<?php
/**
 * Expenses — post petty-cash expenses paid out of the reception cash counter.
 *
 * Anyone holding FINANCIAL_POST_EXPENSES (receptionist / accountant / manager /
 * admin by default) can post. A "shift" is one calendar day (PKT). Two limits
 * gate every posting, both configured in expense_categories.php and enforced
 * here server-side inside a transaction:
 *
 *   * per-category shift limit  — that category's total today, all users
 *   * overall per-shift limit   — this user's total today, all categories
 *
 * Admin postings bypass both (the audit log records that). Vouchers get an
 * EXP-YYYY-NNNN number from an atomic yearly counter; voiding (admin only)
 * keeps row + number forever and just drops the amount out of all totals.
 *
 * Non-admin users see today's postings (their shift); admin sees everything
 * with date/category filters plus per-user daily totals.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';   // require_day_open() — expenses feed the cash tally
require_once __DIR__ . '/config/notify.php';     // notify_expense_posted() — approval email
require_once __DIR__ . '/config/expense_approval.php'; // decide_expense() — shared approve/reject
refresh_session_permissions($pdo);
require_permission('FINANCIAL_POST_EXPENSES');

$isAdmin = ($_SESSION['base_role'] ?? '') === 'ADMIN';
$userId  = (int) $_SESSION['user_id'];
// Approvers (admin + manager) can Approve/Reject a pending row inline from here,
// as well as via the emailed 60-minute magic link.
$canApprove = has_permission('FINANCIAL_APPROVE_EXPENSES');

$error = '';
$success = '';

// The listing filters (from/to/cat) live in the query string. Every row action
// POSTs back to this page, so without carrying them through the redirect the
// admin was thrown back to today/all-categories after each Approve — losing the
// range they were working through. Each action form posts the filter it was
// rendered under; this rebuilds that query string for the PRG redirect.
function expense_filter_qs(array $src): string {
    $qs = [];
    foreach (['from', 'to'] as $k) {
        $v = trim((string) ($src[$k] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $qs[$k] = $v; }
    }
    $cat = (int) ($src['cat'] ?? 0);
    if ($cat > 0) { $qs['cat'] = $cat; }
    return $qs ? '?' . http_build_query($qs) : '';
}

// PRG back to the SAME filtered view, carrying a one-shot flash message. A plain
// re-render would also work, but the redirect stops a refresh from replaying the
// approve/void POST.
function expense_redirect_back(array $src, string $type, string $message): void {
    $qs = expense_filter_qs($src);
    $_SESSION['expense_flash'] = ['type' => $type, 'message' => $message];
    header('Location: expenses.php' . $qs);
    exit;
}

// Yearly expense voucher number, e.g. "EXP-2026-0014". Same race-safe pattern
// as generate_refund_number() (config/billing.php): we do NOT trust
// LAST_INSERT_ID() on an ON DUPLICATE KEY *update* — its return value is
// unreliable across setups and was re-issuing EXP-2026-0001 whenever the
// expense_sequences counter fell behind the actual max in `expenses` (e.g. after
// a restore, manual insert, or re-run migration). Instead take GREATEST of the
// stored counter and the real max already in the table, then persist that.
function generate_expense_number(PDO $pdo): string {
    $year = (int) date('Y');

    $stmt = $pdo->prepare("
        SELECT GREATEST(
            COALESCE((SELECT last_sequence FROM expense_sequences WHERE sequence_year = :y), 0),
            COALESCE((SELECT MAX(CAST(SUBSTRING_INDEX(expense_number, '-', -1) AS UNSIGNED))
                      FROM expenses WHERE expense_number LIKE :pfx), 0)
        ) + 1
    ");
    $stmt->execute([':y' => $year, ':pfx' => 'EXP-' . $year . '-%']);
    $seq = (int) $stmt->fetchColumn();
    if ($seq < 1) {
        $seq = 1;
    }

    // Persist the new high-water mark so the counter tracks reality.
    $pdo->prepare('
        INSERT INTO expense_sequences (sequence_year, last_sequence)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE last_sequence = GREATEST(last_sequence, VALUES(last_sequence))
    ')->execute([$year, $seq]);

    return 'EXP-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

// ---- Post an expense ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_expense') {
    $categoryId  = (int) ($_POST['category_id'] ?? 0);
    $amount      = round((float) ($_POST['amount'] ?? 0), 2);
    $description = trim($_POST['description'] ?? '');
    $paidTo      = trim($_POST['paid_to'] ?? '');
    // Non-counter money (salary/rent paid by bank or owner) never touches the
    // drawer. Admin-only — the form only offers the selector to an admin.
    $source      = $isAdmin ? ($_POST['source'] ?? 'CASH_COUNTER') : 'CASH_COUNTER';
    if (!in_array($source, ['CASH_COUNTER', 'BANK', 'OWNER'], true)) {
        $source = 'CASH_COUNTER';
    }
    // Period-based categories (Salaries, Doctor Shares) are paid in a LATER
    // month than they belong to, so the admin picks the month. Everything else
    // — rent included — is a running-month cost keyed off expense_date.
    $periodMonth = trim($_POST['period_month'] ?? '');   // 'YYYY-MM' from <input type=month>

    // What kind of category is this? Decides the month picker, the drawer
    // lock and the limit checks. Read before validation so the rules can
    // branch on it; the authoritative re-read happens under FOR UPDATE below.
    $catMeta = ['is_period_based' => 0, 'is_admin_only' => 0, 'needs_doctor' => 0, 'needs_vehicle' => 0];
    if ($categoryId > 0) {
        try {
            $cm = $pdo->prepare('SELECT is_period_based, is_admin_only, needs_doctor, needs_vehicle FROM expense_categories WHERE id = ?');
            $cm->execute([$categoryId]);
            $catMeta = $cm->fetch() ?: $catMeta;
        } catch (PDOException $e) {
            try {
                // needs_vehicle absent (add_vehicle_expenses.sql not run) — step down.
                $cm = $pdo->prepare('SELECT is_period_based, is_admin_only, needs_doctor FROM expense_categories WHERE id = ?');
                $cm->execute([$categoryId]);
                $catMeta = $cm->fetch() ?: $catMeta;
            } catch (PDOException $e3) {
                try {
                    $cm = $pdo->prepare('SELECT is_period_based, is_admin_only FROM expense_categories WHERE id = ?');
                    $cm->execute([$categoryId]);
                    $catMeta = $cm->fetch() ?: $catMeta;
                } catch (PDOException $e2) { /* pre-migration: flags absent, treat as plain */ }
            }
        }
    }
    $isPeriodBased = (bool) ($catMeta['is_period_based'] ?? 0);
    $isAdminOnly   = (bool) ($catMeta['is_admin_only'] ?? 0);
    $needsDoctor   = (bool) ($catMeta['needs_doctor'] ?? 0);
    $needsVehicle  = (bool) ($catMeta['needs_vehicle'] ?? 0);

    // Vehicle fields. Forced NULL unless the category is vehicle-tracked, so a
    // stale form field can never attach a vehicle to an unrelated expense.
    $vehicleId     = $needsVehicle ? (int) ($_POST['vehicle_id'] ?? 0) : 0;
    $subcategoryId = $needsVehicle ? (int) ($_POST['subcategory_id'] ?? 0) : 0;
    $meterRaw      = trim($_POST['meter_reading'] ?? '');
    $litresRaw     = trim($_POST['litres'] ?? '');
    $meterReading  = ($needsVehicle && $meterRaw !== '')  ? (int) $meterRaw : null;
    $litres        = ($needsVehicle && $litresRaw !== '') ? round((float) $litresRaw, 2) : null;
    if ($meterReading !== null && $meterReading < 0) { $meterReading = null; }
    if ($litres !== null && $litres <= 0) { $litres = null; }

    // Is the chosen sub-category a fuel one? Read from the DB, never from the
    // name, so "Diesel" or "Petrol" added later behave correctly with no code
    // change. Drives the mandatory-field rules below and the tank_full flag.
    $isFuelSub = false;
    if ($needsVehicle && $subcategoryId > 0) {
        try {
            $fs = $pdo->prepare('SELECT tracks_fuel FROM expense_subcategories WHERE id = ?');
            $fs->execute([$subcategoryId]);
            $isFuelSub = (bool) $fs->fetchColumn();
        } catch (PDOException $e) { $isFuelSub = false; }
    }

    // Full tank? Only meaningful on a fuel row; forced NULL everywhere else so a
    // maintenance posting can never carry a stray flag. '' = unanswered, which
    // the validation below rejects for a fuel row.
    $tankRaw   = $_POST['tank_full'] ?? '';
    $tankFull  = null;
    if ($isFuelSub && ($tankRaw === '1' || $tankRaw === '0')) {
        $tankFull = (int) $tankRaw;
    }

    // WHO physically took the vehicle to the pump. Only meaningful on a fuel row,
    // so forced NULL everywhere else — a maintenance posting must never carry a
    // name that the per-person efficiency report would then hold against them.
    //
    // NOT posted_by_id: reception posts nearly every expense, so the poster is
    // near-constant and comparing people by it would compare nothing.
    //
    // Deliberately OPTIONAL. A fill whose driver nobody remembers must still be
    // postable — refusing it would push people to guess a name, and a guessed
    // name in a report that accuses someone is worse than an honest blank.
    $refuelledById = ($isFuelSub && ($_POST['refuelled_by_id'] ?? '') !== '')
                         ? (int) $_POST['refuelled_by_id'] : 0;

    // Who the disbursement was paid to. Only meaningful for needs_doctor
    // categories; forced NULL otherwise so an ordinary expense can never carry
    // a stray doctor id from a stale form field.
    $paidToDoctorId = $needsDoctor ? (int) ($_POST['paid_to_doctor_id'] ?? 0) : 0;

    // Only CASH_COUNTER money is subject to the drawer's day-lock. A bank
    // salary posted for a month whose days are all closed must still go in.
    $dayLock = $source === 'CASH_COUNTER' ? require_day_open($pdo) : null;

    // A closed month takes no more postings, whatever the source. Checked
    // against the ACCOUNTING period — a salary posted today but belonging to a
    // closed month would otherwise silently alter a P&L already signed off.
    // Non-period expenses land on today, so this only ever bites a back-dated one.
    $monthLock = function_exists('require_month_open')
        ? require_month_open($pdo, $isPeriodBased && $periodMonth !== '' ? $periodMonth . '-01' : date('Y-m-d'))
        : null;

    // IMPERSONATION MONEY BRAKE — CASH_COUNTER only.
    //
    // A counter expense is petty cash leaving the drawer, and it reduces the
    // target's expected_cash exactly as a refund does, so while impersonating
    // it is a way to empty someone else's drawer under their name. The brake
    // covered refund/void/close-day/take-payment and missed this one. BANK and
    // OWNER sources touch no drawer, so they are deliberately not gated —
    // making an admin re-affirm a bank salary posting would be noise that
    // teaches people to tick past the warning.
    $impBlock = $source === 'CASH_COUNTER' ? imp_block_money_action('Posting this counter expense') : '';

    if ($impBlock !== '') {
        $error = $impBlock;
    } elseif ($isAdminOnly && !$isAdmin) {
        $error = 'Only an admin may post under that category.';
    } elseif ($isPeriodBased && !preg_match('/^\d{4}-\d{2}$/', $periodMonth)) {
        $error = 'Pick the month this payment is for.';
    } elseif ($needsDoctor && $paidToDoctorId <= 0) {
        $error = 'Pick the doctor this payment is for.';
    } elseif ($needsVehicle && $vehicleId <= 0) {
        $error = 'Pick the vehicle this spend is for.';
    } elseif ($needsVehicle && $subcategoryId <= 0) {
        $error = 'Choose Fuel, Maintenance or Repairs.';
    // A FUEL row without a meter reading, litres, or a known tank state cannot
    // produce an efficiency figure, and a fuel posting whose whole purpose is
    // that figure is worth blocking for. Maintenance and Repairs are deliberately
    // NOT subject to any of these three — they often have no meaningful reading
    // and never have litres.
    } elseif ($isFuelSub && $meterReading === null) {
        $error = 'A fuel posting needs the meter reading.';
    } elseif ($isFuelSub && ($litres === null || $litres <= 0)) {
        $error = 'A fuel posting needs the litres filled.';
    } elseif ($isFuelSub && $tankFull === null) {
        $error = 'Say whether the tank was filled to full.';
    } elseif ($monthLock) {
        $error = $monthLock;
    } elseif ($dayLock) {
        $error = $dayLock;
    } elseif ($categoryId <= 0) {
        $error = 'Pick a category.';
    } elseif ($amount <= 0) {
        $error = 'The amount must be more than zero.';
    } elseif ($description === '') {
        $error = 'Describe what the cash was spent on.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the category row so two simultaneous postings can't both
            // squeeze under the same remaining limit.
            $catStmt = $pdo->prepare('SELECT * FROM expense_categories WHERE id = ? AND is_active = 1 FOR UPDATE');
            $catStmt->execute([$categoryId]);
            $category = $catStmt->fetch();
            if (!$category) {
                throw new RuntimeException('That category is not available.');
            }

            // Over-limit postings are no longer blocked: the cash may genuinely
            // have to go out (e.g. a large staff advance from a small counter
            // limit). Instead we FLAG the posting over-limit and let the existing
            // approve/reject flow gate it — every non-admin posting already goes
            // PENDING. Admins bypass limits entirely (nothing to flag).
            $overLimit = false;
            $limitBreaches = [];
            // Shift limits police the counter float. Bank/owner money is not in
            // that float, so a Rs 400,000 payroll must not be measured against a
            // Rs 5,000 counter cap (it would flag over-limit on every payroll run).
            if (!$isAdmin && $source === 'CASH_COUNTER') {
                // Per-category cap: this category's spend today, all users.
                $catLimit = (float) $category['shift_limit'];
                if ($catLimit > 0) {
                    $spent = $pdo->prepare("
                        SELECT COALESCE(SUM(amount), 0) FROM expenses
                        WHERE category_id = ? AND expense_date = CURDATE()
                          AND voided_at IS NULL AND approval_status <> 'REJECTED'
                    ");
                    $spent->execute([$categoryId]);
                    $catSpent = (float) $spent->fetchColumn();
                    if ($catSpent + $amount > $catLimit) {
                        $overLimit = true;
                        $limitBreaches[] = sprintf('"%s" limit Rs %s (Rs %s already spent, over by Rs %s)',
                            $category['name'], number_format($catLimit), number_format($catSpent),
                            number_format($catSpent + $amount - $catLimit));
                    }
                }

                // Overall cap: this user's spend today, all categories.
                $totStmt = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'expense_shift_limit_total'");
                $totStmt->execute();
                $shiftLimitTotal = (float) ($totStmt->fetchColumn() ?: 0);
                if ($shiftLimitTotal > 0) {
                    $mine = $pdo->prepare("
                        SELECT COALESCE(SUM(amount), 0) FROM expenses
                        WHERE posted_by_id = ? AND expense_date = CURDATE()
                          AND voided_at IS NULL AND approval_status <> 'REJECTED'
                    ");
                    $mine->execute([$userId]);
                    $mySpent = (float) $mine->fetchColumn();
                    if ($mySpent + $amount > $shiftLimitTotal) {
                        $overLimit = true;
                        $limitBreaches[] = sprintf('your Rs %s shift limit (Rs %s already posted, over by Rs %s)',
                            number_format($shiftLimitTotal), number_format($mySpent),
                            number_format($mySpent + $amount - $shiftLimitTotal));
                    }
                }
            }
            $limitNote = $overLimit ? ('Exceeds ' . implode(' and ', $limitBreaches)) : null;

            // Admins own the limits AND are approvers — their own postings are
            // auto-approved (nobody to email). Everyone else starts PENDING and
            // gets a 60-minute magic link out to the admins + managers.
            $status = $isAdmin ? 'APPROVED' : 'PENDING';
            $expenseNumber = generate_expense_number($pdo);
            // over_limit/limit_note fall back gracefully if the migration hasn't
            // run yet (mid-deploy): retry without those columns.
            // 'YYYY-MM' -> first of that month; NULL for running-month costs.
            $periodValue = $isPeriodBased && $periodMonth !== '' ? $periodMonth . '-01' : null;
            $doctorValue = $paidToDoctorId > 0 ? $paidToDoctorId : null;
            $vehicleValue = $vehicleId > 0 ? $vehicleId : null;
            $subcatValue  = $subcategoryId > 0 ? $subcategoryId : null;
            try {
                // Newest shape: vehicle columns + tank_full present.
                $pdo->prepare('
                    INSERT INTO expenses
                        (expense_number, category_id, subcategory_id, vehicle_id, meter_reading, litres, tank_full,
                         amount, description, paid_to, paid_to_doctor_id,
                         expense_date, period_month, source,
                         posted_by_id, approval_status, over_limit, limit_note, approved_by_id, approved_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ' . ($isAdmin ? 'NOW()' : 'NULL') . ')
                ')->execute([
                    $expenseNumber, $categoryId, $subcatValue, $vehicleValue, $meterReading, $litres, $tankFull,
                    $amount, $description,
                    $paidTo !== '' ? $paidTo : null, $doctorValue,
                    $periodValue, $source,
                    $userId, $status,
                    $overLimit ? 1 : 0, $limitNote,
                    $isAdmin ? $userId : null,
                ]);
            } catch (PDOException $eTank) {
            try {
                // tank_full absent (add_expense_tank_full.sql not run) — step down.
                $pdo->prepare('
                    INSERT INTO expenses
                        (expense_number, category_id, subcategory_id, vehicle_id, meter_reading, litres,
                         amount, description, paid_to, paid_to_doctor_id,
                         expense_date, period_month, source,
                         posted_by_id, approval_status, over_limit, limit_note, approved_by_id, approved_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ' . ($isAdmin ? 'NOW()' : 'NULL') . ')
                ')->execute([
                    $expenseNumber, $categoryId, $subcatValue, $vehicleValue, $meterReading, $litres,
                    $amount, $description,
                    $paidTo !== '' ? $paidTo : null, $doctorValue,
                    $periodValue, $source,
                    $userId, $status,
                    $overLimit ? 1 : 0, $limitNote,
                    $isAdmin ? $userId : null,
                ]);
            } catch (PDOException $eVeh) {
            try {
                $pdo->prepare('
                    INSERT INTO expenses
                        (expense_number, category_id, amount, description, paid_to, paid_to_doctor_id,
                         expense_date, period_month, source,
                         posted_by_id, approval_status, over_limit, limit_note, approved_by_id, approved_at)
                    VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ' . ($isAdmin ? 'NOW()' : 'NULL') . ')
                ')->execute([
                    $expenseNumber, $categoryId, $amount, $description,
                    $paidTo !== '' ? $paidTo : null, $doctorValue,
                    $periodValue, $source,
                    $userId, $status,
                    $overLimit ? 1 : 0, $limitNote,
                    $isAdmin ? $userId : null,
                ]);
            } catch (PDOException $e) {
                // paid_to_doctor_id absent (migration not yet run) — retry without it.
                try {
                    $pdo->prepare('
                        INSERT INTO expenses
                            (expense_number, category_id, amount, description, paid_to, expense_date,
                             period_month, source,
                             posted_by_id, approval_status, over_limit, limit_note, approved_by_id, approved_at)
                        VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ' . ($isAdmin ? 'NOW()' : 'NULL') . ')
                    ')->execute([
                        $expenseNumber, $categoryId, $amount, $description,
                        $paidTo !== '' ? $paidTo : null,
                        $periodValue, $source,
                        $userId, $status,
                        $overLimit ? 1 : 0, $limitNote,
                        $isAdmin ? $userId : null,
                    ]);
                } catch (PDOException $e2) {
                    // Oldest shape: no period/source/over_limit columns either.
                    $pdo->prepare('
                        INSERT INTO expenses
                            (expense_number, category_id, amount, description, paid_to, expense_date,
                             posted_by_id, approval_status, approved_by_id, approved_at)
                        VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ' . ($isAdmin ? 'NOW()' : 'NULL') . ')
                    ')->execute([
                        $expenseNumber, $categoryId, $amount, $description,
                        $paidTo !== '' ? $paidTo : null, $userId, $status,
                        $isAdmin ? $userId : null,
                    ]);
                }
            }
            }   // end vehicle-columns fallback
            }   // end tank_full fallback
            $expenseId = (int) $pdo->lastInsertId();

            // Who refuelled — written as a follow-up UPDATE rather than a sixth
            // nested INSERT fallback. The chain above is already four shapes deep
            // and adding a fifth would double every branch for one nullable
            // annotation column.
            //
            // Inside the same transaction, so the name can never be attached to a
            // posting that then rolls back. A failure here means the migration has
            // not run: the expense is still correct and complete without the name,
            // so it must NOT sink the posting — the money matters, the annotation
            // does not.
            if ($refuelledById > 0 && $expenseId > 0) {
                try {
                    $pdo->prepare('UPDATE expenses SET refuelled_by_id = ? WHERE id = ?')
                        ->execute([$refuelledById, $expenseId]);
                } catch (PDOException $eRef) {
                    // refuelled_by_id absent (add_fuel_accountability.sql not run).
                }
            }

            // Mint the single-use magic-link token in the SAME transaction, so a
            // committed PENDING expense always has a matching link. Store only the
            // hash; the raw token travels in the email.
            $rawToken = '';
            if (!$isAdmin) {
                $rawToken = bin2hex(random_bytes(32));
                $pdo->prepare('
                    INSERT INTO expense_approval_tokens (expense_id, token_hash, expires_at)
                    VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))
                ')->execute([$expenseId, hash('sha256', $rawToken)]);
            }

            $auditNote = sprintf('Posted expense %s: Rs %s under "%s" — %s',
                $expenseNumber, number_format($amount, 2), $category['name'], $description);
            if ($isAdmin) { $auditNote .= ' (admin: limits bypassed, auto-approved)'; }
            elseif ($overLimit) { $auditNote .= ' [OVER LIMIT — ' . $limitNote . ']'; }
            audit_log($pdo, 'expense_posted', $auditNote, $userId);

            $pdo->commit();

            // Fire the approval email AFTER commit — best-effort, never blocks.
            if (!$isAdmin && $rawToken !== '') {
                notify_expense_posted($pdo, $expenseId, $rawToken);
            }

            // PRG so a refresh can't double-post the same expense.
            header('Location: expenses.php?posted=' . urlencode($expenseNumber) . ($overLimit ? '&over=1' : ''));
            exit;
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = 'Could not post the expense. Please try again.';
        }
    }
}

