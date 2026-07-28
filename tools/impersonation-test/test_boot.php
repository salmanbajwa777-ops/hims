<?php
/**
 * Include-order smoke test: does loading config/auth.php ALONE (as every page
 * does first) put the impersonation helpers + banner in scope without fataling,
 * and WITHOUT consuming permissions.php's $pdo-guarded timezone pin?
 */
error_reporting(E_ALL); ini_set('display_errors','1');
$HIMS = dirname(__DIR__, 2);
$fail = 0;
function t($label,$ok){ global $fail; printf("%s %s\n",$ok?'  ok  ':'FAIL  ',$label); $ok||$fail++; }

// Simulate a page's very first line, with NO $pdo yet.
require_once "$HIMS/config/auth.php";

t('auth.php loads standalone', true);
t('is_impersonating() in scope', function_exists('is_impersonating'));
t('imp_audit_suffix() in scope', function_exists('imp_audit_suffix'));
t('not impersonating by default', is_impersonating() === false);
t('suffix is empty', imp_audit_suffix() === '');
// The pin must NOT have been consumed: permissions.php should still be unloaded,
// so that db.php -> permissions.php ordering still applies SET time_zone.
t('permissions.php NOT loaded early (timezone pin preserved)', !function_exists('load_permissions'));
t('audit_log() NOT yet defined (comes with permissions.php)', !function_exists('audit_log'));

// Banner renders nothing for a normal session.
$_SESSION['user_id']=1; $_SESSION['base_role']='ADMIN';
ob_start(); require "$HIMS/partials/impersonation_banner.php"; $o=ob_get_clean();
t('banner silent when not impersonating', trim($o)===''); 

echo $fail ? "\nFAILED $fail\n" : "\nboot ok\n";
exit($fail?1:0);
