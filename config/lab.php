<?php
/**
 * Lab results module helpers (Metacare Diagnostics). Admin-only for now.
 * Requires sql/add_lab_module.sql. Nothing here touches clinic financials.
 */

const LAB_NAME = 'Metacare Diagnostics';
const LAB_TAGLINE = 'Laboratory Services';
const LAB_MAX_PDF = 10485760; // 10 MB

function lab_h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/** True when $value is numeric, $range is "a-b" (commas allowed), and value falls outside it.
 *  Free-text ranges ("Negative: 0 - 5 | Positive: >5") never flag automatically. */
function lab_out_of_range(string $value, string $range): bool
{
    $v = str_replace(',', '', trim($value));
    $r = str_replace(',', '', trim($range));
    if ($v === '' || !is_numeric($v)) { return false; }
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)$/', $r, $m)) { return false; }
    return (float) $v < (float) $m[1] || (float) $v > (float) $m[2];
}

/** Display a plain integer result with thousands separators (282000 -> 282,000), else as typed. */
function lab_fmt_value(string $v): string
{
    return preg_match('/^\d{5,}$/', $v) ? number_format((int) $v) : $v;
}

/** Patient block for an order row joined with patients (p_* columns): name, mrn, "7 yr(s) / Male", phone. */
function lab_patient_block(array $o): array
{
    if (!empty($o['patient_id'])) {
        $age = '';
        if (!empty($o['p_dob'])) {
            $d = (new DateTime($o['p_dob']))->diff(new DateTime('today'));
            $age = $d->y >= 1 ? $d->y . ' yr(s)' : ($d->m >= 1 ? $d->m . ' month(s)' : $d->d . ' day(s)');
        } elseif (!empty($o['p_age'])) {
            $age = (int) $o['p_age'] . ' yr(s)';
        }
        $g = ucfirst(strtolower((string) ($o['p_gender'] ?? '')));
        return ['name' => $o['p_name'], 'mrn' => $o['p_mrn'] ?? '', 'agegender' => trim($age . ($age && $g ? ' / ' : '') . $g), 'phone' => $o['p_phone'] ?? ''];
    }
    $g = (string) ($o['walkin_gender'] ?? '');
    $a = (string) ($o['walkin_age'] ?? '');
    return ['name' => $o['walkin_name'], 'mrn' => '', 'agegender' => trim($a . ($a && $g ? ' / ' : '') . $g), 'phone' => $o['walkin_phone'] ?? ''];
}

/** Order + joined patient columns, or null. */
function lab_load_order(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT o.*, p.name p_name, p.mrn p_mrn, p.dob p_dob, p.approx_age p_age, p.gender p_gender, p.phone p_phone
                           FROM lab_orders o LEFT JOIN patients p ON p.id = o.patient_id WHERE o.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function lab_upload_dir(): string
{
    $dir = __DIR__ . '/../uploads/lab';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) { @file_put_contents($ht, "Require all denied\n"); }
    return $dir;
}

function lab_csrf(): string
{
    if (empty($_SESSION['lab_csrf'])) { $_SESSION['lab_csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['lab_csrf'];
}
function lab_csrf_ok($t): bool
{
    return is_string($t) && !empty($_SESSION['lab_csrf']) && hash_equals($_SESSION['lab_csrf'], $t);
}