// ---- Void an expense (admin only; row + voucher number kept for audit) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_expense') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only admin can void an expense.');
    }
    $id = (int) ($_POST['expense_id'] ?? 0);
    $reason = trim($_POST['void_reason'] ?? '');

    // A void changes the POSTER's expected-cash for the expense's date — if
    // that receptionist has already closed that day (signed tally), refuse.
    $expRow = null;
    if ($id > 0) {
        $dStmt = $pdo->prepare('SELECT expense_date, posted_by_id FROM expenses WHERE id = ?');
        $dStmt->execute([$id]);
        $expRow = $dStmt->fetch() ?: null;
    }
    $dayLock = $expRow ? require_day_open($pdo, $expRow['expense_date'], (int) $expRow['posted_by_id']) : null;

    if ($dayLock) {
        $error = $dayLock . ' Voiding this expense would change that day\'s signed tally.';
    } elseif ($id > 0 && $reason !== '') {
        $upd = $pdo->prepare('
            UPDATE expenses SET voided_at = NOW(), voided_by_id = ?, void_reason = ?
            WHERE id = ? AND voided_at IS NULL
        ');
        $upd->execute([$userId, $reason, $id]);
        if ($upd->rowCount() === 1) {
            audit_log($pdo, 'expense_voided', "Voided expense #$id — $reason", $userId);
            $success = 'Expense voided. The voucher number is retained for the record.';
        }
    } else {
        $error = 'A void needs a reason.';
    }
    expense_redirect_back($_POST, $error !== '' ? 'error' : 'success', $error !== '' ? $error : $success);
}

