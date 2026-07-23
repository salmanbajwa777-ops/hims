<?php
// Google Sheet invoice log — one sheet per year, one row per document, matching
// the column layout the clinic used before HMIS existed.
//
// Three document types share the one sheet, told apart by the "Receipt" column:
//   Invoice    — a consultation bill (patients.php registration, or checkout.php)
//   Admission  — an ER admission, pushed at admit time with the money columns blank
//   Discharge  — the admission bill, pushed when it is paid
//
// CONTRACT, same as config/notify.php: every push runs AFTER $pdo->commit() and is
// wrapped so it can never throw into the page. An unreachable sheet must never cost
// the clinic a saved payment. Failed pushes land in sheet_sync_log with status
// 'failed' and are re-sent by cron/sheet_retry.php, so the sheet self-heals.

require_once __DIR__ . '/billing.php';   // admission_billed_hours()

/**
 * Settings from config/sheets_config.php, or null when that file is absent
 * (every push then silently no-ops, exactly like mail_config()).
 *
 * NOTE the filename: this file is the library, so the secrets cannot also live at
 * config/sheets.php. config/sheets_config.php is the gitignored counterpart of
 * config/mail.php, created on the server from sheets.example.php.
 */
function sheets_config(): ?array {
    static $cfg = null;
    static $loaded = false;
    if ($loaded) { return $cfg; }
    $loaded = true;

    $path = __DIR__ . '/sheets_config.php';
    if (!is_file($path)) { $cfg = null; return $cfg; }

    $loadedCfg = require $path;
    $cfg = is_array($loadedCfg) ? $loadedCfg : null;
    return $cfg;
}

/** True when a real endpoint is configured; false silences every push. */
function sheets_enabled(): bool {
    $c = sheets_config();
    return $c
        && !empty($c['enabled'])
        && !empty($c['webapp_url'])
        && strpos($c['webapp_url'], 'PASTE_DEPLOYMENT_ID') === false
        && !empty($c['shared_secret'])
        && $c['shared_secret'] !== 'CHANGE_ME';
}

// ============================================================================
// Column order. This array IS the sheet's header row — index 0 is column A.
// It reproduces the pre-HMIS sheet exactly, with Invoice Number inserted after
// Receipt (the old system never recorded one). Changing the ORDER here silently
// misaligns every future row against the historical sheets: don't reorder, only
// append. The Apps Script writes values positionally and only uses this list to
// create the header row on a brand-new yearly tab.
// ============================================================================
function sheet_columns(): array {
    return [
        'Date', 'Receipt', 'Invoice Number', 'RNumber', 'Patient Name',
        'Father/Husband Name', 'Date Of Birth', 'Email', 'Phone/Mobile',
        'Checkup Type', 'Doctor', 'Refund', 'City', 'Area/Address', 'Other Area',
        'Payment Method', 'Other Procedures', 'IV Canula', 'IM Injection',
        'IV Injection', 'Suppository Insertion', 'Rectal Enema', 'Bed Charges',
        'Number of IV Canula', 'Number of IV Injections', 'Number of IM Injections',
        // The old sheet distinguishes these two COUNT columns from the amount
        // columns of the same name (17/18 above) only by a stray backtick and a
        // trailing space in its header text. Reproduced verbatim: the Apps Script
        // matches on the sheet's own header, so "tidying" either string here would
        // make HMIS append a duplicate column instead of filling the existing one.
        'Suppository Insertion`', 'Rectal Enema ', 'Number of Days', 'Disposables',
        'Nurse', 'TotalAmount', 'Discount', 'Net Total', 'Booked By',
        'user-ip', 'user-id', 'user-name', 'user-emal', 'browser',
        'browser-client', 'entry-id', 'created-at',
    ];
}

