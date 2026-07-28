<?php
/**
 * Runtime test for the "View as staff" feature, against a real (sqlite) DB
 * running the ACTUAL config/impersonation.php + config/permissions.php code.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

// NOTE: imp_stop()'s demoted/deactivated path calls session_destroy() only when
// session_status() is PHP_SESSION_ACTIVE, so this suite runs warning-free under
// CLI where no session exists. If a bare session_destroy() ever reappears there,
// this file will start emitting warnings — that is a real bug, not harness
// noise: a warning printed before imp_stop()'s caller sends its Location header
// would break the redirect.

$HIMS = dirname(__DIR__, 2);

/**
 * sqlite stand-in for the live MySQL handle. permissions.php pins the session
 * timezone with `SET time_zone = '+05:00'` (MySQL-only syntax), so swallow just
 * that statement — the shim, not a code change.
 */
class TestPdo extends PDO {
    public function exec(string $s): int|false {
        if (stripos(ltrim($s), 'SET time_zone') === 0) { return 0; }
        return parent::exec($s);
    }
}

$pdo = new TestPdo('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, base_role TEXT, is_active INT DEFAULT 1, must_change_password INT DEFAULT 0)");
$pdo->exec("CREATE TABLE permissions (id INTEGER PRIMARY KEY, `key` TEXT, label TEXT)");
$pdo->exec("CREATE TABLE role_permissions (base_role TEXT, permission_id INT)");
$pdo->exec("CREATE TABLE user_permission_overrides (user_id INT, permission_id INT, granted INT)");
$pdo->exec("CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, user_id INT NULL, action TEXT, details TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$pdo->exec("INSERT INTO users (id,name,base_role) VALUES (1,'SALMAN (ADMIN)','ADMIN'),(2,'AYESHA (RECEPTION)','STAFF'),(3,'DR KHAN','DOCTOR'),(4,'OTHER ADMIN','ADMIN')");
$pdo->exec("INSERT INTO permissions (id,`key`,label) VALUES
    (1,'RECEPTION_REGISTER_PATIENTS','Register patients'),
    (2,'RECEPTION_CLOSE_DAY','Close the day'),
    (3,'ADMIN_MANAGE_STAFF','Manage staff'),
    (4,'NURSING_RECORD_VITALS','Record vitals')");
// STAFF gets register + close-day; ADMIN gets manage-staff; nobody gets vitals by role.
$pdo->exec("INSERT INTO role_permissions VALUES ('STAFF',1),('STAFF',2),('ADMIN',3),('DOCTOR',4)");
// Ayesha is individually REVOKED close-day and individually GRANTED vitals.
$pdo->exec("INSERT INTO user_permission_overrides VALUES (2,2,0),(2,4,1)");

require_once "$HIMS/config/permissions.php";
require_once "$HIMS/config/impersonation.php";

$pass = 0; $fail = 0;
function check(string $label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? '  ok  ' : 'FAIL  ', $label);
    if (!$ok) {
        echo "         got:  " . var_export($got, true) . "\n";
        echo "         want: " . var_export($want, true) . "\n";
    }
}

// --- Sign in as the admin -----------------------------------------------
$_SESSION = [];
$_SESSION['user_id']     = 1;
$_SESSION['base_role']   = 'ADMIN';
$_SESSION['permissions'] = load_permissions($pdo, 1, 'ADMIN');

echo "\n== baseline (admin signed in as themselves) ==\n";
check('not impersonating', is_impersonating(), false);
check('admin has ADMIN_MANAGE_STAFF', has_permission('ADMIN_MANAGE_STAFF'), true);
check('admin lacks RECEPTION_REGISTER_PATIENTS', has_permission('RECEPTION_REGISTER_PATIENTS'), false);
check('audit suffix empty when not impersonating', imp_audit_suffix(), '');
check('real_user_id is the admin', real_user_id(), 1);

// --- Refusals -------------------------------------------------------------
echo "\n== refusals ==\n";
check('cannot view as another ADMIN', imp_start($pdo, 4), 'You cannot view the app as another administrator.');
check('  (session untouched after refusal)', $_SESSION['user_id'], 1);
check('cannot view as self', imp_start($pdo, 1), 'You are already signed in as yourself.');
check('cannot view as missing user', imp_start($pdo, 999), 'That user no longer exists.');
check('  (still not impersonating)', is_impersonating(), false);

// --- Start impersonating the receptionist --------------------------------
echo "\n== start viewing as AYESHA (STAFF) ==\n";
check('start returns no error', imp_start($pdo, 2), '');
check('is_impersonating', is_impersonating(), true);
check('session user is now Ayesha', $_SESSION['user_id'], 2);
check('session role is now STAFF', $_SESSION['base_role'], 'STAFF');
check('real_user_id still resolves to admin', real_user_id(), 1);
check('target name recorded', imp_target_name(), 'AYESHA (RECEPTION)');
check('admin name recorded', imp_admin_name(), 'SALMAN (ADMIN)');

echo "\n-- permissions are AYESHA's exactly (role defaults + her overrides) --\n";
check('HAS register-patients (STAFF role default)', has_permission('RECEPTION_REGISTER_PATIENTS'), true);
check('LACKS close-day (individually revoked from her)', has_permission('RECEPTION_CLOSE_DAY'), false);
check('HAS vitals (individually granted to her)', has_permission('NURSING_RECORD_VITALS'), true);
check('LOSES admin rights while viewing as staff', has_permission('ADMIN_MANAGE_STAFF'), false);

echo "\n-- nesting is refused --\n";
check('cannot start a second impersonation', imp_start($pdo, 3), 'Already viewing as someone else. Stop the current session first.');
check('  (still Ayesha, not Dr Khan)', $_SESSION['user_id'], 2);

echo "\n-- writes are attributed to the STAFF member, tagged with the admin --\n";
audit_log($pdo, 'bill_paid', 'Recorded payment for bill #77');
$row = $pdo->query("SELECT user_id, details FROM audit_logs WHERE action='bill_paid'")->fetch();
check('audit row carries AYESHA as the user', (int) $row['user_id'], 2);
check('details name the acting admin',
    str_contains($row['details'], '[via ADMIN SALMAN (ADMIN) #1 viewing as AYESHA (RECEPTION)]'), true);

// --- Stop -----------------------------------------------------------------
echo "\n== stop viewing ==\n";
$landing = imp_stop($pdo);
check('lands on the admin dashboard', $landing, '/dashboard.php');
check('no longer impersonating', is_impersonating(), false);
check('session user restored to admin', $_SESSION['user_id'], 1);
check('session role restored to ADMIN', $_SESSION['base_role'], 'ADMIN');
check('admin rights restored', has_permission('ADMIN_MANAGE_STAFF'), true);
check('staff rights gone again', has_permission('RECEPTION_REGISTER_PATIENTS'), false);
check('imp_admin_id cleared', isset($_SESSION['imp_admin_id']), false);
check('suffix empty again', imp_audit_suffix(), '');

echo "\n-- start/stop are themselves logged --\n";
$acts = array_column($pdo->query("SELECT action FROM audit_logs ORDER BY id")->fetchAll(), 'action');
check('audit trail order', $acts, ['impersonation_start', 'bill_paid', 'impersonation_stop']);
$startRow = $pdo->query("SELECT user_id, details FROM audit_logs WHERE action='impersonation_start'")->fetch();
check('start logged against the ADMIN', (int) $startRow['user_id'], 1);
check('start details name the target', str_contains($startRow['details'], 'AYESHA (RECEPTION) #2'), true);
$stopRow = $pdo->query("SELECT user_id, details FROM audit_logs WHERE action='impersonation_stop'")->fetch();
check('stop logged against the ADMIN', (int) $stopRow['user_id'], 1);
check('stop details not double-tagged', str_contains($stopRow['details'], '[via ADMIN'), false);

// --- stop when not impersonating is a safe no-op ---------------------------
echo "\n== stop when not impersonating ==\n";
$before = $pdo->query("SELECT COUNT(*) c FROM audit_logs")->fetch()['c'];
check('returns dashboard', imp_stop($pdo), '/dashboard.php');
check('writes no audit row', (int) $pdo->query("SELECT COUNT(*) c FROM audit_logs")->fetch()['c'], (int) $before);
check('session still the admin', $_SESSION['user_id'], 1);

// --- doctor target ---------------------------------------------------------
echo "\n== viewing as a DOCTOR ==\n";
check('start on doctor', imp_start($pdo, 3), '');
check('role is DOCTOR', $_SESSION['base_role'], 'DOCTOR');
check('has vitals via DOCTOR role', has_permission('NURSING_RECORD_VITALS'), true);
require_once "$HIMS/config/landing.php";
check('lands on the doctor console', landing_page_for_role($_SESSION['base_role']), '/doctor.php');
imp_stop($pdo);
check('restored after doctor view', $_SESSION['base_role'], 'ADMIN');

// --- demoted / deactivated MID-impersonation -------------------------------
// imp_stop() must re-authorise against the DB: the parked role is a memory of
// what was true at start, not a fact. Restoring it blindly would hand back a
// full ADMIN session to someone who has since lost the right to one.
echo "\n== admin DEMOTED while viewing as staff ==\n";
$_SESSION = ['user_id' => 1, 'base_role' => 'ADMIN'];
$_SESSION['permissions'] = load_permissions($pdo, 1, 'ADMIN');
check('start ok', imp_start($pdo, 2), '');
$pdo->exec("UPDATE users SET base_role='STAFF' WHERE id=1");     // demoted mid-session
check('stop returns "" (session destroyed)', imp_stop($pdo), '');
check('session was cleared', empty($_SESSION['user_id']), true);
check('no admin role handed back', $_SESSION['base_role'] ?? null, null);
$denied = $pdo->query("SELECT COUNT(*) c FROM audit_logs WHERE action='impersonation_stop_denied'")->fetch()['c'];
check('denial is audited', (int) $denied, 1);
$pdo->exec("UPDATE users SET base_role='ADMIN' WHERE id=1");     // restore fixture

echo "\n== admin DEACTIVATED while viewing as staff ==\n";
$_SESSION = ['user_id' => 1, 'base_role' => 'ADMIN'];
$_SESSION['permissions'] = load_permissions($pdo, 1, 'ADMIN');
check('start ok', imp_start($pdo, 2), '');
$pdo->exec("UPDATE users SET is_active=0 WHERE id=1");
check('stop returns "" (session destroyed)', imp_stop($pdo), '');
check('session cleared', empty($_SESSION['user_id']), true);
$pdo->exec("UPDATE users SET is_active=1 WHERE id=1");

echo "\n== forced password change survives an impersonation ==\n";
// imp_start() sets must_change_password=false for the TARGET's benefit; imp_stop()
// must restore the ADMIN's own DB fact, not a hardcoded false, or an admin could
// dodge a reset another admin had just required of them.
$pdo->exec("UPDATE users SET must_change_password=1 WHERE id=1");
$_SESSION = ['user_id' => 1, 'base_role' => 'ADMIN'];
$_SESSION['permissions'] = load_permissions($pdo, 1, 'ADMIN');
check('start ok', imp_start($pdo, 2), '');
check('  suppressed while viewing', $_SESSION['must_change_password'], false);
check('stop restores admin', imp_stop($pdo), '/dashboard.php');
check('forced reset is back', $_SESSION['must_change_password'], true);
$pdo->exec("UPDATE users SET must_change_password=0 WHERE id=1");

// --- CSRF -----------------------------------------------------------------
// Both start and stop are token-checked in impersonate.php. A forged start is
// the serious one (it would put an admin into someone else's session without
// their intent), but a forged stop still means a third party is driving.
echo "\n== CSRF token ==\n";
$_SESSION = ['user_id' => 1, 'base_role' => 'ADMIN'];
unset($_SESSION['imp_csrf']);
$tok = imp_csrf_token();
check('token is issued', strlen($tok) === 64, true);
check('token is stable within a session', imp_csrf_token(), $tok);
check('correct token passes', imp_csrf_valid($tok), true);
check('wrong token fails', imp_csrf_valid(str_repeat('a', 64)), false);
check('empty token fails', imp_csrf_valid(''), false);
check('null token fails', imp_csrf_valid(null), false);
check('truncated token fails', imp_csrf_valid(substr($tok, 0, 32)), false);
unset($_SESSION['imp_csrf']);
check('no token in session => nothing validates', imp_csrf_valid($tok), false);

// --- money-action re-affirmation ------------------------------------------
// Writes stay ALLOWED while impersonating (that is the point of the feature),
// but the handful that move cash — payments, voids, refunds, shift closings —
// ask for one deliberate tick first, because they land in someone else's drawer
// and on someone else's shift tally.
echo "\n== money-action confirm gate ==\n";
$_SESSION = ['user_id' => 1, 'base_role' => 'ADMIN'];
$_POST = [];
check('no gate in a normal session', imp_block_money_action('Taking this payment'), '');
check('no checkbox in a normal session', imp_confirm_field('Taking this payment'), '');

$_SESSION['permissions'] = load_permissions($pdo, 1, 'ADMIN');
check('start ok', imp_start($pdo, 2), '');
$msg = imp_block_money_action('Taking this payment');
check('gate blocks the first attempt', $msg !== '', true);
check('  message names the staff member', str_contains($msg, 'AYESHA (RECEPTION)'), true);
check('  message names the action', str_contains($msg, 'Taking this payment'), true);
check('checkbox is emitted while impersonating', str_contains(imp_confirm_field('x'), 'imp_confirm'), true);
$_POST['imp_confirm'] = '1';
check('gate passes once re-affirmed', imp_block_money_action('Taking this payment'), '');
$_POST = [];
imp_stop($pdo);
check('gate gone after stopping', imp_block_money_action('Taking this payment'), '');

echo "\n---------------------------------------------\n";
echo "passed: $pass   failed: $fail\n";
exit($fail ? 1 : 0);