// ---- Correct the meter reading / litres on an already-posted expense ----
//
// Admin only. Exists because a mistyped odometer was previously permanent: the
// report counted it as an unusable reading and there was no way to fix it, so
// cost-per-km stayed wrong forever on a shrinking denominator.
//
// DELIBERATELY NOT DAY-LOCKED. A void is refused once the poster has signed off
// that day's tally because it moves cash. A meter reading moves NO money — it
// touches neither amount nor source nor expected_cash — and the readings most in
// need of correction are precisely the ones on days already closed. Blocking
// those would make the feature useless for its actual purpose. The money fields
// remain untouchable here; only meter_reading and litres can change.
//
// Every correction is audit-logged with the old AND new value, so a reading that
// was edited to flatter a cost-per-km figure is visible after the fact.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_meter') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only admin can correct a meter reading.');
    }
    $id = (int) ($_POST['expense_id'] ?? 0);

    // Blank clears the reading — that is a legitimate correction ("this row never
    // had a trustworthy reading"), distinct from typing 0.
    $mRaw = trim($_POST['meter_reading'] ?? '');
    $lRaw = trim($_POST['litres'] ?? '');
    $tRaw = trim($_POST['tank_full'] ?? '');
    $newMeter  = $mRaw === '' ? null : (int) $mRaw;
    $newLitres = $lRaw === '' ? null : round((float) $lRaw, 2);
    if ($newMeter !== null && $newMeter < 0)  { $newMeter = null; }
    if ($newLitres !== null && $newLitres <= 0) { $newLitres = null; }
    // A mis-set full/part flag would otherwise be as permanent as a mistyped
    // reading was, and it silently changes km/L. '' leaves it unchanged.
    $newTank = ($tRaw === '1' || $tRaw === '0') ? (int) $tRaw : null;

    // Who refuelled, correctable on an already-posted row. Three distinct states,
    // which is why this is not a plain (int) cast:
    //   ''      absent from the form   -> leave whatever is stored
    //   '0'     "Not recorded" chosen  -> CLEAR the name
    //   '12'    a user id              -> set it
    // Clearing has to be reachable: the per-person report is used to question
    // someone's fuel use, so attributing a fill to the wrong person must be
    // undoable, not merely overwritable.
    $rRaw     = trim($_POST['refuelled_by_id'] ?? '');
    $setRefuel = $rRaw !== '';
    $newRefuel = ($rRaw === '' || $rRaw === '0') ? null : (int) $rRaw;
    if ($newRefuel !== null && $newRefuel <= 0) { $newRefuel = null; }

    try {
        // refuelled_by_id may not exist yet, so it is read in a second query
        // rather than widening this one — a failure there must not stop a meter
        // correction, which is the handler's primary job.
        $cur = $pdo->prepare('SELECT e.expense_number, e.vehicle_id, e.meter_reading, e.litres,
                                     v.name AS vehicle_name, v.registration
                                FROM expenses e
                                LEFT JOIN vehicles v ON v.id = e.vehicle_id
                               WHERE e.id = ?');
        $cur->execute([$id]);
        $row = $cur->fetch() ?: null;

        $oldRefuelId = null; $oldRefuelName = null; $refuelColumn = false;
        if ($row) {
            try {
                $rq = $pdo->prepare('SELECT e.refuelled_by_id, u.name
                                       FROM expenses e
                                       LEFT JOIN users u ON u.id = e.refuelled_by_id
                                      WHERE e.id = ?');
                $rq->execute([$id]);
                $rr = $rq->fetch() ?: null;
                $refuelColumn  = true;
                $oldRefuelId   = ($rr && $rr['refuelled_by_id'] !== null) ? (int) $rr['refuelled_by_id'] : null;
                $oldRefuelName = $rr['name'] ?? null;
            } catch (PDOException $e) { $refuelColumn = false; }
        }

        if (!$row) {
            $error = 'That expense could not be found.';
        } elseif ((int) ($row['vehicle_id'] ?? 0) <= 0) {
            // A reading is meaningless without a vehicle to attribute it to.
            $error = 'That expense is not attached to a vehicle, so it has no meter reading.';
        } else {
            $oldMeter  = $row['meter_reading'] !== null ? (int) $row['meter_reading'] : null;
            $oldLitres = $row['litres'] !== null ? (float) $row['litres'] : null;

            // tank_full is only written when the form supplied a value AND the
            // column exists; otherwise it keeps whatever it had.
            $tankChanged = false;
            if ($newTank !== null) {
                try {
                    $pdo->prepare('UPDATE expenses SET tank_full = ? WHERE id = ?')->execute([$newTank, $id]);
                    $tankChanged = true;
                } catch (PDOException $e) { /* pre-migration: silently skip */ }
            }

            // Who refuelled. Same rule as tank_full: only written when the form
            // supplied a value and the column exists.
            $refuelChanged = false;
            if ($setRefuel && $refuelColumn && $newRefuel !== $oldRefuelId) {
                try {
                    $pdo->prepare('UPDATE expenses SET refuelled_by_id = ? WHERE id = ?')
                        ->execute([$newRefuel, $id]);
                    $refuelChanged = true;
                } catch (PDOException $e) { /* FK rejected an unknown user id */ }
            }

            if ($oldMeter === $newMeter && $oldLitres === $newLitres && !$tankChanged && !$refuelChanged) {
                $success = 'No change — the reading is already ' . ($newMeter === null ? 'blank' : number_format($newMeter) . ' km') . '.';
            } elseif ($oldMeter === $newMeter && $oldLitres === $newLitres && $refuelChanged && !$tankChanged) {
                // The refueller changed but the reading did not. Logged on its own
                // because attributing a fill to a person is the input to a report
                // that questions their fuel use — a change of name must be as
                // traceable as a change of odometer.
                $nameOf = function (?int $uid) use ($pdo): string {
                    if ($uid === null) { return 'nobody'; }
                    try {
                        $s = $pdo->prepare('SELECT name FROM users WHERE id = ?');
                        $s->execute([$uid]);
                        return (string) ($s->fetchColumn() ?: ('user #' . $uid));
                    } catch (PDOException $e) { return 'user #' . $uid; }
                };
                audit_log($pdo, 'expense_refueller_changed',
                    sprintf('Refuelled-by on %s: %s -> %s',
                        $row['expense_number'],
                        $oldRefuelName ?: 'nobody', $nameOf($newRefuel)),
                    $userId);
                $success = 'Refuelled-by updated for ' . $row['expense_number'] . '.';
            } else {
                $pdo->prepare('UPDATE expenses SET meter_reading = ?, litres = ? WHERE id = ?')
                    ->execute([$newMeter, $newLitres, $id]);

                $fmt = function ($m, $l) {
                    return ($m === null ? 'blank' : number_format((float) $m) . ' km')
                         . ($l === null ? '' : ' / ' . number_format((float) $l, 2) . ' L');
                };
                audit_log($pdo, 'expense_meter_corrected',
                    sprintf('Corrected meter on %s (%s): %s -> %s%s',
                        $row['expense_number'],
                        trim(($row['vehicle_name'] ?? '') . ' ' . ($row['registration'] ?? '')),
                        $fmt($oldMeter, $oldLitres), $fmt($newMeter, $newLitres),
                        $refuelChanged ? ' · refuelled-by also changed' : ''),
                    $userId);

                $success = 'Meter reading updated for ' . $row['expense_number']
                         . '. Cost-per-km figures will recalculate.';
            }
        }
    } catch (PDOException $e) {
        // meter_reading/litres absent → the vehicle migration has not run.
        $error = 'Meter readings are not available until the vehicle migration has run.';
    }
    expense_redirect_back($_POST, $error !== '' ? 'error' : 'success', $error !== '' ? $error : $success);
}

// ---- Approve / reject pending expenses (in-app; approvers only) ----
//
// Handles BOTH the single-row buttons and the bulk tick-box form — a bulk run is
// just a loop over the same decide_expense(), one row-locked transaction each, so
// a row someone else decided in the meantime is skipped rather than sinking the
// whole batch. Deliberately not one big transaction: approving 12 of 13 is a
// better outcome than rolling all 13 back because one was already handled.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['approve_expense', 'reject_expense'], true)) {
    if (!$canApprove) {
        http_response_code(403);
        exit('You do not have permission to approve expenses.');
    }
    $approve = ($_POST['action'] === 'approve_expense');
    $reason  = trim($_POST['reject_reason'] ?? '');

    // Single row posts expense_id; the bulk form posts expense_ids[].
    $ids = [];
    if (isset($_POST['expense_ids']) && is_array($_POST['expense_ids'])) {
        foreach ($_POST['expense_ids'] as $raw) {
            $n = (int) $raw;
            if ($n > 0) { $ids[$n] = $n; }
        }
        $ids = array_values($ids);
    } elseif ((int) ($_POST['expense_id'] ?? 0) > 0) {
        $ids = [(int) $_POST['expense_id']];
    }

    if (!$ids) {
        $error = 'Tick at least one expense first.';
    } elseif (count($ids) === 1) {
        $result = decide_expense($pdo, $ids[0], $approve, $userId, $reason);
        if ($result['ok']) {
            notify_expense_decided($pdo, $ids[0]);   // best-effort, after commit
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } else {
        $done = 0;
        $skipped = [];
        foreach ($ids as $id) {
            $result = decide_expense($pdo, $id, $approve, $userId, $reason);
            if ($result['ok']) {
                $done++;
                notify_expense_decided($pdo, $id);   // best-effort, after commit
            } else {
                $skipped[] = $result['message'];
            }
        }
        $verb = $approve ? 'approved' : 'rejected';
        if ($done > 0) {
            $success = $done . ' ' . ($done === 1 ? 'expense' : 'expenses') . ' ' . $verb . '.';
            if ($skipped) {
                $success .= ' ' . count($skipped) . ' skipped — ' . implode(' ', $skipped);
            }
        } else {
            $error = 'Nothing was ' . $verb . '. ' . implode(' ', $skipped);
        }
    }
    expense_redirect_back($_POST, $error !== '' ? 'error' : 'success', $error !== '' ? $error : $success);
}

// One-shot flash from a row action's PRG redirect (approve / reject / void).
if (!empty($_SESSION['expense_flash'])) {
    $flash = $_SESSION['expense_flash'];
    unset($_SESSION['expense_flash']);
    // $success is echoed unescaped (the posted= message below carries markup), so
    // escape here — a void reason is free text typed by the admin.
    if (($flash['type'] ?? '') === 'error') { $error = (string) $flash['message']; }
    else { $success = htmlspecialchars((string) $flash['message']); }
}

if (isset($_GET['posted'])) {
    $overPosted = isset($_GET['over']);
    $success = 'Expense ' . htmlspecialchars($_GET['posted']) . ' posted — take the cash from the counter and keep the receipt.'
        . ($isAdmin ? ''
            : ($overPosted
                ? ' <b>This exceeds your shift limit</b>, so it needs admin/manager approval now — please contact them for immediate sign-off. Admins &amp; managers have also been emailed.'
                : ' It is now awaiting a manager\'s approval; you will see the status update here.'));
}

// ---- Data for the page ----
// is_period_based / is_admin_only arrive with add_accounts_phase1.sql. Fall back
// to the old column list if that migration has not run yet, so a mid-deploy page
// still renders (the flags then read as 0 and every category behaves as before).
// needs_doctor arrives later still (add_expense_paid_to_doctor.sql), so the
// fallbacks step down one migration at a time rather than all the way to the
// original column list.
try {
    $categories = $pdo->query(
        'SELECT id, name, shift_limit, is_period_based, is_admin_only, needs_doctor, needs_vehicle
           FROM expense_categories WHERE is_active = 1' . ($isAdmin ? '' : ' AND is_admin_only = 0') . '
          ORDER BY name'
    )->fetchAll();
} catch (PDOException $eNv) {
try {
    $categories = $pdo->query(
        'SELECT id, name, shift_limit, is_period_based, is_admin_only, needs_doctor
           FROM expense_categories WHERE is_active = 1' . ($isAdmin ? '' : ' AND is_admin_only = 0') . '
          ORDER BY name'
    )->fetchAll();
} catch (PDOException $e) {
    try {
        $categories = $pdo->query(
            'SELECT id, name, shift_limit, is_period_based, is_admin_only
               FROM expense_categories WHERE is_active = 1' . ($isAdmin ? '' : ' AND is_admin_only = 0') . '
              ORDER BY name'
        )->fetchAll();
    } catch (PDOException $e2) {
        $categories = $pdo->query('SELECT id, name, shift_limit FROM expense_categories WHERE is_active = 1 ORDER BY name')->fetchAll();
    }
}
}   // end needs_vehicle fallback

// Vehicles + sub-categories for the vehicle-tracked categories. Both are absent
// before add_vehicle_expenses.sql runs, so the form simply omits those fields.
$vehiclesList = [];
$subcatsByCat = [];
try {
    $vehiclesList = $pdo->query(
        'SELECT id, name, registration, vehicle_type FROM vehicles WHERE is_active = 1 ORDER BY name'
    )->fetchAll();
    foreach ($pdo->query(
        'SELECT id, category_id, name, tracks_fuel FROM expense_subcategories
          WHERE is_active = 1 ORDER BY sort_order, name'
    )->fetchAll() as $sc) {
        $subcatsByCat[(int) $sc['category_id']][] = $sc;
    }
} catch (PDOException $e) { /* pre-migration: no vehicle fields offered */ }

// Staff for the "Refuelled by" picker, and whether the column exists to store
// the answer in. Probed separately from $vehiclesList: add_fuel_accountability.sql
// is a later migration, so a database can have vehicles but not this column, and
// offering a field whose value would be silently dropped is worse than no field.
$refuelStaff  = [];
$refuelReady  = false;
if ($vehiclesList) {
    try {
        $pdo->query('SELECT refuelled_by_id FROM expenses LIMIT 1');
        $refuelReady = true;
        // Every active user, not a role subset: whoever takes the van to the pump
        // varies, and a name missing from the list would push the poster to leave
        // it blank — which loses the accountability the field exists for.
        $refuelStaff = $pdo->query(
            'SELECT id, name, base_role FROM users WHERE is_active = 1 ORDER BY name'
        )->fetchAll();
    } catch (PDOException $e) { $refuelReady = false; $refuelStaff = []; }
}

// Doctors for the disbursement picker. Admin-only screen concern, but the list
// is cheap and the <select> is hidden for everyone else anyway.
$doctorsList = [];
try {
    $doctorsList = $pdo->query(
        "SELECT id, name FROM users WHERE base_role = 'DOCTOR' AND is_active = 1 ORDER BY name"
    )->fetchAll();
} catch (PDOException $e) { /* is_active may predate add_user_active_status.sql */ }

// Per-category spend so far today (all users) — feeds the client-side over-limit
// warning so the receptionist is told before they submit, not just after.
$catSpentToday = [];
foreach ($pdo->query("
    SELECT category_id, COALESCE(SUM(amount),0) AS t FROM expenses
    WHERE expense_date = CURDATE() AND voided_at IS NULL AND approval_status <> 'REJECTED'
    GROUP BY category_id
")->fetchAll() as $cs) {
    $catSpentToday[(int) $cs['category_id']] = (float) $cs['t'];
}

// Limits snapshot for the sidebar meter (non-admin).
$totStmt = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'expense_shift_limit_total'");
$totStmt->execute();
$shiftLimitTotal = (float) ($totStmt->fetchColumn() ?: 0);

$mineStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM expenses
    WHERE posted_by_id = ? AND expense_date = CURDATE()
      AND voided_at IS NULL AND approval_status <> 'REJECTED'
");
$mineStmt->execute([$userId]);
$mySpentToday = (float) $mineStmt->fetchColumn();

// Listing. Non-admin: today only. Admin: date-range + category filters.
$filterFrom = $isAdmin ? ($_GET['from'] ?? date('Y-m-d')) : date('Y-m-d');
$filterTo   = $isAdmin ? ($_GET['to']   ?? date('Y-m-d')) : date('Y-m-d');
$filterCat  = $isAdmin ? (int) ($_GET['cat'] ?? 0) : 0;
// Guard against malformed dates from the query string.
$reDate = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($reDate, $filterFrom)) { $filterFrom = date('Y-m-d'); }
if (!preg_match($reDate, $filterTo))   { $filterTo = date('Y-m-d'); }

$where  = ['e.expense_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];
if ($filterCat > 0) { $where[] = 'e.category_id = ?'; $params[] = $filterCat; }

// The refueller join is attempted first and dropped if the column is absent, so
// the listing keeps working on a database where add_fuel_accountability.sql has
// not been run. Only the join differs — every other column is identical, so the
// two shapes cannot drift.
$listSql = '
    SELECT e.*, ec.name AS category_name, u.name AS posted_by_name,
           v.name AS voided_by_name, a.name AS approved_by_name%s
    FROM expenses e
    JOIN expense_categories ec ON ec.id = e.category_id
    JOIN users u ON u.id = e.posted_by_id
    LEFT JOIN users v ON v.id = e.voided_by_id
    LEFT JOIN users a ON a.id = e.approved_by_id
    %s
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY e.created_at DESC, e.id DESC
    LIMIT 300
';
try {
    $listStmt = $pdo->prepare(sprintf($listSql,
        ', rf.name AS refuelled_by_name', 'LEFT JOIN users rf ON rf.id = e.refuelled_by_id'));
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();
} catch (PDOException $e) {
    $listStmt = $pdo->prepare(sprintf($listSql, '', ''));
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();
}

// Hidden inputs every row/bulk action form carries, so the PRG redirect can put
// the admin back on the exact range + category they were working through.
$filterFields = '<input type="hidden" name="from" value="' . htmlspecialchars($filterFrom) . '">'
              . '<input type="hidden" name="to" value="' . htmlspecialchars($filterTo) . '">'
              . '<input type="hidden" name="cat" value="' . (int) $filterCat . '">';

// Vehicle cost panel under pending vehicle rows. Only load the partial if the
// migration has actually run — before that, expenses.vehicle_id does not exist
// and the panel would query columns that are not there. $rows comes from
// SELECT e.*, so the key is simply absent pre-migration.
$vehiclePanelReady = false;
if ($canApprove && is_file(__DIR__ . '/partials/vehicle_cost_panel.php')) {
    foreach ($rows as $probe) {
        if (array_key_exists('vehicle_id', $probe)) {
            require_once __DIR__ . '/partials/vehicle_cost_panel.php';
            $vehiclePanelReady = true;
        }
        break;   // one row is enough to know the column shape
    }
}

// Rows the bulk bar can act on: pending, not voided, inside the current filter.
// Counted from $rows so the tick-all box can never select something off-screen.
// Excludes the viewer's OWN postings: decide_expense() refuses those, so
// counting them would make "Select all N" overstate what can actually be done.
$pendingIds = [];
foreach ($rows as $r) {
    if ($r['voided_at'] === null
        && ($r['approval_status'] ?? 'PENDING') === 'PENDING'
        && (int) $r['posted_by_id'] !== $userId) {
        $pendingIds[] = (int) $r['id'];
    }
}

// ---- Pending-approvals section ----
//
// Promoted above the listing so an approver can clear the queue on a phone
// without scrolling past a form they do not need. Deliberately NOT limited to
// the date filter: an expense posted three days ago and still unapproved must
// not become invisible because the filter defaults to today. It is a work
// queue, not a report.
//
// Shown to EVERY role that can reach this page — a receptionist sees the same
// list read-only, so they know their own posting is waiting rather than lost.
$pendingRows = [];
try {
    $pStmt = $pdo->prepare("
        SELECT e.*, ec.name AS category_name, u.name AS posted_by_name
          FROM expenses e
          JOIN expense_categories ec ON ec.id = e.category_id
          JOIN users u ON u.id = e.posted_by_id
         WHERE e.approval_status = 'PENDING'
           AND e.voided_at IS NULL
         ORDER BY e.over_limit DESC, e.created_at ASC, e.id ASC
         LIMIT 50
    ");
    $pStmt->execute();
    $pendingRows = $pStmt->fetchAll();
} catch (PDOException $e) {
    // over_limit may predate its migration — retry without ordering on it.
    try {
        $pStmt = $pdo->query("
            SELECT e.*, ec.name AS category_name, u.name AS posted_by_name
              FROM expenses e
              JOIN expense_categories ec ON ec.id = e.category_id
              JOIN users u ON u.id = e.posted_by_id
             WHERE e.approval_status = 'PENDING' AND e.voided_at IS NULL
             ORDER BY e.created_at ASC, e.id ASC LIMIT 50
        ");
        $pendingRows = $pStmt->fetchAll();
    } catch (PDOException $e2) { $pendingRows = []; }
}

// An approver may not decide their OWN posting (enforced in decide_expense();
// mirrored here so the buttons are never rendered rather than rendered-and-
// refused). $canApprove is the permission; this is the per-row test.
$canDecideRow = function (array $r) use ($canApprove, $userId): bool {
    return $canApprove && (int) $r['posted_by_id'] !== $userId;
};

// How many cards are visible before the "View N more" link.
$pendingVisible = 3;

// Column count for the full-width vehicle-cost sub-row, kept in step with the
// header. Defined AFTER $pendingIds, since the tick-box column only exists when
// there is something pending to tick.
$rowCols = ($isAdmin ? 8 : 7)
         + (($isAdmin || $canApprove) ? 1 : 0)
         + (($canApprove && $pendingIds) ? 1 : 0);

// A rejected expense returned its cash to the drawer, so — like a voided one —
// it drops out of every total. Pending still counts (the cash is already out).
$rangeTotal = 0.0;
foreach ($rows as $r) {
    if ($r['voided_at'] === null && $r['approval_status'] !== 'REJECTED') {
        $rangeTotal += (float) $r['amount'];
    }
}

// Admin extra: per-user totals over the filtered range (limit oversight at a glance).
$userTotals = [];
if ($isAdmin) {
    $utStmt = $pdo->prepare('
        SELECT u.name, COALESCE(SUM(e.amount), 0) AS total, COUNT(*) AS cnt
        FROM expenses e JOIN users u ON u.id = e.posted_by_id
        WHERE e.expense_date BETWEEN ? AND ? AND e.voided_at IS NULL
          AND e.approval_status <> "REJECTED"
        GROUP BY e.posted_by_id, u.name ORDER BY total DESC
    ');
    $utStmt->execute([$filterFrom, $filterTo]);
    $userTotals = $utStmt->fetchAll();
}

$pageTitle = 'Expenses';
$headExtra = <<<CSS
<style>
.exp-grid { display: grid; grid-template-columns: 380px 1fr; gap: 20px; align-items: start; }
@media (max-width: 1100px) { .exp-grid { grid-template-columns: 1fr; } }

.f-group { margin-bottom: 14px; }
.f-group label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.f-group input, .f-group select, .f-group textarea {
    width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px;
    font: inherit; font-size: 13.5px; background: var(--bg);
}
.f-group input:focus, .f-group select:focus, .f-group textarea:focus {
    outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff;
}
.f-group textarea { resize: vertical; min-height: 64px; }

.limit-meter { border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; background: var(--bg); }
.limit-meter .lm-row { display: flex; justify-content: space-between; font-size: 12.5px; color: var(--text-secondary); margin-bottom: 7px; }
.limit-meter .lm-row strong { color: var(--text); font-variant-numeric: tabular-nums; }
.limit-bar { height: 7px; border-radius: 999px; background: rgba(15,23,42,.08); overflow: hidden; }
.limit-bar span { display: block; height: 100%; border-radius: 999px; background: var(--primary); }
.limit-bar span.warn { background: #F59E0B; }
.limit-bar span.over { background: var(--red, #DC2626); }

.filters { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; margin-bottom: 14px; }
.filters .f-group { margin: 0; }
.filters .f-group input, .filters .f-group select { width: auto; }

.row-voided td { opacity: .45; }
.row-voided .exp-amt { text-decoration: line-through; }
.exp-amt { font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
.exp-no { font-size: 12px; font-weight: 700; color: var(--text-secondary); white-space: nowrap; }
.void-chip { font-size: 11px; font-weight: 700; color: var(--red-text); background: rgba(225,29,72,.09); border: 1px solid rgba(225,29,72,.24); border-radius: 20px; padding: 2px 8px; }
.st-chip { font-size: 11px; font-weight: 700; border-radius: 20px; padding: 2px 9px; white-space: nowrap; display: inline-block; }
.st-pending  { color: #92590B; background: rgba(245,158,11,.13); border: 1px solid rgba(245,158,11,.34); }
.st-approved { color: var(--primary); background: rgba(26,127,126,.11); border: 1px solid rgba(26,127,126,.28); }
.st-rejected { color: var(--red-text, #b3261e); background: rgba(225,29,72,.09); border: 1px solid rgba(225,29,72,.24); }
.st-over { color: #9A3412; background: rgba(234,88,12,.12); border: 1px solid rgba(234,88,12,.32); margin-top: 3px; }
.over-warn { background: rgba(234,88,12,.10); border: 1px solid rgba(234,88,12,.30); color: #9A3412; border-radius: 10px; padding: 10px 12px; font-size: 12.5px; font-weight: 600; margin: -4px 0 14px; display: none; }
/* Earned-share readout under the doctor picker. Neutral while it reports a
   clean figure; amber once something needs a second look (nothing earned that
   month, or a disbursement already posted for it). */
.earned-box { margin-top: 10px; border-radius: 10px; padding: 10px 12px; font-size: 12.5px; line-height: 1.5;
              background: var(--primary-light, rgba(26,127,126,.08)); border: 1px solid var(--border); }
.earned-box .muted { color: var(--text-muted); font-weight: 400; }
.earned-box.warn { background: rgba(234,88,12,.10); border-color: rgba(234,88,12,.30); color: #9A3412; }
.earned-box .warn-line { margin-top: 6px; padding-top: 6px; border-top: 1px dashed rgba(0,0,0,.15); }
.earned-box .stream-line { margin-top: 4px; font-size: 12px; }
.over-warn.show { display: block; }
.link-btn { background: none; border: none; color: var(--primary); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 0; }
.link-btn.warn { color: var(--red-text); }
.total-strip { display: flex; gap: 18px; flex-wrap: wrap; margin-bottom: 14px; }
.total-chip { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 8px 14px; }
.total-chip strong { color: var(--text); font-variant-numeric: tabular-nums; }
.muted-note { font-size: 12px; color: var(--text-muted); margin-top: 6px; }

/* ---------------------------------------------------------------------------
   Post-expense accordion.
   Desktop keeps the form permanently open in the left column — collapsing it
   there would be a regression, since that column exists to hold it. The toggle
   row only appears at phone widths and under forced-mobile view.
   --------------------------------------------------------------------------- */
.post-toggle { display: none; }
.post-card .post-body { display: block; }

/* ---------------------------------------------------------------------------
   Pending approvals.
   --------------------------------------------------------------------------- */
/* min-width:0 lets this flex/grid child actually shrink — without it the cards'
   content sets a floor and the whole .content overflows horizontally on desktop
   (measured: 1306px inside a 1265px viewport before this was added). */
.pending-wrap { margin-bottom: 18px; min-width: 0; max-width: 100%; }
.pend-card { min-width: 0; }
.pend-card .pc-desc, .pend-card .pc-meta { overflow-wrap: anywhere; }
.pending-head { display: flex; align-items: center; gap: 9px; margin: 0 2px 10px; flex-wrap: wrap; }
.pending-head .ph-t { font-size: 11.5px; font-weight: 700; text-transform: uppercase;
                      letter-spacing: .06em; color: var(--text-secondary); }
.pending-head .ph-badge { background: var(--amber-bg); color: var(--amber-text);
                          border: 1px solid rgba(245,158,11,.34); border-radius: 20px;
                          font-size: 11px; font-weight: 700; padding: 1px 8px;
                          font-variant-numeric: tabular-nums; }
.pending-head .ph-note { font-size: 11.5px; color: var(--text-muted); }

.pend-card { background: var(--card, #fff); border: 1px solid var(--border); border-radius: 12px;
             padding: 12px 14px; margin-bottom: 8px; }
.pend-card.over { border-color: rgba(245,158,11,.40); background: var(--amber-bg); }
.pend-card.pend-extra { display: none; }
.pend-card.pend-extra.show { display: block; }
.pend-card .pc-top { display: flex; justify-content: space-between; gap: 10px; align-items: baseline; }
.pend-card .pc-amt { font-weight: 700; font-size: 15.5px; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
.pend-card .pc-cat { font-size: 12px; color: var(--text-secondary); font-weight: 600; text-align: right; }
.pend-card .pc-desc { font-size: 13px; margin-top: 3px; }
.pend-card .pc-meta { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
.pend-card .pc-flags { margin-top: 7px; }
.pend-card .pc-acts { display: flex; gap: 8px; margin-top: 10px; padding-top: 10px;
                      border-top: 1px solid var(--border); }
.pend-card.over .pc-acts { border-top-color: rgba(245,158,11,.34); }
.pend-card .pc-status { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border);
                        font-size: 12px; color: var(--text-muted); font-weight: 600; }
.pend-card.over .pc-status { border-top-color: rgba(245,158,11,.34); }
/* 44px matches the app-wide touch-target floor in assets/app.css — an approval
   tap on a phone is the primary use case for this whole screen. */
.pbtn { width: 100%; min-height: 44px; border-radius: 9px; border: 1px solid transparent;
        font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
.pbtn.p-ok { background: var(--primary); color: #fff; }
.pbtn.p-ok:hover { background: var(--primary-dark); }
.pbtn.p-no { background: transparent; color: var(--red-text); border-color: rgba(225,29,72,.34); }
.pbtn.p-no:hover { background: var(--red-bg); }
.pbtn:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(63,122,99,.28); }
.pend-more { display: block; width: 100%; padding: 10px; font: inherit; font-size: 12.5px;
             font-weight: 600; color: var(--primary); background: var(--card, #fff);
             border: 1px dashed var(--border-strong); border-radius: 10px; cursor: pointer; }
.pend-more:hover { background: var(--primary-light); }

/* Vehicle block on the posting form — sub-category segmented control, vehicle
   picker and meter reading. Shown only for needs_vehicle categories. */
.sub-seg { display: flex; gap: 6px; flex-wrap: wrap; }
.sub-btn { flex: 1; min-width: 84px; padding: 9px 8px; border-radius: 9px; border: 1px solid var(--border-strong);
           background: #fff; color: var(--text-secondary); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; }
.sub-btn:hover { border-color: var(--primary); }
.sub-btn.on { background: var(--primary); color: #fff; border-color: var(--primary); }
.sub-btn:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(63,122,99,.28); }
.meter-box { border: 1px solid var(--border); border-radius: 11px; padding: 12px 13px; background: var(--bg); margin-bottom: 13px; }
.meter-head { font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;
              letter-spacing: .06em; margin-bottom: 9px; }
.meter-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
.meter-grid label { display: block; font-size: 11.5px; font-weight: 600; color: var(--text-secondary); margin-bottom: 5px; }
.meter-grid input { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 9px;
                    font: inherit; font-size: 13.5px; background: #fff; font-variant-numeric: tabular-nums; }
.meter-grid input:disabled { background: var(--bg); color: var(--text-muted); }
.meter-grid input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(63,122,99,.18); }
.meter-calc { margin-top: 9px; padding-top: 9px; border-top: 1px dashed var(--border-strong);
              font-size: 12.5px; color: var(--text-secondary); }
.meter-calc .mc-row { display: flex; justify-content: space-between; gap: 10px; }
.meter-calc b { color: var(--text); font-variant-numeric: tabular-nums; }
.meter-warn { margin-top: 9px; border-radius: 9px; padding: 9px 11px; font-size: 12.3px; font-weight: 600; line-height: 1.5; }
.meter-warn.bad  { background: var(--red-bg); border: 1px solid rgba(225,29,72,.28); color: var(--red-text); }
.meter-warn.warn { background: var(--amber-bg); border: 1px solid rgba(245,158,11,.34); color: var(--amber-text); }

/* Bulk approve bar. Sits above the table and stays put while the list scrolls
   under it, so a long pending run does not mean scrolling back up to act. */
.bulk-bar { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
            background: var(--bg); border: 1px solid var(--border); border-radius: 12px;
            padding: 10px 14px; margin-bottom: 12px; }
.bulk-bar .bulk-all { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: var(--text); cursor: pointer; }
.bulk-bar .bulk-count { font-size: 12.5px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
.bulk-bar .bulk-actions { margin-left: auto; display: flex; gap: 8px; }
.bulk-bar button[disabled] { opacity: .45; cursor: not-allowed; }
.bulk-bar.has-sel { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.10); }
.btn.ghost { background: none; color: var(--red-text, #b3261e); border: 1px solid rgba(225,29,72,.30); }
input.bulk-pick, #bulkAll { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; }
tr.row-sel td { background: rgba(26,127,126,.055); }

/* ===========================================================================
   MOBILE. Paired rules — a real breakpoint AND html[data-view="mobile"] — the
   same pattern my_queue.php's .qrow uses, so the sidebar's Auto/Desktop/Mobile
   toggle forces this layout on a big screen too.
   =========================================================================== */
@media (max-width: 900px) {
    /* Accordion: collapse the post form behind a single tappable row. */
    .post-card { padding: 0; }
    .post-toggle { display: flex; align-items: center; gap: 10px; width: 100%;
                   min-height: 52px; padding: 13px 14px; background: none; border: 0;
                   font: inherit; text-align: left; cursor: pointer; color: var(--text); }
    .post-toggle .pt-plus { width: 26px; height: 26px; border-radius: 50%; background: var(--primary);
                            color: #fff; display: grid; place-items: center; font-size: 17px;
                            line-height: 1; flex: none; }
    .post-toggle .pt-lbl { font-weight: 600; flex: 1; font-size: 13.5px; }
    .post-toggle .pt-chev { color: var(--text-muted); font-size: 15px; transition: transform .15s; }
    .post-card.open .post-toggle .pt-chev { transform: rotate(90deg); }
    .post-card.open .post-toggle { border-bottom: 1px solid var(--border); }
    .post-card .post-body { display: none; padding: 14px; }
    .post-card.open .post-body { display: block; }
    /* The card's own heading is redundant once the toggle row names it. */
    .post-card .post-body > .section-title { display: none; }

    /* Listing table becomes cards. Same technique as .qrow: hide the head, turn
       rows into blocks, and label each cell with its column via data-label. */
    .exp-table thead { display: none; }
    .exp-table, .exp-table tbody, .exp-table tr, .exp-table td { display: block; width: 100%; }
    .exp-table tr { background: var(--card, #fff); border: 1px solid var(--border);
                    border-radius: 12px; margin-bottom: 8px; padding: 10px 12px; }
    .exp-table tr.row-voided { opacity: .55; }
    .exp-table td { border: 0; padding: 2px 0; text-align: left !important; }
    .exp-table td::before { content: attr(data-label) " "; font-size: 11px; font-weight: 700;
                            color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
    /* Amount and voucher lead the card, so they carry no label. */
    .exp-table td.m-lead::before, .exp-table td.m-nolabel::before { content: none; }
    .exp-table td.m-lead { font-size: 15px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .exp-table td.m-hide { display: none; }
    .exp-table tr td.m-act { display: flex; gap: 14px; padding-top: 8px; margin-top: 6px;
                             border-top: 1px solid var(--border); }
    .exp-table tr.veh-costrow { padding: 0; border: 0; background: none; }
    .bulk-bar { position: static; }
    .bulk-bar .bulk-actions { margin-left: 0; width: 100%; }
    .bulk-bar .bulk-actions .btn { flex: 1; min-height: 44px; }
    .filters .f-group { flex: 1 1 44%; }
    .filters .f-group input, .filters .f-group select { width: 100%; }
}

html[data-view="mobile"] .post-card { padding: 0; }
html[data-view="mobile"] .post-toggle { display: flex; align-items: center; gap: 10px; width: 100%;
    min-height: 52px; padding: 13px 14px; background: none; border: 0; font: inherit;
    text-align: left; cursor: pointer; color: var(--text); }
html[data-view="mobile"] .post-toggle .pt-plus { width: 26px; height: 26px; border-radius: 50%;
    background: var(--primary); color: #fff; display: grid; place-items: center; font-size: 17px;
    line-height: 1; flex: none; }
html[data-view="mobile"] .post-toggle .pt-lbl { font-weight: 600; flex: 1; font-size: 13.5px; }
html[data-view="mobile"] .post-toggle .pt-chev { color: var(--text-muted); font-size: 15px; }
html[data-view="mobile"] .post-card.open .post-toggle .pt-chev { transform: rotate(90deg); }
html[data-view="mobile"] .post-card.open .post-toggle { border-bottom: 1px solid var(--border); }
html[data-view="mobile"] .post-card .post-body { display: none; padding: 14px; }
html[data-view="mobile"] .post-card.open .post-body { display: block; }
html[data-view="mobile"] .post-card .post-body > .section-title { display: none; }
html[data-view="mobile"] .exp-table thead { display: none; }
html[data-view="mobile"] .exp-table,
html[data-view="mobile"] .exp-table tbody,
html[data-view="mobile"] .exp-table tr,
html[data-view="mobile"] .exp-table td { display: block; width: 100%; }
html[data-view="mobile"] .exp-table tr { background: var(--card, #fff); border: 1px solid var(--border);
    border-radius: 12px; margin-bottom: 8px; padding: 10px 12px; }
html[data-view="mobile"] .exp-table td { border: 0; padding: 2px 0; text-align: left !important; }
html[data-view="mobile"] .exp-table td::before { content: attr(data-label) " "; font-size: 11px;
    font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
html[data-view="mobile"] .exp-table td.m-lead::before,
html[data-view="mobile"] .exp-table td.m-nolabel::before { content: none; }
html[data-view="mobile"] .exp-table td.m-lead { font-size: 15px; font-weight: 700;
    font-variant-numeric: tabular-nums; }
html[data-view="mobile"] .exp-table td.m-hide { display: none; }
html[data-view="mobile"] .exp-table tr td.m-act { display: flex; gap: 14px; padding-top: 8px;
    margin-top: 6px; border-top: 1px solid var(--border); }
html[data-view="mobile"] .exp-table tr.veh-costrow { padding: 0; border: 0; background: none; }
html[data-view="mobile"] .bulk-bar { position: static; }

/* Forced-DESKTOP must UNDO the breakpoint rules, or a narrow window stays in
   card mode after the user explicitly asked for desktop. !important is required:
   the media query above has equal specificity and would otherwise win on order. */
html[data-view="desktop"] .post-toggle { display: none !important; }
html[data-view="desktop"] .post-card .post-body { display: block !important; }
html[data-view="desktop"] .exp-table { display: table !important; }
html[data-view="desktop"] .exp-table thead { display: table-header-group !important; }
html[data-view="desktop"] .exp-table tbody { display: table-row-group !important; }
html[data-view="desktop"] .exp-table tr { display: table-row !important; border: 0 !important;
    padding: 0 !important; margin: 0 !important; background: none !important; }
html[data-view="desktop"] .exp-table td { display: table-cell !important; }
html[data-view="desktop"] .exp-table td::before { content: none !important; }
html[data-view="desktop"] .exp-table td.m-hide { display: table-cell !important; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'expenses';
/* This page used to branch on role: staff got the app bar, admins got an
   inline-styled header of their own. Both are gone — sidebar.php emits the same
   bar for every role, which is the point. The page title survives in .page-head
   below, and date/Logout live in the bar. */
require __DIR__ . '/partials/sidebar.php';
?>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Expenses</div>
                    <div class="page-sub">Cash paid out of the counter — every posting needs a voucher and stays on the shift tally</div>
                </div>
                <?php if ($isAdmin): ?>
                <a class="btn" href="expense_categories.php" style="text-decoration:none;">Categories &amp; Limits</a>
                <?php endif; ?>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= $success ?></div><?php endif; ?>

            <!-- PENDING APPROVALS — promoted above everything so a phone user
                 lands on the queue, not on a form. Hidden entirely at zero (no
                 empty-state card). Not date-filtered: a three-day-old pending
                 item must not vanish because the filter defaults to today. -->
            <?php if ($pendingRows): ?>
            <div class="pending-wrap">
                <div class="pending-head">
                    <span class="ph-t">Pending approval</span>
                    <span class="ph-badge"><?= count($pendingRows) ?></span>
                    <?php if (!$canApprove): ?>
                    <span class="ph-note">Read-only — an admin or manager signs these off</span>
                    <?php endif; ?>
                </div>

                <?php foreach ($pendingRows as $i => $pr): ?>
                <?php
                    $over    = !empty($pr['over_limit']);
                    $mine    = (int) $pr['posted_by_id'] === $userId;
                    $canRow  = $canDecideRow($pr);
                    $hideCls = $i >= $pendingVisible ? ' pend-extra' : '';
                ?>
                <div class="pend-card<?= $over ? ' over' : '' ?><?= $hideCls ?>">
                    <div class="pc-top">
                        <span class="pc-amt">Rs <?= number_format((float) $pr['amount'], 0) ?></span>
                        <span class="pc-cat"><?= htmlspecialchars($pr['category_name']) ?></span>
                    </div>
                    <div class="pc-desc"><?= htmlspecialchars($pr['description']) ?></div>
                    <div class="pc-meta">
                        <?= htmlspecialchars($pr['expense_number']) ?> ·
                        <?= htmlspecialchars($pr['posted_by_name']) ?> ·
                        <?= htmlspecialchars(date('d/m h:i A', strtotime($pr['created_at'] ?? $pr['expense_date']))) ?>
                    </div>
                    <?php if ($over): ?>
                    <div class="pc-flags">
                        <span class="st-chip st-over" title="<?= htmlspecialchars($pr['limit_note'] ?? '') ?>">Over limit</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($canRow): ?>
                    <div class="pc-acts">
                        <form method="POST" action="expenses.php" style="flex:1;margin:0;">
                            <input type="hidden" name="action" value="approve_expense">
                            <input type="hidden" name="expense_id" value="<?= (int) $pr['id'] ?>">
                            <?= $filterFields ?>
                            <button type="submit" class="pbtn p-ok">Approve</button>
                        </form>
                        <form method="POST" action="expenses.php" style="flex:1;margin:0;"
                              onsubmit="var r=prompt('Reason for declining <?= htmlspecialchars($pr['expense_number']) ?> (the cash should be returned):');if(!r){return false;}this.reject_reason.value=r;return true;">
                            <input type="hidden" name="action" value="reject_expense">
                            <input type="hidden" name="expense_id" value="<?= (int) $pr['id'] ?>">
                            <input type="hidden" name="reject_reason" value="">
                            <?= $filterFields ?>
                            <button type="submit" class="pbtn p-no">Decline</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="pc-status">
                        <?php if ($mine && $canApprove): ?>
                            ◷ You posted this — another approver must sign it off
                        <?php elseif ($mine): ?>
                            ◷ Awaiting admin approval
                        <?php else: ?>
                            ◷ Awaiting admin approval
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php if (count($pendingRows) > $pendingVisible): ?>
                <button type="button" class="pend-more" id="pendMore">
                    View <?= count($pendingRows) - $pendingVisible ?> more
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="exp-grid">
                <!-- Post an expense. Collapsed to a single row on mobile (and in
                     forced-mobile view); always open on desktop, where the left
                     column exists precisely to hold it. -->
                <div class="card post-card" id="postCard">
                    <button type="button" class="post-toggle" id="postToggle"
                            aria-expanded="false" aria-controls="postBody">
                        <span class="pt-plus" aria-hidden="true">+</span>
                        <span class="pt-lbl">Post a new expense</span>
                        <span class="pt-chev" aria-hidden="true">›</span>
                    </button>
                    <div class="post-body" id="postBody">
                    <div class="section-title">Post an Expense</div>
                    <div class="section-sub">Cash leaves the counter drawer against this voucher.</div>

                    <?php if (!$isAdmin && $shiftLimitTotal > 0): ?>
                    <?php
                        $pct = min(100, $shiftLimitTotal > 0 ? ($mySpentToday / $shiftLimitTotal) * 100 : 0);
                        $barCls = $pct >= 100 ? 'over' : ($pct >= 80 ? 'warn' : '');
                    ?>
                    <div class="limit-meter">
                        <div class="lm-row">
                            <span>Your shift limit</span>
                            <strong>Rs <?= number_format($mySpentToday) ?> / <?= number_format($shiftLimitTotal) ?></strong>
                        </div>
                        <div class="limit-bar"><span class="<?= $barCls ?>" style="width:<?= $pct ?>%;"></span></div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="expenses.php" id="expForm">
                        <input type="hidden" name="action" value="post_expense">
                        <div class="f-group">
                            <label>Category</label>
                            <select name="category_id" id="expCategory" required
                                    data-limit-total="<?= htmlspecialchars((string) $shiftLimitTotal) ?>"
                                    data-mine-today="<?= htmlspecialchars((string) $mySpentToday) ?>">
                                <option value="">Select a category&hellip;</option>
                                <?php foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"
                                        data-cat-limit="<?= htmlspecialchars((string) (float) $c['shift_limit']) ?>"
                                        data-cat-spent="<?= htmlspecialchars((string) ($catSpentToday[(int) $c['id']] ?? 0)) ?>"
                                        data-cat-name="<?= htmlspecialchars($c['name']) ?>"
                                        data-period-based="<?= (int) ($c['is_period_based'] ?? 0) ?>"
                                        data-needs-doctor="<?= (int) ($c['needs_doctor'] ?? 0) ?>"
                                        data-needs-vehicle="<?= (int) ($c['needs_vehicle'] ?? 0) ?>"><?= htmlspecialchars($c['name']) ?><?= (float) $c['shift_limit'] > 0 ? ' — limit Rs ' . number_format((float) $c['shift_limit']) . '/shift' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Month picker: shown only for period-based categories
                             (Salaries, Doctor Shares) — money paid in one month
                             that belongs to another. Toggled by the script below;
                             hidden by default and NOT required, so a normal
                             running-month expense submits exactly as before. -->
                        <div class="f-group" id="periodGroup" style="display:none;">
                            <label>Which month is this payment for?</label>
                            <input type="month" name="period_month" id="expPeriod"
                                   max="<?= date('Y-m') ?>">
                            <div class="muted-note" style="margin-top:6px;">
                                July's salary paid in August still belongs to July's accounts.
                            </div>
                        </div>
                        <!-- Doctor picker: disbursement categories must name WHO
                             was paid, or the row cannot be reconciled against what
                             that doctor earned. Driven by needs_doctor, not by the
                             category name. -->
                        <div class="f-group" id="doctorGroup" style="display:none;">
                            <label>Which doctor is being paid?</label>
                            <select name="paid_to_doctor_id" id="expDoctor">
                                <option value="">Select a doctor&hellip;</option>
                                <?php foreach ($doctorsList as $d): ?>
                                <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <!-- Filled by doctor_earned.php once doctor + month are
                                 both chosen. Shows what was earned and what has
                                 already been paid, so a double payment is visible
                                 BEFORE the button is pressed. -->
                            <div id="earnedBox" class="earned-box" style="display:none;"></div>
                        </div>
                        <!-- Vehicle block: sub-category, vehicle and meter reading.
                             Shown only for categories flagged needs_vehicle, and
                             only once add_vehicle_expenses.sql has run (no
                             vehicles list = no fields). Feeds the per-vehicle
                             cost-per-km reports and the approval cost panel. -->
                        <?php if ($vehiclesList): ?>
                        <div id="vehicleGroup" style="display:none;">
                            <div class="f-group">
                                <label>Type of spend</label>
                                <div class="sub-seg" id="subSeg">
                                    <?php
                                    // Rendered per category so a future vehicle
                                    // category brings its own sub-categories.
                                    $allSubs = [];
                                    foreach ($subcatsByCat as $catId => $subs) {
                                        foreach ($subs as $sc) { $allSubs[] = ['cat' => $catId] + $sc; }
                                    }
                                    foreach ($allSubs as $sc): ?>
                                    <button type="button" class="sub-btn"
                                            data-cat="<?= (int) $sc['cat'] ?>"
                                            data-sub="<?= (int) $sc['id'] ?>"
                                            data-fuel="<?= (int) $sc['tracks_fuel'] ?>"><?= htmlspecialchars($sc['name']) ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="subcategory_id" id="expSubcat" value="">
                            </div>

                            <div class="f-group">
                                <label>Vehicle</label>
                                <select name="vehicle_id" id="expVehicle">
                                    <option value="">Select a vehicle&hellip;</option>
                                    <?php foreach ($vehiclesList as $v): ?>
                                    <option value="<?= (int) $v['id'] ?>"><?= htmlspecialchars($v['name']) ?> — <?= htmlspecialchars($v['registration']) ?><?= $v['vehicle_type'] ? ' (' . htmlspecialchars($v['vehicle_type']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="meter-box">
                                <div class="meter-head">Meter reading</div>
                                <div class="meter-grid">
                                    <div>
                                        <label>Previous (km)</label>
                                        <input type="text" id="expPrevMeter" value="—" disabled>
                                    </div>
                                    <div>
                                        <label>Current (km)</label>
                                        <input type="number" name="meter_reading" id="expMeter" min="0" step="1" placeholder="0">
                                    </div>
                                </div>
                                <div class="meter-calc" id="meterCalc" style="display:none;"></div>
                                <div class="meter-warn" id="meterWarn" style="display:none;"></div>
                            </div>

                            <div class="f-group" id="litresGroup" style="display:none;">
                                <label>Litres</label>
                                <input type="number" name="litres" id="expLitres" step="0.01" min="0" placeholder="0.00">
                                <div class="meter-warn" id="rateWarn" style="display:none;"></div>
                            </div>

                            <?php if ($refuelReady): ?>
                            <!-- WHO took the vehicle to the pump. Fuel rows only,
                                 and optional: a fill nobody remembers must still
                                 be postable, because a guessed name feeding a
                                 report that accuses someone is worse than a blank.
                                 Drives the per-person km/L comparison on the
                                 Vehicle Report. -->
                            <div class="f-group" id="refuelGroup" style="display:none;">
                                <label>Refuelled by</label>
                                <select name="refuelled_by_id" id="expRefueller">
                                    <option value="">Not recorded</option>
                                    <?php foreach ($refuelStaff as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['name']) ?><?php
                                        if (!empty($u['base_role'])) { echo ' — ' . htmlspecialchars(ucfirst(strtolower($u['base_role']))); } ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="muted-note" style="margin-top:6px;">
                                    Who physically took the vehicle to the pump — not who paid.
                                    This is what lets the report compare km per litre by person.
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Full tank? Only a full-to-full pair gives a true
                                 km/L, so the fill state has to be recorded rather
                                 than assumed. Defaults to Yes: topping up is the
                                 exception, not the rule. -->
                            <div class="f-group" id="tankGroup" style="display:none;">
                                <label>Filled to full?</label>
                                <div class="sub-seg" id="tankSeg">
                                    <button type="button" class="sub-btn on" data-tank="1">Yes — full tank</button>
                                    <button type="button" class="sub-btn" data-tank="0">No — part fill</button>
                                </div>
                                <input type="hidden" name="tank_full" id="expTankFull" value="1">
                                <div class="muted-note" style="margin-top:6px;">
                                    Only full-to-full fills give a true km/L. A part fill still counts
                                    toward cost per km.
                                </div>
                            </div>

                            <div class="meter-warn" id="dupWarn" style="display:none;"></div>
                        </div>
                        <?php endif; ?>

                        <div class="f-group">
                            <label>Amount (Rs)</label>
                            <input type="number" name="amount" id="expAmount" step="0.01" min="1" placeholder="0" required>
                        </div>
                        <?php if ($isAdmin): ?>
                        <!-- Admin-only. A receptionist can only ever spend counter
                             cash, so the field is not rendered for them and the
                             POST handler pins their source to CASH_COUNTER. -->
                        <div class="f-group">
                            <label>Paid from</label>
                            <select name="source" id="expSource">
                                <option value="CASH_COUNTER">Counter cash (affects the day tally)</option>
                                <option value="BANK">Bank transfer</option>
                                <option value="OWNER">Owner / outside the counter</option>
                            </select>
                            <div class="muted-note" style="margin-top:6px;">
                                Only counter cash is deducted from the shift's expected drawer.
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!$isAdmin): ?>
                        <div class="over-warn" id="overWarn"></div>
                        <?php endif; ?>
                        <div class="f-group">
                            <label>What was it for?</label>
                            <textarea name="description" maxlength="255" placeholder="e.g. Printer cartridge for reception" required></textarea>
                        </div>
                        <div class="f-group">
                            <label>Paid to <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
                            <input type="text" name="paid_to" maxlength="120" placeholder="Vendor / rider / staff name">
                        </div>
                        <?= imp_confirm_field('Post this counter expense') ?>
                        <button type="submit" class="btn" style="width:100%;">Post Expense</button>
                        <div class="muted-note">Keep the receipt with the counter cash for the shift tally.</div>
                    </form>
                    </div><!-- /.post-body -->
                </div>

                <!-- Listing -->
                <div class="card">
                    <div class="section-title"><?= $isAdmin ? 'All Expenses' : "Today's Expenses" ?></div>
                    <div class="section-sub"><?= $isAdmin ? 'Voided rows keep their voucher number but drop out of every total.' : 'Everything posted from the counter this shift, by all users.' ?></div>

                    <?php if ($isAdmin): ?>
                    <form method="GET" action="expenses.php" class="filters">
                        <div class="f-group"><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>"></div>
                        <div class="f-group"><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>"></div>
                        <div class="f-group">
                            <label>Category</label>
                            <select name="cat">
                                <option value="0">All categories</option>
                                <?php foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= $filterCat === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn small" style="padding:9px 16px;font-size:12.5px;">Filter</button>
                    </form>
                    <?php endif; ?>

                    <div class="total-strip">
                        <span class="total-chip">Total (<?= $filterFrom === $filterTo ? htmlspecialchars(date('d/m', strtotime($filterFrom))) : htmlspecialchars(date('d/m', strtotime($filterFrom)) . ' – ' . date('d/m', strtotime($filterTo))) ?>): <strong>Rs <?= number_format($rangeTotal, 2) ?></strong></span>
                        <?php foreach ($userTotals as $ut): ?>
                        <span class="total-chip"><?= htmlspecialchars($ut['name']) ?>: <strong>Rs <?= number_format((float) $ut['total']) ?></strong> (<?= (int) $ut['cnt'] ?>)</span>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($canApprove && $pendingIds): ?>
                    <!-- Bulk bar. Ticking rows enables it; the buttons submit the
                         same handler as the per-row forms, which decides each id
                         in its own transaction. Hidden without JS-selected rows
                         so it can never fire on an empty selection. -->
                    <div class="bulk-bar" id="bulkBar">
                        <label class="bulk-all">
                            <input type="checkbox" id="bulkAll">
                            Select all <?= count($pendingIds) ?> awaiting approval
                        </label>
                        <span class="bulk-count" id="bulkCount">None selected</span>
                        <span class="bulk-actions">
                            <button type="submit" form="bulkForm" name="action" value="approve_expense"
                                    class="btn small" id="bulkApprove" disabled
                                    style="padding:7px 14px;font-size:12.5px;">Approve selected</button>
                            <button type="submit" form="bulkForm" name="action" value="reject_expense"
                                    class="btn small ghost" id="bulkReject" disabled
                                    style="padding:7px 14px;font-size:12.5px;">Reject selected</button>
                        </span>
                    </div>
                    <?php endif; ?>

                    <!-- The bulk form wraps nothing itself: the row tick-boxes and
                         the bar's buttons both point at it by id, because a <form>
                         cannot legally wrap <tr>s that also contain the per-row
                         action forms (nested forms are dropped by the parser). -->
                    <?php if ($canApprove && $pendingIds): ?>
                    <form method="POST" action="expenses.php" id="bulkForm"
                          onsubmit="return bulkConfirm(this, event);">
                        <input type="hidden" name="reject_reason" value="">
                        <?= $filterFields ?>
                    </form>
                    <?php endif; ?>

                    <div style="overflow-x:auto;">
                    <table class="exp-table">
                        <thead>
                            <tr>
                                <?php if ($canApprove && $pendingIds): ?><th style="width:34px;"></th><?php endif; ?>
                                <th>Voucher</th>
                                <?php if ($isAdmin): ?><th>Date</th><?php endif; ?>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Paid to</th>
                                <th>Posted by</th>
                                <th style="text-align:right;">Amount</th>
                                <th>Status</th>
                                <?php if ($isAdmin || $canApprove): ?><th style="width:110px;">Action</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                            <?php $emptyCols = ($isAdmin ? 8 : 7) + (($isAdmin || $canApprove) ? 1 : 0)
                                             + (($canApprove && $pendingIds) ? 1 : 0); ?>
                            <tr><td colspan="<?= $emptyCols ?>" class="muted" style="padding:20px 10px;">No expenses<?= $isAdmin ? ' in this range' : ' posted this shift yet' ?>.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $r): ?>
                            <?php
                                $voided = $r['voided_at'] !== null;
                                $rowPending = !$voided && ($r['approval_status'] ?? 'PENDING') === 'PENDING';
                            ?>
                            <tr class="<?= $voided ? 'row-voided' : '' ?>">
                                <?php if ($canApprove && $pendingIds): ?>
                                <td class="m-hide">
                                    <?php /* Not offered for the approver's OWN posting — decide_expense()
                                             refuses those, so a tick-box here would promise an action that
                                             is guaranteed to fail and report as "skipped". */ ?>
                                    <?php if ($rowPending && (int) $r['posted_by_id'] !== $userId): ?>
                                    <input type="checkbox" class="bulk-pick" form="bulkForm"
                                           name="expense_ids[]" value="<?= (int) $r['id'] ?>"
                                           aria-label="Select <?= htmlspecialchars($r['expense_number']) ?>">
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td class="m-nolabel">
                                    <span class="exp-no"><?= htmlspecialchars($r['expense_number']) ?></span>
                                    <?php if ($voided): ?><br><span class="void-chip" title="<?= htmlspecialchars('By ' . ($r['voided_by_name'] ?? '') . ': ' . ($r['void_reason'] ?? '')) ?>">VOID</span><?php endif; ?>
                                </td>
                                <?php if ($isAdmin): ?><td style="white-space:nowrap;" data-label="Date"><?= htmlspecialchars(date('d/m/Y', strtotime($r['expense_date']))) ?></td><?php endif; ?>
                                <td data-label="Category"><?= htmlspecialchars($r['category_name']) ?></td>
                                <td class="m-nolabel" style="font-weight:600;"><?= htmlspecialchars($r['description']) ?></td>
                                <td data-label="Paid to" class="<?= ($r['paid_to'] ?? '') === '' ? 'm-hide' : '' ?>"><?= htmlspecialchars($r['paid_to'] ?? '—') ?></td>
                                <td data-label="By"><?= htmlspecialchars($r['posted_by_name']) ?>
                                    <?php /* Who refuelled, when it differs from who posted. Shown on
                                             the row so a wrong attribution is noticed here rather than
                                             first appearing as a red flag on the report. */ ?>
                                    <?php if (!empty($r['refuelled_by_name']) && $r['refuelled_by_name'] !== $r['posted_by_name']): ?>
                                    <br><span class="muted" style="font-size:11px;" title="Refuelled by">
                                        ⛽ <?= htmlspecialchars($r['refuelled_by_name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;" class="m-lead"><span class="exp-amt">Rs <?= number_format((float) $r['amount'], 2) ?></span></td>
                                <td class="m-nolabel">
                                    <?php
                                        $st = $r['approval_status'] ?? 'PENDING';
                                        if ($st === 'APPROVED') {
                                            $stTitle = $r['approved_by_name'] ? 'By ' . $r['approved_by_name'] . ($r['approved_at'] ? ' · ' . date('d/m h:i A', strtotime($r['approved_at'])) : '') : '';
                                            echo '<span class="st-chip st-approved" title="' . htmlspecialchars($stTitle) . '">Approved</span>';
                                        } elseif ($st === 'REJECTED') {
                                            $stTitle = ($r['approved_by_name'] ? 'By ' . $r['approved_by_name'] . ': ' : '') . ($r['rejection_reason'] ?? '');
                                            echo '<span class="st-chip st-rejected" title="' . htmlspecialchars($stTitle) . '">Rejected</span>';
                                        } else {
                                            echo '<span class="st-chip st-pending">Awaiting approval</span>';
                                        }
                                        if (!empty($r['over_limit'])) {
                                            echo '<br><span class="st-chip st-over" title="' . htmlspecialchars($r['limit_note'] ?? 'Exceeded a shift limit') . '">Over limit</span>';
                                        }
                                    ?>
                                </td>
                                <?php if ($isAdmin || $canApprove): ?>
                                <td style="white-space:nowrap;" class="m-act m-nolabel">
                                    <?php if (!$voided && $canApprove && $st === 'PENDING'): ?>
                                    <form method="POST" action="expenses.php" style="margin:0 0 4px;">
                                        <input type="hidden" name="action" value="approve_expense">
                                        <input type="hidden" name="expense_id" value="<?= (int) $r['id'] ?>">
                                        <?= $filterFields ?>
                                        <button type="submit" class="link-btn">Approve</button>
                                    </form>
                                    <form method="POST" action="expenses.php" style="margin:0 0 4px;"
                                          onsubmit="var r=prompt('Reason for rejecting <?= htmlspecialchars($r['expense_number']) ?> (cash to be returned):');if(!r){return false;}this.reject_reason.value=r;return true;">
                                        <input type="hidden" name="action" value="reject_expense">
                                        <input type="hidden" name="expense_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="reject_reason" value="">
                                        <?= $filterFields ?>
                                        <button type="submit" class="link-btn warn">Reject</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php
                                    /* Correct a mistyped odometer. Admin only, and only on a
                                       row that actually carries a vehicle — a reading has no
                                       meaning without one. Offered even on a CLOSED day and on
                                       an already-approved row: no money moves, and those are
                                       exactly the readings that need fixing. */
                                    if (!$voided && $isAdmin && $vehiclePanelReady && !empty($r['vehicle_id'])):
                                        $curM = $r['meter_reading'] !== null ? (string) (int) $r['meter_reading'] : '';
                                        $curL = $r['litres'] !== null ? rtrim(rtrim(number_format((float) $r['litres'], 2, '.', ''), '0'), '.') : '';
                                        $curT = array_key_exists('tank_full', $r) && $r['tank_full'] !== null ? (string) (int) $r['tank_full'] : '';
                                        // Pre-fills the refueller prompt. Absent pre-migration, in
                                        // which case editMeter() skips that step entirely.
                                        $curRN = $r['refuelled_by_name'] ?? '';
                                    ?>
                                    <form method="POST" action="expenses.php" style="margin:0 0 4px;"
                                          onsubmit="return editMeter(this, '<?= htmlspecialchars($r['expense_number'], ENT_QUOTES) ?>', '<?= htmlspecialchars($curM, ENT_QUOTES) ?>', '<?= htmlspecialchars($curL, ENT_QUOTES) ?>', '<?= htmlspecialchars($curT, ENT_QUOTES) ?>', '<?= htmlspecialchars($curRN, ENT_QUOTES) ?>');">
                                        <input type="hidden" name="action" value="edit_meter">
                                        <input type="hidden" name="expense_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="meter_reading" value="">
                                        <input type="hidden" name="litres" value="">
                                        <input type="hidden" name="tank_full" value="">
                                        <input type="hidden" name="refuelled_by_id" value="">
                                        <?= $filterFields ?>
                                        <button type="submit" class="link-btn"><?= $curM === '' ? 'Add meter' : 'Edit meter' ?></button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!$voided && $isAdmin): ?>
                                    <form method="POST" action="expenses.php" style="margin:0;"
                                          onsubmit="var r=prompt('Reason for voiding <?= htmlspecialchars($r['expense_number']) ?>:');if(!r){return false;}this.void_reason.value=r;return true;">
                                        <input type="hidden" name="action" value="void_expense">
                                        <input type="hidden" name="expense_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="void_reason" value="">
                                        <?= $filterFields ?>
                                        <button type="submit" class="link-btn warn">Void</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php
                            // Vehicle running cost, inline under a PENDING vehicle
                            // expense, so the approver sees cost per km at the
                            // moment of deciding rather than later in a report.
                            // Only for rows an approver can actually act on —
                            // decided rows would just be noise.
                            if ($canApprove && $rowPending && $vehiclePanelReady
                                && !empty($r['vehicle_id'])):
                            ?>
                            <tr class="veh-costrow">
                                <td colspan="<?= $rowCols ?>" style="padding-top:0;">
                                    <?php vehicle_cost_panel($pdo, (int) $r['id']); ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
<script>
// Post-expense accordion + "View N more" on the pending queue.
// The accordion row only exists at mobile widths (CSS decides), so this just
// toggles a class — no width sniffing in JS, which would disagree with the
// media query at the boundary and after a view-toggle change.
(function () {
    var card = document.getElementById('postCard');
    var tog  = document.getElementById('postToggle');
    if (card && tog) {
        tog.addEventListener('click', function () {
            var open = card.classList.toggle('open');
            tog.setAttribute('aria-expanded', open ? 'true' : 'false');
            tog.querySelector('.pt-plus').textContent = open ? '×' : '+';
            // Bring the first field into view when opening on a phone.
            if (open) {
                var first = card.querySelector('#expCategory');
                if (first && window.matchMedia('(max-width: 900px)').matches) {
                    setTimeout(function () { first.focus({ preventScroll: false }); }, 60);
                }
            }
        });
        // A validation error means the user was mid-post: open it so they can
        // see what went wrong instead of a collapsed row hiding the form.
        if (document.querySelector('.alert.error')) {
            card.classList.add('open');
            tog.setAttribute('aria-expanded', 'true');
            tog.querySelector('.pt-plus').textContent = '×';
        }
    }

    var more = document.getElementById('pendMore');
    if (more) {
        more.addEventListener('click', function () {
            document.querySelectorAll('.pend-card.pend-extra').forEach(function (c) {
                c.classList.add('show');
            });
            more.remove();
        });
    }
})();

<?php if ($isAdmin && $refuelReady && $refuelStaff): ?>
<?php /* Name -> id for the Edit-meter refueller prompt. Emitted only for an
         admin (the only role that can call the handler) and only once the column
         exists, so editMeter() can test for it and skip that step otherwise. */ ?>
window.EXP_REFUEL_STAFF = <?= json_encode(array_map(
    fn($u) => ['id' => (int) $u['id'], 'n' => $u['name']], $refuelStaff),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
<?php endif; ?>

// Correct a meter reading. prompt() rather than a modal to match the Void and
// Reject controls already on this row — one bespoke dialog among three prompts
// would be the odd one out. Litres is asked only as a follow-up so the common
// case (fix the odometer, leave litres alone) stays two keystrokes.
//
// Blank is a MEANINGFUL answer: it clears the reading, which is the right
// correction for "this row never had a trustworthy number". Cancel aborts.
function editMeter(form, voucher, curMeter, curLitres, curTank, curRefuelName) {
    var m = window.prompt(
        'Meter reading (km) for ' + voucher + ':\n\n' +
        'Leave blank to clear it. Cancel to abort.',
        curMeter);
    if (m === null) { return false; }               // cancelled
    m = m.trim();
    if (m !== '' && !/^\d{1,9}$/.test(m)) {
        window.alert('Enter whole kilometres only, e.g. 48595 — no commas or decimals.');
        return false;
    }

    var l = window.prompt(
        'Litres for ' + voucher + ':\n\n' +
        'Leave blank if not a fuel fill. Cancel to abort.',
        curLitres);
    if (l === null) { return false; }
    l = l.trim();
    if (l !== '' && !/^\d{1,6}(\.\d{1,2})?$/.test(l)) {
        window.alert('Enter litres as a number, e.g. 40 or 40.50.');
        return false;
    }

    // Tank state only matters when there are litres — a maintenance row has no
    // fill to describe. Blank leaves the existing flag untouched.
    var t = '';
    if (l !== '') {
        t = window.prompt(
            'Was the tank filled to FULL? Enter 1 for full, 0 for a part fill.\n\n' +
            'Leave blank to leave it unchanged. Only full-to-full pairs give a km/L.',
            curTank);
        if (t === null) { return false; }
        t = t.trim();
        if (t !== '' && t !== '0' && t !== '1') {
            window.alert('Enter 1 for a full tank, 0 for a part fill, or leave blank.');
            return false;
        }
    }

    // WHO refuelled, asked only on a fuel row (litres present) and only once the
    // column exists. Matched on the typed NAME rather than an id: nobody knows
    // their own user id, and a mistyped id would silently attribute a fill to
    // the wrong person in a report used to question fuel use.
    //
    // Three answers: blank leaves it unchanged, "-" clears it, a name sets it.
    // Clearing must be reachable so a wrong attribution can be undone.
    var r = '';
    if (l !== '' && window.EXP_REFUEL_STAFF) {
        var typed = window.prompt(
            'Refuelled by — who took the vehicle to the pump?\n\n' +
            'Type part of the name. Enter "-" to clear it.\n' +
            'Leave blank to leave it unchanged.' +
            (curRefuelName ? '\n\nCurrently: ' + curRefuelName : ''),
            curRefuelName || '');
        if (typed === null) { return false; }
        typed = typed.trim();
        if (typed === '-') {
            r = '0';                                  // explicit clear
        } else if (typed !== '') {
            var needle = typed.toUpperCase(), hits = [];
            for (var i = 0; i < window.EXP_REFUEL_STAFF.length; i++) {
                if (window.EXP_REFUEL_STAFF[i].n.toUpperCase().indexOf(needle) !== -1) {
                    hits.push(window.EXP_REFUEL_STAFF[i]);
                }
            }
            if (hits.length === 0) {
                window.alert('No active staff member matches "' + typed + '".');
                return false;
            }
            // Never guess between two matches — picking one would be a silent
            // misattribution, which is the one error this feature must not make.
            if (hits.length > 1) {
                var names = hits.map(function (h) { return h.n; }).slice(0, 8).join('\n');
                window.alert(hits.length + ' staff match "' + typed + '":\n\n' + names +
                             '\n\nType more of the name so there is only one match.');
                return false;
            }
            r = String(hits[0].id);
        }
    }

    form.meter_reading.value = m;
    form.litres.value = l;
    form.tank_full.value = t;
    if (form.refuelled_by_id) { form.refuelled_by_id.value = r; }
    return true;
}
</script>
<?php if ($canApprove && $pendingIds): ?>
<script>
// Bulk approve/reject. The tick-boxes and the bar's buttons all belong to
// #bulkForm via form=, so the browser gathers expense_ids[] for us — this only
// drives the select-all box, the live count, and the confirm.
(function () {
    var bar   = document.getElementById('bulkBar');
    var all   = document.getElementById('bulkAll');
    var count = document.getElementById('bulkCount');
    var okBtn = document.getElementById('bulkApprove');
    var noBtn = document.getElementById('bulkReject');
    var picks = Array.prototype.slice.call(document.querySelectorAll('.bulk-pick'));
    if (!bar || !all || !picks.length) return;

    function selected() { return picks.filter(function (p) { return p.checked; }); }

    function refresh() {
        var n = selected().length;
        count.textContent = n ? (n + ' selected') : 'None selected';
        okBtn.disabled = noBtn.disabled = (n === 0);
        bar.classList.toggle('has-sel', n > 0);
        // Indeterminate keeps the header box honest on a partial selection.
        all.checked = (n === picks.length);
        all.indeterminate = (n > 0 && n < picks.length);
        picks.forEach(function (p) {
            var tr = p.closest('tr');
            if (tr) { tr.classList.toggle('row-sel', p.checked); }
        });
    }

    all.addEventListener('change', function () {
        picks.forEach(function (p) { p.checked = all.checked; });
        refresh();
    });
    picks.forEach(function (p) { p.addEventListener('change', refresh); });

    // Which button was pressed. SubmitEvent.submitter is not in older WebViews
    // (the Android wrapper), so record the click ourselves and use submitter only
    // as a cross-check — without it a bulk Reject would skip its reason prompt.
    var lastAction = '';
    [okBtn, noBtn].forEach(function (b) {
        if (b) { b.addEventListener('click', function () { lastAction = b.value; }); }
    });

    // Named globally because the form's onsubmit attribute calls it.
    window.bulkConfirm = function (form, ev) {
        var n = selected().length;
        if (!n) { return false; }
        var act = (ev && ev.submitter && ev.submitter.value) || lastAction;
        if (act === 'reject_expense') {
            var r = prompt('Reason for rejecting ' + n + ' expense' + (n === 1 ? '' : 's')
                         + ' (cash to be returned). The same reason is recorded on each:');
            if (!r) { return false; }
            form.reject_reason.value = r;
            return true;
        }
        return window.confirm('Approve ' + n + ' expense' + (n === 1 ? '' : 's') + '?');
    };

    refresh();
})();
</script>
<?php endif; ?>
<script>
// Month picker toggle. Salaries and Doctor Shares are paid in a LATER month than
// they belong to, so those categories must capture which month — everything else
// (rent included) is a running-month cost keyed off the posting date.
// Driven by expense_categories.is_period_based, never by category NAME, so
// adding another deferred-payment category later needs no code change.
// Runs for admins too, unlike the over-limit script below.
(function () {
    var cat = document.getElementById('expCategory');
    var grp = document.getElementById('periodGroup');
    var inp = document.getElementById('expPeriod');
    if (!cat || !grp || !inp) return;

    // Doctor picker + earned-share auto-load. Null on non-admin pages, where the
    // disbursement category is not offered at all.
    var docGrp = document.getElementById('doctorGroup');
    var docSel = document.getElementById('expDoctor');
    var earned = document.getElementById('earnedBox');
    var amount = document.getElementById('expAmount');

    function money(n) { return 'Rs ' + Math.round(n).toLocaleString('en-US'); }

    // Ask the server what this doctor earned in this month and pre-fill the
    // amount with the BALANCE still owed, so a part-payment tops up rather than
    // re-paying the whole month. The figure stays editable — an agreed
    // adjustment is normal, so this suggests rather than dictates.
    var lastKey = '';
    function loadEarned() {
        if (!docGrp || !docSel || !earned) return;
        var id = docSel.value, m = inp.value;
        if (!id || !m) { earned.style.display = 'none'; lastKey = ''; return; }
        var key = id + '|' + m;
        if (key === lastKey) return;          // avoid refetching on unrelated changes
        lastKey = key;

        earned.style.display = '';
        earned.className = 'earned-box';
        earned.textContent = 'Checking what was earned…';

        fetch('doctor_earned.php?doctor_id=' + encodeURIComponent(id) + '&month=' + encodeURIComponent(m), {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) {
            if (d.bills === 0) {
                earned.className = 'earned-box warn';
                earned.innerHTML = '<strong>Nothing earned</strong> by this doctor in that month — '
                    + 'no paid OPD consultations and no paid in-door daily rounds. '
                    + 'Check the month before posting.';
                return;
            }
            var html = '<div><strong>' + money(d.doctor) + '</strong> earned '
                     + '<span class="muted">(' + money(d.gross) + ' gross';
            if (d.tax > 0) { html += ', ' + money(d.tax) + ' tax withheld'; }
            html += ')</span></div>';

            // Where it came from. In-door rounds are consultation income too, so
            // show both lines whenever the doctor has any IPD work — otherwise
            // the total looks unexplained against the OPD count alone.
            if (d.ipd_visits > 0) {
                html += '<div class="stream-line">'
                      + 'OPD ' + money(d.opd_doctor) + ' <span class="muted">· ' + d.opd_bills
                      + ' consultation' + (d.opd_bills === 1 ? '' : 's') + '</span>'
                      + ' &nbsp;|&nbsp; In-door ' + money(d.ipd_doctor)
                      + ' <span class="muted">· ' + d.ipd_visits + ' daily round'
                      + (d.ipd_visits === 1 ? '' : 's') + '</span></div>';
            } else {
                html += '<div class="stream-line muted">from ' + d.opd_bills
                      + ' paid consultation' + (d.opd_bills === 1 ? '' : 's') + '</div>';
            }

            if (d.already_paid > 0) {
                html += '<div class="warn-line">Already disbursed for this month: <strong>'
                      + money(d.already_paid) + '</strong>'
                      + (d.suggested > 0 ? ' — balance ' + money(d.suggested) : ' — fully paid')
                      + '</div>';
            }
            earned.className = 'earned-box' + (d.already_paid > 0 ? ' warn' : '');
            earned.innerHTML = html;

            // Only overwrite an untouched or auto-filled amount — never clobber
            // a figure the admin deliberately typed.
            if (amount && (!amount.value || amount.dataset.auto === '1')) {
                amount.value = d.suggested > 0 ? d.suggested : '';
                amount.dataset.auto = '1';
            }
        })
        .catch(function () {
            earned.className = 'earned-box warn';
            earned.textContent = 'Could not load the earned figure — enter the amount manually.';
        });
    }

    if (amount) {
        // Any manual keystroke ends auto-fill ownership of the field.
        amount.addEventListener('input', function () { amount.dataset.auto = ''; });
    }

    function sync() {
        var opt = cat.options[cat.selectedIndex];
        var on = !!(opt && opt.getAttribute('data-period-based') === '1');
        grp.style.display = on ? '' : 'none';
        // Only required while visible, or the browser would block submission on
        // a hidden field and give the user nothing to fix.
        inp.required = on;
        if (on && !inp.value) {
            // Default to LAST month: salaries are paid after the month worked,
            // which is the overwhelmingly common case.
            var d = new Date();
            d.setDate(1);
            d.setMonth(d.getMonth() - 1);
            inp.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
        }
        if (!on) { inp.value = ''; }

        // Doctor picker follows its own flag, not the month flag.
        if (docGrp && docSel) {
            var needsDoc = !!(opt && opt.getAttribute('data-needs-doctor') === '1');
            docGrp.style.display = needsDoc ? '' : 'none';
            docSel.required = needsDoc;
            if (!needsDoc) {
                docSel.value = '';
                if (earned) { earned.style.display = 'none'; }
                lastKey = '';
                if (amount && amount.dataset.auto === '1') { amount.value = ''; amount.dataset.auto = ''; }
            } else {
                loadEarned();
            }
        }
    }
    cat.addEventListener('change', sync);
    if (docSel) { docSel.addEventListener('change', loadEarned); }
    inp.addEventListener('change', loadEarned);
    sync();

    // ---- Vehicle block ----------------------------------------------------
    // Sub-category buttons, vehicle picker, and the live meter-reading check.
    // The rollback warning is shown here, at typing time, so the mistake is
    // caught by the person who can still fix it rather than by the approver.
    var vehGrp  = document.getElementById('vehicleGroup');
    var vehSel  = document.getElementById('expVehicle');
    var subHid  = document.getElementById('expSubcat');
    var meter   = document.getElementById('expMeter');
    var prevInp = document.getElementById('expPrevMeter');
    var calc    = document.getElementById('meterCalc');
    var mWarn   = document.getElementById('meterWarn');
    var litGrp  = document.getElementById('litresGroup');
    var litres  = document.getElementById('expLitres');
    var subBtns = vehGrp ? [].slice.call(vehGrp.querySelectorAll('.sub-btn')) : [];

    if (vehGrp) {
        var prevMeter = null, maxGap = 5000;

        function fmt(n) { return Number(n).toLocaleString('en-US'); }

        function refreshCalc() {
            var cur = parseInt(meter.value || '', 10);
            calc.style.display = 'none';
            mWarn.style.display = 'none';
            if (isNaN(cur) || prevMeter === null) { return; }

            var delta = cur - prevMeter;
            if (delta <= 0) {
                mWarn.className = 'meter-warn bad';
                mWarn.textContent = 'That is ' + fmt(Math.abs(delta)) + ' km LOWER than this vehicle’s '
                    + 'last reading (' + fmt(prevMeter) + '). Check the meter — you can still post it, '
                    + 'but it goes for approval and no cost per km can be worked out.';
                mWarn.style.display = '';
                return;
            }
            if (delta > maxGap) {
                mWarn.className = 'meter-warn warn';
                mWarn.textContent = 'That is a jump of ' + fmt(delta) + ' km since the last reading — '
                    + 'larger than ' + fmt(maxGap) + ' km, so a posting may have been missed.';
                mWarn.style.display = '';
                return;
            }

            var html = '<div class="mc-row"><span>Distance since last reading</span><b>' + fmt(delta) + ' km</b></div>';
            var L = parseFloat(litres && litres.value ? litres.value : '');
            if (!isNaN(L) && L > 0) {
                html += '<div class="mc-row" style="margin-top:4px;"><span>Fuel economy this fill</span><b>'
                      + (delta / L).toFixed(2) + ' km/L</b></div>';
            }
            var amt = parseFloat(amount && amount.value ? amount.value : '');
            if (!isNaN(amt) && amt > 0) {
                html += '<div class="mc-row" style="margin-top:4px;"><span>Cost per km</span><b>Rs '
                      + (amt / delta).toFixed(2) + '</b></div>';
            }
            calc.innerHTML = html;
            calc.style.display = '';
        }

        function loadPrev() {
            prevMeter = null;
            prevInp.value = '—';
            refreshCalc();
            if (!vehSel.value) { return; }
            fetch('vehicle_last_meter.php?vehicle_id=' + encodeURIComponent(vehSel.value),
                  { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                .then(function (d) {
                    if (d && d.ok && d.meter !== null) {
                        prevMeter = d.meter;
                        maxGap = d.max_gap || maxGap;
                        prevInp.value = fmt(d.meter) + (d.date ? '  (' + d.date + ')' : '');
                    } else {
                        prevInp.value = 'first reading';
                    }
                    if (d) {
                        fleetRate = (d.fleet_rate === null || d.fleet_rate === undefined)
                                  ? null : parseFloat(d.fleet_rate);
                        fuelToday = !!d.fuel_today;
                    }
                    refreshCalc();
                    refreshRate();
                    refreshDup();
                })
                .catch(function () { prevInp.value = '—'; });
        }

        // ---- Full-tank toggle -----------------------------------------------
        var tankGrp = document.getElementById('tankGroup');
        var tankSeg = document.getElementById('tankSeg');
        var tankHid = document.getElementById('expTankFull');
        var rateWarn = document.getElementById('rateWarn');
        var dupWarn  = document.getElementById('dupWarn');
        // Both null before add_fuel_accountability.sql runs — every use is guarded.
        var refuelGrp = document.getElementById('refuelGroup');
        var refuelSel = document.getElementById('expRefueller');
        var fleetRate = null, fuelToday = false;

        if (tankSeg) {
            [].slice.call(tankSeg.querySelectorAll('.sub-btn')).forEach(function (b) {
                b.addEventListener('click', function () {
                    [].slice.call(tankSeg.querySelectorAll('.sub-btn')).forEach(function (x) {
                        x.classList.remove('on');
                    });
                    b.classList.add('on');
                    tankHid.value = b.getAttribute('data-tank');
                });
            });
        }

        // Implied Rs/litre vs the fleet's trailing 30-day average. Non-blocking:
        // fuel prices move and a genuine outlier is possible, so this informs the
        // person who can still check the receipt.
        function refreshRate() {
            if (!rateWarn) { return; }
            rateWarn.style.display = 'none';
            var amt = parseFloat(amount && amount.value ? amount.value : '');
            var L   = parseFloat(litres && litres.value ? litres.value : '');
            if (!(amt > 0) || !(L > 0) || !(fleetRate > 0)) { return; }
            var mine = amt / L;
            var diff = ((mine - fleetRate) / fleetRate) * 100;
            if (Math.abs(diff) >= 30) {
                rateWarn.className = 'meter-warn warn';
                rateWarn.textContent = 'Rs/litre looks unusual: Rs ' + mine.toFixed(2)
                    + ' vs a recent average of Rs ' + fleetRate.toFixed(2)
                    + ' (' + (diff > 0 ? '+' : '') + diff.toFixed(0) + '%). Check before submitting.';
                rateWarn.style.display = '';
            }
        }

        function refreshDup() {
            if (!dupWarn) { return; }
            var show = fuelToday && subHid.value && litGrp.style.display !== 'none';
            dupWarn.className = 'meter-warn warn';
            dupWarn.textContent = 'This vehicle already has a fuel entry today — '
                + 'continue if this is a genuine second fill.';
            dupWarn.style.display = show ? '' : 'none';
        }

        function pickSub(btn) {
            subBtns.forEach(function (b) { b.classList.remove('on'); });
            btn.classList.add('on');
            subHid.value = btn.getAttribute('data-sub');
            // Litres, the tank toggle and the fuel-only warnings all follow the
            // sub-category's tracks_fuel flag, never its name.
            var isFuel = btn.getAttribute('data-fuel') === '1';
            litGrp.style.display = isFuel ? '' : 'none';
            if (tankGrp) { tankGrp.style.display = isFuel ? '' : 'none'; }
            if (refuelGrp) { refuelGrp.style.display = isFuel ? '' : 'none'; }
            if (!isFuel) {
                if (litres) { litres.value = ''; }
                if (tankHid) { tankHid.value = ''; }
                // Clear the refueller too: the server forces it NULL on a
                // non-fuel row anyway, but leaving a name visible in a hidden
                // field would tell the user it was recorded when it was not.
                if (refuelSel) { refuelSel.value = ''; }
                if (rateWarn) { rateWarn.style.display = 'none'; }
            } else if (tankHid && tankHid.value === '') {
                // Re-arm the default when coming back to Fuel.
                tankHid.value = '1';
            }
            refreshCalc();
            refreshRate();
            refreshDup();
        }
        subBtns.forEach(function (b) {
            b.addEventListener('click', function () { pickSub(b); });
        });

        vehSel.addEventListener('change', loadPrev);
        meter.addEventListener('input', refreshCalc);
        if (litres) {
            litres.addEventListener('input', function () { refreshCalc(); refreshRate(); });
        }
        if (amount) {
            amount.addEventListener('input', function () { refreshCalc(); refreshRate(); });
        }

        // Show/hide the whole block with the category, and scope the
        // sub-category buttons to the chosen category.
        var vehSync = function () {
            var opt = cat.options[cat.selectedIndex];
            var on = !!(opt && opt.getAttribute('data-needs-vehicle') === '1');
            vehGrp.style.display = on ? '' : 'none';
            vehSel.required = on;
            if (!on) {
                vehSel.value = ''; subHid.value = ''; meter.value = '';
                if (litres) { litres.value = ''; }
                if (tankHid) { tankHid.value = ''; }
                if (refuelSel) { refuelSel.value = ''; }
                subBtns.forEach(function (b) { b.classList.remove('on'); });
                litGrp.style.display = 'none';
                if (tankGrp) { tankGrp.style.display = 'none'; }
                if (refuelGrp) { refuelGrp.style.display = 'none'; }
                calc.style.display = 'none';
                mWarn.style.display = 'none';
                if (rateWarn) { rateWarn.style.display = 'none'; }
                if (dupWarn) { dupWarn.style.display = 'none'; }
                return;
            }
            // Only this category's sub-categories are offered; default to the
            // first (Fuel) so the common case is one click shorter.
            var catId = opt.value, first = null;
            subBtns.forEach(function (b) {
                var mine = b.getAttribute('data-cat') === catId;
                b.style.display = mine ? '' : 'none';
                if (mine && !first) { first = b; }
            });
            if (first && !subHid.value) { pickSub(first); }
            loadPrev();
        };
        cat.addEventListener('change', vehSync);
        vehSync();
    }
})();
</script>
<?php if (!$isAdmin): ?>
<script>
// Over-limit awareness: as the receptionist picks a category + amount, show a
// live warning if the posting would break the per-category or overall shift
// limit. On submit, if over-limit, confirm before posting (it still posts, but
// goes to admin/manager approval). Mirrors the server-side check exactly.
(function () {
    var cat = document.getElementById('expCategory');
    var amt = document.getElementById('expAmount');
    var warn = document.getElementById('overWarn');
    var form = document.getElementById('expForm');
    if (!cat || !amt || !warn || !form) return;

    function money(n) { return 'Rs ' + Math.round(n).toLocaleString('en-US'); }

    // Returns array of breach messages (empty = within limits).
    function breaches() {
        var amount = parseFloat(amt.value || '0');
        var out = [];
        if (!(amount > 0)) return out;
        var opt = cat.options[cat.selectedIndex];
        if (opt && opt.value) {
            var catLimit = parseFloat(opt.getAttribute('data-cat-limit') || '0');
            var catSpent = parseFloat(opt.getAttribute('data-cat-spent') || '0');
            if (catLimit > 0 && catSpent + amount > catLimit) {
                out.push('the "' + opt.getAttribute('data-cat-name') + '" limit of ' + money(catLimit)
                    + ' (over by ' + money(catSpent + amount - catLimit) + ')');
            }
        }
        var total = parseFloat(cat.getAttribute('data-limit-total') || '0');
        var mine = parseFloat(cat.getAttribute('data-mine-today') || '0');
        if (total > 0 && mine + amount > total) {
            out.push('your overall shift limit of ' + money(total)
                + ' (over by ' + money(mine + amount - total) + ')');
        }
        return out;
    }

    function refresh() {
        var b = breaches();
        if (b.length) {
            warn.innerHTML = '⚠️ This exceeds ' + b.join(' and ')
                + '. You can still post it — it will be sent to admin/manager for approval. Contact them for immediate sign-off.';
            warn.classList.add('show');
        } else {
            warn.classList.remove('show');
        }
    }

    cat.addEventListener('change', refresh);
    amt.addEventListener('input', refresh);

    form.addEventListener('submit', function (e) {
        var b = breaches();
        if (b.length && !window.confirm('This expense exceeds ' + b.join(' and ')
            + '.\n\nIt will be POSTED and sent to admin/manager for approval. Contact them now for immediate sign-off.\n\nPost anyway?')) {
            e.preventDefault();
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