// The five services that own their own amount + count columns, carried over from
// the old sheet. Keys are the canonical names in er_services_master; matching is
// case-insensitive and ignores spaces so "IV Cannula" still lands in "IV Canula".
// ANY service not in this list is written into "Other Procedures" instead of being
// dropped — admin can add services freely and the money always shows up somewhere.
function sheet_service_columns(): array {
    return [
        'iv canula'             => ['amount' => 'IV Canula',             'count' => 'Number of IV Canula'],
        'iv cannula'            => ['amount' => 'IV Canula',             'count' => 'Number of IV Canula'],
        'im injection'          => ['amount' => 'IM Injection',          'count' => 'Number of IM Injections'],
        'iv injection'          => ['amount' => 'IV Injection',          'count' => 'Number of IV Injections'],
        'suppository insertion' => ['amount' => 'Suppository Insertion', 'count' => 'Suppository Insertion`'],
        'rectal enema'          => ['amount' => 'Rectal Enema',          'count' => 'Rectal Enema '],
        'disposables'           => ['amount' => 'Disposables',           'count' => null],
    ];
}

/** Normalises a service name for matching: lowercase, collapsed whitespace. */
function sheet_service_key(string $name): string {
    return trim(preg_replace('/\s+/', ' ', mb_strtolower($name)));
}

/**
 * Is patients.email present? It arrives with sql/add_sheet_sync.sql, and code
 * auto-deploys on push while migrations are run by hand — so there is a window
 * where selecting it would throw and cost us the whole row. Probed once per
 * request; when absent the Email column just comes through empty.
 */
function sheet_has_patient_email(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) { return $has; }
    try {
        $pdo->query('SELECT email FROM patients LIMIT 0');
        $has = true;
    } catch (PDOException $e) {
        $has = false;
    }
    return $has;
}

/** `p.email` when the column exists, else a NULL placeholder of the same name. */
function sheet_email_select(PDO $pdo): string {
    return sheet_has_patient_email($pdo) ? 'p.email' : 'NULL AS email';
}

// ============================================================================
// Row building
// ============================================================================

/**
 * The who/where audit tail (user-ip … created-at) the old system recorded.
 * Captured from the live request, so it must be built during the web request
 * that caused the document — not later in the retry cron.
 */
function sheet_actor_fields(PDO $pdo, ?int $userId, int $entryId): array {
    $name = null; $email = null;
    if ($userId) {
        try {
            $s = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
            $s->execute([$userId]);
            if ($r = $s->fetch()) { $name = $r['name']; $email = $r['email']; }
        } catch (Throwable $e) { /* audit tail is best-effort */ }
    }

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    // Deliberately coarse — the old sheet stored "Chrome" / "Windows", not the
    // full UA string. Order matters: Edge and Opera both claim "Chrome".
    $browser = 'Unknown';
    foreach ([
        'Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome',
        'Safari' => 'Safari', 'Firefox' => 'Firefox', 'MSIE' => 'IE', 'Trident' => 'IE',
    ] as $needle => $label) {
        if (stripos($ua, $needle) !== false) { $browser = $label; break; }
    }
    $platform = 'Unknown';
    foreach ([
        'Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS',
        'iPad' => 'iOS', 'Mac OS' => 'macOS', 'Linux' => 'Linux',
    ] as $needle => $label) {
        if (stripos($ua, $needle) !== false) { $platform = $label; break; }
    }

    // Behind Hostinger's proxy the real client sits in X-Forwarded-For (first hop).
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }

    return [
        'user-ip'        => $ip,
        'user-id'        => $userId ?: '',
        'user-name'      => $name ?: '',
        'user-emal'      => $email ?: '',   // header misspelling is the old sheet's — kept to match
        'browser'        => $browser,
        'browser-client' => $platform,
        'entry-id'       => $entryId,
        'created-at'     => date('Y-m-d H:i:s'),
    ];
}

/**
 * Splits an admission's logged services into the five legacy columns, with
 * everything else summarised into "Other Procedures" so nothing is lost.
 * Returns a partial row keyed by column name.
 */
