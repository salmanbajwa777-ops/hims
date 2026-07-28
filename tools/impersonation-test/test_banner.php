<?php
// Renders partials/impersonation_banner.php in both states.
$HIMS = dirname(__DIR__, 2);
require_once "$HIMS/config/impersonation.php";

echo "=== NOT impersonating (must output nothing) ===\n";
$_SESSION = ['user_id' => 1, 'base_role' => 'ADMIN'];
ob_start(); require "$HIMS/partials/impersonation_banner.php"; $out = ob_get_clean();
echo strlen(trim($out)) === 0 ? "  ok  empty\n" : "FAIL  emitted " . strlen($out) . " bytes\n";

echo "\n=== impersonating (must render the bar) ===\n";
$_SESSION = [
    'user_id' => 2, 'base_role' => 'STAFF',
    'imp_admin_id' => 1, 'imp_admin_name' => 'SALMAN', 'imp_admin_role' => 'ADMIN',
    'imp_target_name' => 'AYESHA <script>', 'imp_started_at' => time(),
];
ob_start(); require "$HIMS/partials/impersonation_banner.php"; $out = ob_get_clean();

$checks = [
    'names the target'        => str_contains($out, 'AYESHA'),
    'shows their role'        => str_contains($out, 'STAFF'),
    'names the acting admin'  => str_contains($out, 'SALMAN'),
    'has a stop form'         => str_contains($out, 'action="/impersonate.php"'),
    'posts action=stop'       => str_contains($out, 'value="stop"'),
    'escapes HTML in names'   => str_contains($out, '&lt;script&gt;') && !str_contains($out, '<script>'),
    'hidden when printing'    => str_contains($out, '@media print'),
    'offsets the body'        => str_contains($out, 'padding-top'),
];
$fail = 0;
foreach ($checks as $label => $ok) { printf("%s %s\n", $ok ? '  ok  ' : 'FAIL  ', $label); $ok || $fail++; }
echo "\n" . ($fail ? "FAILED $fail\n" : "banner ok\n");
exit($fail ? 1 : 0);
