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

// Yearly expense voucher number, e.g. "EXP-2026-0014". Same race-safe
// atomic-upsert + LAST_INSERT_ID() pattern as refund numbers (config/billing.php).
function generate_expense_number(PDO $pdo): string {
    $year = (int) date('Y');
    $pdo->prepare('
        INSERT INTO expense_sequences (sequence_year, last_sequence)
        VALUES (?, 1)
        ON DUPLICATE KEY UPDATE last_sequence = LAST_INSERT_ID(last_sequence) + 1
    ')->execute([$year]);
    $lastId = (int) $pdo->lastInsertId();
    $seq = $lastId > 0 ? $lastId : 1;
    return 'EXP-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

// ---- Post an expense ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_expense') {
    $categoryId  = (int) ($_POST['category_id'] ?? 0);
    $amount      = round((float) ($_POST['amount'] ?? 0), 2);
    $description = trim($_POST['description'] ?? '');
    $paidTo      = trim($_POST['paid_to'] ?? '');

    // Expenses come out of the counted drawer — a closed day takes no more.
    $dayLock = require_day_open($pdo);

    if ($dayLock) {
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

            if (!$isAdmin) {
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
                        $remaining = max(0, $catLimit - $catSpent);
                        throw new RuntimeException(sprintf(
                            'This would exceed the "%s" shift limit of Rs %s — Rs %s already spent today, Rs %s remaining. Ask admin if more is needed.',
                            $category['name'], number_format($catLimit), number_format($catSpent), number_format($remaining)
                        ));
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
                        $remaining = max(0, $shiftLimitTotal - $mySpent);
                        throw new RuntimeException(sprintf(
                            'This would exceed your overall shift limit of Rs %s — you have posted Rs %s today, Rs %s remaining. Ask admin if more is needed.',
                            number_format($shiftLimitTotal), number_format($mySpent), number_format($remaining)
                        ));
                    }
                }
            }

            // Admins own the limits AND are approvers — their own postings are
            // auto-approved (nobody to email). Everyone else starts PENDING and
            // gets a 60-minute magic link out to the admins + managers.
            $status = $isAdmin ? 'APPROVED' : 'PENDING';
            $expenseNumber = generate_expense_number($pdo);
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
            $expenseId = (int) $pdo->lastInsertId();

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
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$userId, 'expense_posted', $auditNote]);

            $pdo->commit();

            // Fire the approval email AFTER commit — best-effort, never blocks.
            if (!$isAdmin && $rawToken !== '') {
                notify_expense_posted($pdo, $expenseId, $rawToken);
            }

            // PRG so a refresh can't double-post the same expense.
            header('Location: expenses.php?posted=' . urlencode($expenseNumber));
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
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$userId, 'expense_voided', "Voided expense #$id — $reason"]);
            $success = 'Expense voided. The voucher number is retained for the record.';
        }
    } else {
        $error = 'A void needs a reason.';
    }
}

// ---- Approve / reject a pending expense (in-app; approvers only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['approve_expense', 'reject_expense'], true)) {
    if (!$canApprove) {
        http_response_code(403);
        exit('You do not have permission to approve expenses.');
    }
    $id       = (int) ($_POST['expense_id'] ?? 0);
    $approve  = ($_POST['action'] === 'approve_expense');
    $reason   = trim($_POST['reject_reason'] ?? '');
    $result   = decide_expense($pdo, $id, $approve, $userId, $reason);
    if ($result['ok']) {
        notify_expense_decided($pdo, $id);   // best-effort, after commit
        $success = $result['message'];
    } else {
        $error = $result['message'];
    }
}

if (isset($_GET['posted'])) {
    $success = 'Expense ' . htmlspecialchars($_GET['posted']) . ' posted — take the cash from the counter and keep the receipt.'
        . ($isAdmin ? '' : ' It is now awaiting a manager\'s approval; you will see the status update here.');
}

// ---- Data for the page ----
$categories = $pdo->query('SELECT id, name, shift_limit FROM expense_categories WHERE is_active = 1 ORDER BY name')->fetchAll();

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