function sheet_service_breakdown(PDO $pdo, int $admissionId): array {
    $row = [];
    $other = [];
    try {
        $s = $pdo->prepare('
            SELECT service_name, SUM(quantity) AS qty, SUM(calculated_charge) AS amt
            FROM admission_services
            WHERE admission_id = ? AND is_billable = 1
            GROUP BY service_name
            ORDER BY service_name
        ');
        $s->execute([$admissionId]);
        $map = sheet_service_columns();
        foreach ($s->fetchAll() as $svc) {
            $key = sheet_service_key((string) $svc['service_name']);
            $qty = (float) $svc['qty'];
            $amt = (float) $svc['amt'];
            if (isset($map[$key])) {
                $cols = $map[$key];
                // Aliases (IV Canula / IV Cannula) can both hit the same column.
                $row[$cols['amount']] = (float) ($row[$cols['amount']] ?? 0) + $amt;
                if ($cols['count']) {
                    $row[$cols['count']] = (float) ($row[$cols['count']] ?? 0) + $qty;
                }
            } else {
                $other[] = $svc['service_name'] . ' x' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')
                         . ' = ' . number_format($amt, 2, '.', '');
            }
        }
    } catch (Throwable $e) { /* a missing services table must not kill the row */ }

    if ($other) { $row['Other Procedures'] = implode('; ', $other); }
    return $row;
}

// ============================================================================
// Document → row builders. Each returns an associative array keyed by column
// name; sheet_push() fills the gaps and orders it.
// ============================================================================

/** Consultation invoice row (Receipt = "Invoice"). */
function sheet_row_for_bill(PDO $pdo, int $billId, ?int $actorId): ?array {
    $stmt = $pdo->prepare('
        SELECT b.id, b.invoice_number, b.grand_total, b.subtotal, b.status,
               b.payment_method, b.paid_at, b.created_at, b.created_by_id,
               v.id AS visit_id, v.fee, v.discount_pct, v.visit_date,
               p.mrn, p.name AS patient_name, p.father_name, p.dob, p.phone,
               ' . sheet_email_select($pdo) . ',
               ct.label AS consult_type, du.name AS doctor_name,
               c.name AS city_name, a.name AS area_name,
               cu.name AS booked_by
        FROM bills b
        JOIN visits v ON v.id = b.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN doctor_consult_types ct ON ct.id = v.doctor_consult_type_id
        LEFT JOIN users du ON du.id = v.doctor_id
        LEFT JOIN cities c ON c.id = p.city_id
        LEFT JOIN areas a ON a.id = p.area_id
        LEFT JOIN users cu ON cu.id = b.created_by_id
        WHERE b.id = ?
    ');
    $stmt->execute([$billId]);
    $r = $stmt->fetch();
    if (!$r) { return null; }

    // Gross fee vs net: bill_items already carry the discounted rate, so the gross
    // has to come from visits.fee — the same reason the printed slip reads it there
    // rather than from the line items.
    $gross    = (float) $r['fee'];
    $net      = (float) $r['grand_total'];
    $discount = round($gross - $net, 2);

    $refunded = 0.0;
    try { $refunded = refunded_total($pdo, $billId); } catch (Throwable $e) { /* pre-migration */ }

    $payLabels = ['cash' => 'Cash', 'card' => 'Online-Card', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];
    $payment = $r['status'] === 'waived'
        ? 'Waived (free visit)'
        : ($payLabels[$r['payment_method']] ?? '');

    $when = $r['paid_at'] ?: $r['created_at'];

    return array_merge([
        'Date'                => date('d/m/Y', strtotime($when)),
        'Receipt'             => 'Invoice',
        'Invoice Number'      => $r['invoice_number'],
        'RNumber'             => $r['mrn'],
        'Patient Name'        => $r['patient_name'],
        'Father/Husband Name' => $r['father_name'],
        'Date Of Birth'       => $r['dob'] ? date('d/m/Y', strtotime($r['dob'])) : '',
        'Email'               => $r['email'],
        'Phone/Mobile'        => $r['phone'],
        'Checkup Type'        => $r['consult_type'],
        'Doctor'              => $r['doctor_name'],
        'Refund'              => $refunded > 0 ? number_format($refunded, 2, '.', '') : '',
        'City'                => $r['city_name'],
        'Area/Address'        => $r['area_name'],
        'Payment Method'      => $payment,
        'TotalAmount'         => number_format($gross, 2, '.', ''),
        'Discount'            => $discount > 0 ? number_format($discount, 2, '.', '') : '',
        'Net Total'           => number_format($net, 2, '.', ''),
        'Booked By'           => $r['booked_by'],
    ], sheet_actor_fields($pdo, $actorId ?: (int) $r['created_by_id'], $billId));
}

/**
 * Admission row (Receipt = "Admission"), pushed the moment a patient is admitted.
 * The money columns are deliberately blank — the admission bill does not exist
 * until discharge. The Discharge row that follows carries the figures.
 */
function sheet_row_for_admission(PDO $pdo, int $admissionId, ?int $actorId): ?array {
    $stmt = $pdo->prepare('
        SELECT ad.id, ad.admitted_at, ad.admission_type, ad.admitted_by_id,
               ad.admitting_doctor_manual,
               p.mrn, p.name AS patient_name, p.father_name, p.dob, p.phone,
               ' . sheet_email_select($pdo) . ',
               ct.label AS consult_type,
               COALESCE(du.name, ad.admitting_doctor_manual) AS doctor_name,
               c.name AS city_name, a.name AS area_name,
               nu.name AS nurse_name, abu.name AS booked_by
        FROM admissions ad
        JOIN visits v ON v.id = ad.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN doctor_consult_types ct ON ct.id = v.doctor_consult_type_id
        LEFT JOIN users du ON du.id = COALESCE(ad.admitting_doctor_id, v.doctor_id)
        LEFT JOIN cities c ON c.id = p.city_id
        LEFT JOIN areas a ON a.id = p.area_id
        LEFT JOIN users nu ON nu.id = ad.assigned_nurse_id
        LEFT JOIN users abu ON abu.id = ad.admitted_by_id
        WHERE ad.id = ?
    ');
    $stmt->execute([$admissionId]);
    $r = $stmt->fetch();
    if (!$r) { return null; }

    return array_merge([
        'Date'                => date('d/m/Y', strtotime($r['admitted_at'])),
        'Receipt'             => 'Admission',
        'Invoice Number'      => '',           // no bill yet — filled by the Discharge row
        'RNumber'             => $r['mrn'],
        'Patient Name'        => $r['patient_name'],
        'Father/Husband Name' => $r['father_name'],
        'Date Of Birth'       => $r['dob'] ? date('d/m/Y', strtotime($r['dob'])) : '',
        'Email'               => $r['email'],
        'Phone/Mobile'        => $r['phone'],
        'Checkup Type'        => $r['consult_type'],
        'Doctor'              => $r['doctor_name'],
        'City'                => $r['city_name'],
        'Area/Address'        => $r['area_name'],
        'Other Procedures'    => ucfirst(strtolower(str_replace('_', ' ', (string) $r['admission_type']))) . ' admission',
        'Nurse'               => $r['nurse_name'],
        'Booked By'           => $r['booked_by'],
    ], sheet_actor_fields($pdo, $actorId ?: (int) $r['admitted_by_id'], $admissionId));
}

/** Discharge row (Receipt = "Discharge") — the admission bill, with services split out. */
function sheet_row_for_admission_bill(PDO $pdo, int $admissionBillId, ?int $actorId): ?array {
    $stmt = $pdo->prepare('
        SELECT ab.id, ab.invoice_number, ab.subtotal, ab.grand_total, ab.paid_amount,
               ab.write_off_amount, ab.payment_method, ab.paid_at, ab.created_at,
               ab.finalized_by_id, ab.created_by_id,
               ad.id AS admission_id, ad.admitted_at, ad.discharged_at,
               p.mrn, p.name AS patient_name, p.father_name, p.dob, p.phone,
               ' . sheet_email_select($pdo) . ',
               ct.label AS consult_type,
               COALESCE(du.name, ad.admitting_doctor_manual) AS doctor_name,
               c.name AS city_name, a.name AS area_name,
               nu.name AS nurse_name, fu.name AS booked_by
        FROM admission_bills ab
        JOIN admissions ad ON ad.id = ab.admission_id
        JOIN visits v ON v.id = ad.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN doctor_consult_types ct ON ct.id = v.doctor_consult_type_id
        LEFT JOIN users du ON du.id = COALESCE(ad.admitting_doctor_id, v.doctor_id)
        LEFT JOIN cities c ON c.id = p.city_id
        LEFT JOIN areas a ON a.id = p.area_id
        LEFT JOIN users nu ON nu.id = ad.assigned_nurse_id
        LEFT JOIN users fu ON fu.id = COALESCE(ab.finalized_by_id, ab.created_by_id)
        WHERE ab.id = ?
    ');
    $stmt->execute([$admissionBillId]);
    $r = $stmt->fetch();
    if (!$r) { return null; }

    // Bed charges = the STAY line; the stay hours are what the old sheet called
    // "Number of Days" (it recorded whatever unit the stay was billed in).
    $bed = 0.0; $stayQty = 0.0;
    try {
        $s = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) AS amt, COALESCE(SUM(quantity), 0) AS qty
            FROM admission_bill_items WHERE admission_bill_id = ? AND item_kind = 'STAY'
        ");
        $s->execute([$admissionBillId]);
        if ($x = $s->fetch()) { $bed = (float) $x['amt']; $stayQty = (float) $x['qty']; }
    } catch (Throwable $e) { /* best-effort */ }

    $gross    = (float) $r['subtotal'];
    $net      = (float) $r['grand_total'];
    $discount = round($gross - $net, 2);

    $payLabels = ['cash' => 'Cash', 'card' => 'Online-Card', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];
    $payment = $payLabels[$r['payment_method']] ?? '';
    if ((float) $r['write_off_amount'] > 0) {
        $payment = trim($payment . ' (written off Rs ' . number_format((float) $r['write_off_amount'], 2) . ')');
    }

    $when = $r['paid_at'] ?: ($r['discharged_at'] ?: $r['created_at']);

    $base = [
        'Date'                => date('d/m/Y', strtotime($when)),
        'Receipt'             => 'Discharge',
        'Invoice Number'      => $r['invoice_number'],
        'RNumber'             => $r['mrn'],
        'Patient Name'        => $r['patient_name'],
        'Father/Husband Name' => $r['father_name'],
        'Date Of Birth'       => $r['dob'] ? date('d/m/Y', strtotime($r['dob'])) : '',
        'Email'               => $r['email'],
        'Phone/Mobile'        => $r['phone'],
        'Checkup Type'        => $r['consult_type'],
        'Doctor'              => $r['doctor_name'],
        'City'                => $r['city_name'],
        'Area/Address'        => $r['area_name'],
        'Payment Method'      => $payment,
        'Bed Charges'         => $bed > 0 ? number_format($bed, 2, '.', '') : '',
        'Number of Days'      => $stayQty > 0 ? rtrim(rtrim(number_format($stayQty, 2, '.', ''), '0'), '.') : '',
        'Nurse'               => $r['nurse_name'],
        'TotalAmount'         => number_format($gross, 2, '.', ''),
        'Discount'            => $discount > 0 ? number_format($discount, 2, '.', '') : '',
        'Net Total'           => number_format($net, 2, '.', ''),
        'Booked By'           => $r['booked_by'],
    ];

    // Services fill their own columns where one exists, else "Other Procedures".
    $services = sheet_service_breakdown($pdo, (int) $r['admission_id']);
    foreach ($services as $col => $val) {
        $base[$col] = is_float($val) ? number_format($val, 2, '.', '') : $val;
    }

    return array_merge($base, sheet_actor_fields(
        $pdo, $actorId ?: (int) ($r['finalized_by_id'] ?: $r['created_by_id']), $admissionBillId
    ));
}

// ============================================================================
// Delivery
// ============================================================================

/**
 * POSTs one row to the Apps Script web app. Returns [ok, error]. Never throws.
 *
 * cURL follows redirects on purpose: a Google web-app /exec URL answers with a
 * 302 to script.googleusercontent.com and the real body is only at the target.
 * Without FOLLOWLOCATION every push would look like it failed.
 */
function sheet_send(array $payload): array {
    $cfg = sheets_config();
    if (!$cfg) { return [false, 'sheets config missing']; }

    $body = json_encode($payload);
    if ($body === false) { return [false, 'payload encode failed']; }

    if (!function_exists('curl_init')) { return [false, 'cURL unavailable']; }

    $ch = curl_init($cfg['webapp_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => (int) ($cfg['timeout'] ?? 10),
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false)  { return [false, 'curl: ' . $err]; }
    if ($code !== 200)    { return [false, 'HTTP ' . $code . ': ' . substr((string) $resp, 0, 200)]; }

    $json = json_decode((string) $resp, true);
    if (!is_array($json) || empty($json['ok'])) {
        return [false, 'script: ' . substr((string) $resp, 0, 200)];
    }
    return [true, null];
}

/**
 * Build, log and send one document's row. THE entry point — call after commit.
 *
 * The log row is written FIRST (status 'failed'), then flipped to 'sent' on
 * success. Ordered that way on purpose: if the request dies mid-push, the row
 * is already recorded as outstanding and the retry cron picks it up. The
 * UNIQUE (doc_type, doc_ref) key means a repeat call updates the same row
 * instead of appending a duplicate to the sheet.
 */
function sheet_push(PDO $pdo, string $docType, int $docRef, ?int $actorId = null): void {
    try {
        $row = null;
        if ($docType === 'INVOICE')        { $row = sheet_row_for_bill($pdo, $docRef, $actorId); }
        elseif ($docType === 'ADMISSION')  { $row = sheet_row_for_admission($pdo, $docRef, $actorId); }
        elseif ($docType === 'DISCHARGE')  { $row = sheet_row_for_admission_bill($pdo, $docRef, $actorId); }
        if (!$row) { return; }

        // The row's own Date decides its yearly tab, so a document saved just after
        // midnight on 1 Jan still files under the year it belongs to.
        $year = (int) date('Y');
        if (!empty($row['Date']) && preg_match('#/(\d{4})$#', $row['Date'], $m)) {
            $year = (int) $m[1];
        }

        $payload = [
            'secret'  => (sheets_config()['shared_secret'] ?? ''),
            'tab'     => str_replace('{year}', (string) $year,
                            (sheets_config()['tab_pattern'] ?? 'Baby Medics {year}')),
            'columns' => sheet_columns(),
            'row'     => $row,
        ];

        $enabled = sheets_enabled();

        // A document can legitimately reach this function twice — a bill raised at
        // registration is pushed there, and checkout.php pushes again if someone
        // later settles or revisits it. The sheet APPENDS, so sending twice would
        // put two rows in the ledger for one invoice. Anything already 'sent' is
        // therefore left alone: the sheet is an append-only log of what happened,
        // not a mirror that tracks later edits.
        $already = false;
        try {
            $chk = $pdo->prepare('SELECT status FROM sheet_sync_log WHERE doc_type = ? AND doc_ref = ?');
            $chk->execute([$docType, $docRef]);
            $already = ($chk->fetchColumn() === 'sent');
        } catch (Throwable $e) {
            // Migration not run yet — fall through and attempt the push.
        }
        if ($already) { return; }

        // Record the attempt BEFORE making it: if the request dies mid-push the row
        // is already on file as outstanding and cron/sheet_retry.php will re-send it.
        try {
            $pdo->prepare('
                INSERT INTO sheet_sync_log (doc_type, doc_ref, invoice_number, sheet_year, payload, status, attempts)
                VALUES (?, ?, ?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE payload = VALUES(payload), sheet_year = VALUES(sheet_year),
                                        invoice_number = VALUES(invoice_number)
            ')->execute([
                $docType, $docRef, ($row['Invoice Number'] ?: null), $year,
                json_encode($payload), $enabled ? 'failed' : 'skipped',
            ]);
        } catch (Throwable $e) {
            // Migration not run yet — push anyway rather than losing the row entirely.
        }

        if (!$enabled) { return; }

        [$ok, $err] = sheet_send($payload);
        sheet_mark($pdo, $docType, $docRef, $ok, $err);
    } catch (Throwable $e) {
        // A sheet must never break a payment.
    }
}

/** Flips a log row to sent/failed and bumps the attempt counter. */
function sheet_mark(PDO $pdo, string $docType, int $docRef, bool $ok, ?string $err): void {
    try {
        $pdo->prepare('
            UPDATE sheet_sync_log
            SET status = ?, attempts = attempts + 1, last_error = ?, sent_at = ?
            WHERE doc_type = ? AND doc_ref = ?
        ')->execute([
            $ok ? 'sent' : 'failed',
            $ok ? null : substr((string) $err, 0, 500),
            $ok ? date('Y-m-d H:i:s') : null,
            $docType, $docRef,
        ]);
    } catch (Throwable $e) { /* best-effort */ }
}