$listStmt = $pdo->prepare('
    SELECT e.*, ec.name AS category_name, u.name AS posted_by_name,
           v.name AS voided_by_name, a.name AS approved_by_name
    FROM expenses e
    JOIN expense_categories ec ON ec.id = e.category_id
    JOIN users u ON u.id = e.posted_by_id
    LEFT JOIN users v ON v.id = e.voided_by_id
    LEFT JOIN users a ON a.id = e.approved_by_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY e.created_at DESC, e.id DESC
    LIMIT 300
');
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

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
.st-approved { color: #0E5456; background: rgba(26,127,126,.11); border: 1px solid rgba(26,127,126,.28); }
.st-rejected { color: var(--red-text, #b3261e); background: rgba(225,29,72,.09); border: 1px solid rgba(225,29,72,.24); }
.link-btn { background: none; border: none; color: var(--primary); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 0; }
.link-btn.warn { color: var(--red-text); }
.total-strip { display: flex; gap: 18px; flex-wrap: wrap; margin-bottom: 14px; }
.total-chip { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 8px 14px; }
.total-chip strong { color: var(--text); font-variant-numeric: tabular-nums; }
.muted-note { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'expenses';
require __DIR__ . '/partials/sidebar.php';
if (!$isAdmin) {
    $qhActive = 'expenses';
    $qhBrand = false; // the sidebar already carries the HIMS mark
    require __DIR__ . '/partials/quick_header.php';
}
?>
        <?php if ($isAdmin): ?>
        <header class="header" style="height:72px;position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;padding:0 32px;background:rgba(255,255,255,.80);backdrop-filter:blur(18px);border-bottom:1px solid var(--border);">
            <div class="page-title" style="font-size:16px;">Expenses</div>
            <div style="display:flex;align-items:center;gap:18px;margin-left:auto;">
                <span style="font-size:13px;color:var(--text-secondary);white-space:nowrap;"><?= date('D, d/m/Y') ?></span>
                <a style="font-size:13px;color:var(--text-secondary);font-weight:500;" href="logout.php">Logout</a>
            </div>
        </header>
        <?php endif; ?>

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

            <div class="exp-grid">
                <!-- Post an expense -->
                <div class="card">
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

                    <form method="POST" action="expenses.php">
                        <input type="hidden" name="action" value="post_expense">
                        <div class="f-group">
                            <label>Category</label>
                            <select name="category_id" required>
                                <option value="">Select a category&hellip;</option>
                                <?php foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?><?= (float) $c['shift_limit'] > 0 ? ' — limit Rs ' . number_format((float) $c['shift_limit']) . '/shift' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="f-group">
                            <label>Amount (Rs)</label>
                            <input type="number" name="amount" step="0.01" min="1" placeholder="0" required>
                        </div>
                        <div class="f-group">
                            <label>What was it for?</label>
                            <textarea name="description" maxlength="255" placeholder="e.g. Printer cartridge for reception" required></textarea>
                        </div>
                        <div class="f-group">
                            <label>Paid to <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
                            <input type="text" name="paid_to" maxlength="120" placeholder="Vendor / rider / staff name">
                        </div>
                        <button type="submit" class="btn" style="width:100%;">Post Expense</button>
                        <div class="muted-note">Keep the receipt with the counter cash for the shift tally.</div>
                    </form>
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

                    <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
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
                            <?php $emptyCols = ($isAdmin ? 8 : 7) + (($isAdmin || $canApprove) ? 1 : 0); ?>
                            <tr><td colspan="<?= $emptyCols ?>" class="muted" style="padding:20px 10px;">No expenses<?= $isAdmin ? ' in this range' : ' posted this shift yet' ?>.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $r): ?>
                            <?php $voided = $r['voided_at'] !== null; ?>
                            <tr class="<?= $voided ? 'row-voided' : '' ?>">
                                <td>
                                    <span class="exp-no"><?= htmlspecialchars($r['expense_number']) ?></span>
                                    <?php if ($voided): ?><br><span class="void-chip" title="<?= htmlspecialchars('By ' . ($r['voided_by_name'] ?? '') . ': ' . ($r['void_reason'] ?? '')) ?>">VOID</span><?php endif; ?>
                                </td>
                                <?php if ($isAdmin): ?><td style="white-space:nowrap;"><?= htmlspecialchars(date('d/m/Y', strtotime($r['expense_date']))) ?></td><?php endif; ?>
                                <td><?= htmlspecialchars($r['category_name']) ?></td>
                                <td><?= htmlspecialchars($r['description']) ?></td>
                                <td><?= htmlspecialchars($r['paid_to'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['posted_by_name']) ?></td>
                                <td style="text-align:right;"><span class="exp-amt">Rs <?= number_format((float) $r['amount'], 2) ?></span></td>
                                <td>
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
                                    ?>
                                </td>
                                <?php if ($isAdmin || $canApprove): ?>
                                <td style="white-space:nowrap;">
                                    <?php if (!$voided && $canApprove && $st === 'PENDING'): ?>
                                    <form method="POST" action="expenses.php" style="margin:0 0 4px;">
                                        <input type="hidden" name="action" value="approve_expense">
                                        <input type="hidden" name="expense_id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="link-btn">Approve</button>
                                    </form>
                                    <form method="POST" action="expenses.php" style="margin:0 0 4px;"
                                          onsubmit="var r=prompt('Reason for rejecting <?= htmlspecialchars($r['expense_number']) ?> (cash to be returned):');if(!r){return false;}this.reject_reason.value=r;return true;">
                                        <input type="hidden" name="action" value="reject_expense">
                                        <input type="hidden" name="expense_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="reject_reason" value="">
                                        <button type="submit" class="link-btn warn">Reject</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!$voided && $isAdmin): ?>
                                    <form method="POST" action="expenses.php" style="margin:0;"
                                          onsubmit="var r=prompt('Reason for voiding <?= htmlspecialchars($r['expense_number']) ?>:');if(!r){return false;}this.void_reason.value=r;return true;">
                                        <input type="hidden" name="action" value="void_expense">
                                        <input type="hidden" name="expense_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="void_reason" value="">
                                        <button type="submit" class="link-btn warn">Void</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
