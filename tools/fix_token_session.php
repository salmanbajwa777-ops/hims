<?php
/**
 * Token session restart — diagnose AND fix.
 *
 * THE SYMPTOM: a doctor's evening tokens continue from the morning run
 * (…13, 14, then 15) instead of restarting at 1.
 *
 * Three independent things must ALL be true for a restart to happen, and this
 * tool checks each, names the one that is broken, and repairs what is repairable:
 *
 *   1. visit_queue_counters must be keyed (doctor_id, visit_date, token_session).
 *      If the PK is still the old 2-column (doctor_id, visit_date), issue_token()
 *      writes session 2 but the ON DUPLICATE KEY still matches the MORNING row and
 *      keeps incrementing it. This is the usual cause and this tool FIXES it.
 *
 *   2. visits.token_session must exist, or the session is never recorded.
 *      Also fixed here.
 *
 *   3. The doctor must have a SECOND window (start_time_2) on that date's day
 *      sheet. token_session_for() returns 1 when it is NULL — no second window
 *      means no session 2, so nothing restarts. This is DATA, not schema: it
 *      cannot be invented, so the tool reports it and tells you where to set it.
 *
 * WHAT IT WILL NOT DO: rewrite tokens already issued. token_no is printed on
 * slips patients are physically holding, and renumbering them would mean two
 * people in the waiting room holding the same paper with different queue
 * positions. Past rows are reported; the fix applies from the next registration.
 *
 * Admin-only. Read-only until you pass &apply=1.
 *
 * Usage:
 *   tools/fix_token_session.php              -- diagnose only, changes nothing
 *   tools/fix_token_session.php?apply=1      -- apply the schema fix
 *   tools/fix_token_session.php?date=2026-08-17   -- inspect another date
 */
require_once __DIR__ . '/../config/guard_admin.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/tokens.php';

header('Content-Type: text/plain; charset=utf-8');

$date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$apply = ($_GET['apply'] ?? '') === '1';

echo "TOKEN SESSION RESTART — DIAGNOSE" . ($apply ? " AND FIX" : " (read-only)") . "\n";
echo "date : $date\n";
echo "mode : " . ($apply ? "APPLY — schema changes will be made" : "DRY RUN — nothing will be changed") . "\n";
echo str_repeat('=', 78) . "\n\n";

$problems = [];
$fixed    = [];

/** Column present? */
$hasCol = function (string $table, string $col) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $s->execute([$table, $col]);
    return ((int) $s->fetchColumn()) > 0;
};

/** The columns of a named index, in order. */
$indexCols = function (string $table, string $index) use ($pdo): array {
    $s = $pdo->prepare('SELECT column_name FROM information_schema.statistics
                         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                         ORDER BY seq_in_index');
    $s->execute([$table, $index]);
    return array_column($s->fetchAll(PDO::FETCH_ASSOC), 'column_name');
};

// =========================================================================
echo "CHECK 1 — visits.token_session exists\n";
// =========================================================================
if ($hasCol('visits', 'token_session')) {
    echo "  OK    visits.token_session present\n\n";
} else {
    echo "  BROKEN  visits.token_session is MISSING — the session is never recorded.\n";
    $problems[] = 'visits.token_session missing';
    if ($apply) {
        try {
            $pdo->exec('ALTER TABLE visits ADD COLUMN token_session TINYINT NOT NULL DEFAULT 1 AFTER token_no');
            echo "  FIXED   added visits.token_session (existing rows default to 1)\n";
            $fixed[] = 'visits.token_session added';
        } catch (PDOException $e) {
            echo "  FAILED  " . $e->getMessage() . "\n";
        }
    } else {
        echo "  FIX     ALTER TABLE visits ADD COLUMN token_session TINYINT NOT NULL DEFAULT 1 AFTER token_no\n";
    }
    echo "\n";
}

// =========================================================================
echo "CHECK 2 — visit_queue_counters.token_session exists\n";
// =========================================================================
if ($hasCol('visit_queue_counters', 'token_session')) {
    echo "  OK    visit_queue_counters.token_session present\n\n";
} else {
    echo "  BROKEN  visit_queue_counters.token_session is MISSING.\n";
    $problems[] = 'visit_queue_counters.token_session missing';
    if ($apply) {
        try {
            $pdo->exec('ALTER TABLE visit_queue_counters ADD COLUMN token_session TINYINT NOT NULL DEFAULT 1 AFTER visit_date');
            echo "  FIXED   added visit_queue_counters.token_session\n";
            $fixed[] = 'visit_queue_counters.token_session added';
        } catch (PDOException $e) {
            echo "  FAILED  " . $e->getMessage() . "\n";
        }
    } else {
        echo "  FIX     ALTER TABLE visit_queue_counters ADD COLUMN token_session TINYINT NOT NULL DEFAULT 1 AFTER visit_date\n";
    }
    echo "\n";
}

// =========================================================================
echo "CHECK 3 — the counter's PRIMARY KEY includes token_session\n";
echo "          (this is the one that actually causes the symptom)\n";
// =========================================================================
$pk = $indexCols('visit_queue_counters', 'PRIMARY');
echo "  current PK: (" . implode(', ', $pk) . ")\n";

$pkOk = in_array('token_session', $pk, true);
if ($pkOk) {
    echo "  OK    the session is part of the key, so each session counts separately\n\n";
} else {
    echo "  BROKEN  token_session is NOT in the key.\n";
    echo "          issue_token() writes session 2, but ON DUPLICATE KEY matches the\n";
    echo "          MORNING row and increments it -- which is exactly your symptom.\n";
    $problems[] = 'counter PK missing token_session';

    // The re-key can only run once the column exists.
    if (!$hasCol('visit_queue_counters', 'token_session')) {
        echo "  BLOCKED the column must be added first (check 2). Re-run with apply=1.\n\n";
    } elseif ($apply) {
        // A single ALTER: the table is never without a primary key mid-statement.
        //
        // The old PK guaranteed one row per (doctor, date). Widening it can only
        // ever ADD room, never collide -- every existing row already carries
        // token_session = 1, so the widened key is unique by construction.
        try {
            $pdo->exec('ALTER TABLE visit_queue_counters
                        DROP PRIMARY KEY,
                        ADD PRIMARY KEY (doctor_id, visit_date, token_session)');
            echo "  FIXED   PK is now (doctor_id, visit_date, token_session)\n";
            $fixed[] = 'counter re-keyed';
            $pkOk = true;
        } catch (PDOException $e) {
            echo "  FAILED  " . $e->getMessage() . "\n";
        }
        echo "\n";
    } else {
        echo "  FIX     ALTER TABLE visit_queue_counters\n";
        echo "            DROP PRIMARY KEY,\n";
        echo "            ADD PRIMARY KEY (doctor_id, visit_date, token_session);\n\n";
    }
}

// =========================================================================
echo "CHECK 4 — does each doctor have a SECOND window on $date?\n";
echo "          Schema cannot fix this: no start_time_2 means no session 2.\n";
// =========================================================================
$noSecond = [];
try {
    $s = $pdo->prepare("
        SELECT u.id, u.name, dt.start_time, dt.end_time, dt.start_time_2, dt.end_time_2
          FROM users u
          LEFT JOIN doctor_day_timings dt
                 ON dt.doctor_id = u.id AND dt.timing_date = ?
         WHERE u.base_role = 'DOCTOR' AND u.is_active = 1
         ORDER BY u.name
    ");
    $s->execute([$date]);
    $docs = $s->fetchAll(PDO::FETCH_ASSOC);

    if (!$docs) {
        echo "  (no active doctors found)\n\n";
    } else {
        printf("  %-26s %-11s %-11s %-11s %s\n", 'DOCTOR', 'SESS1 START', 'SESS1 END', 'SESS2 START', 'VERDICT');
        foreach ($docs as $d) {
            $s2 = trim((string) ($d['start_time_2'] ?? ''));
            $has2 = $s2 !== '' && $s2 !== '00:00:00';
            if (!$has2) { $noSecond[] = $d['name']; }
            printf("  %-26s %-11s %-11s %-11s %s\n",
                mb_strimwidth($d['name'], 0, 26, ''),
                $d['start_time']   ?: '-',
                $d['end_time']     ?: '-',
                $s2 ?: '-',
                $has2 ? 'session 2 exists' : '** NO SESSION 2 — token cannot restart **');
        }
        echo "\n";
        if ($noSecond) {
            echo "  " . count($noSecond) . " doctor(s) have no second window on $date.\n";
            echo "  For those, the token SHOULD keep counting -- that is correct behaviour,\n";
            echo "  not the bug. Set the evening window on the doctor's day sheet\n";
            echo "  (reception's day sheet / doctor_timings screen) and the next\n";
            echo "  registration after the boundary starts a fresh run at 1.\n\n";
            $problems[] = count($noSecond) . ' doctor(s) without start_time_2 on ' . $date;
        }
    }
} catch (PDOException $e) {
    echo "  could not read doctor_day_timings: " . $e->getMessage() . "\n\n";
}

// =========================================================================
echo "CHECK 5 — what actually happened on $date\n";
// =========================================================================
try {
    $sessCol = $hasCol('visits', 'token_session') ? 'v.token_session' : '1';
    $s = $pdo->prepare("
        SELECT u.name, $sessCol AS token_session,
               MIN(v.token_no) AS lo, MAX(v.token_no) AS hi, COUNT(*) AS n,
               MIN(v.created_at) AS first_at, MAX(v.created_at) AS last_at
          FROM visits v
          JOIN users u ON u.id = v.doctor_id
         WHERE DATE(v.created_at) = ?
         GROUP BY u.name, $sessCol
         ORDER BY u.name, token_session
    ");
    $s->execute([$date]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "  no visits on $date\n\n";
    } else {
        printf("  %-24s %-4s %-11s %-5s %-9s %s\n", 'DOCTOR', 'SESS', 'TOKENS', 'COUNT', 'FIRST', 'LAST');
        $prevDoc = null; $prevHi = null;
        foreach ($rows as $r) {
            printf("  %-24s %-4s %-11s %-5s %-9s %s\n",
                mb_strimwidth($r['name'], 0, 24, ''),
                $r['token_session'],
                $r['lo'] . '-' . $r['hi'],
                $r['n'],
                substr((string) $r['first_at'], 11, 5),
                substr((string) $r['last_at'], 11, 5));
            // A session 2 that starts above 1 is the smoking gun.
            if ($r['name'] === $prevDoc && (int) $r['token_session'] > 1 && (int) $r['lo'] > 1) {
                echo "        ^^ session " . $r['token_session'] . " started at " . $r['lo']
                   . " instead of 1 — the counter did not reset\n";
            }
            $prevDoc = $r['name']; $prevHi = $r['hi'];
        }
        echo "\n";
    }
} catch (PDOException $e) {
    echo "  could not read visits: " . $e->getMessage() . "\n\n";
}

// =========================================================================
echo "CHECK 6 — live counter rows for $date\n";
// =========================================================================
try {
    $cols = $hasCol('visit_queue_counters', 'token_session')
        ? 'doctor_id, visit_date, token_session, next_token'
        : 'doctor_id, visit_date, next_token';
    $s = $pdo->prepare("SELECT $cols FROM visit_queue_counters WHERE visit_date = ? ORDER BY doctor_id");
    $s->execute([$date]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "  none\n\n";
    } else {
        foreach ($rows as $r) {
            echo "  " . json_encode($r) . "\n";
        }
        echo "\n";
        // One row per doctor where the session column exists means only one
        // session was ever counted.
        if ($hasCol('visit_queue_counters', 'token_session')) {
            $perDoc = [];
            foreach ($rows as $r) { $perDoc[$r['doctor_id']][] = $r['token_session']; }
            foreach ($perDoc as $did => $sessions) {
                if (count($sessions) === 1 && (int) $sessions[0] === 1) {
                    echo "  doctor $did has only a session-1 counter — either no evening\n";
                    echo "  registrations yet, or check 4 shows why session 2 never triggered.\n";
                }
            }
            echo "\n";
        }
    }
} catch (PDOException $e) {
    echo "  could not read visit_queue_counters: " . $e->getMessage() . "\n\n";
}

// =========================================================================
echo str_repeat('=', 78) . "\n";
echo "VERDICT\n";
echo str_repeat('=', 78) . "\n";

if (!$problems) {
    echo "Schema is correct and every doctor with an evening clinic has a second\n";
    echo "window. If a token still did not restart, the registration happened BEFORE\n";
    echo "the session boundary — which is the midpoint between session 1's end and\n";
    echo "session 2's start, not session 2's start itself. Check the times in check 4\n";
    echo "against the registration time in check 5.\n";
} else {
    echo "Found " . count($problems) . " problem(s):\n";
    foreach ($problems as $i => $p) { echo "  " . ($i + 1) . ". $p\n"; }
    echo "\n";
    if ($fixed) {
        echo "APPLIED " . count($fixed) . " fix(es):\n";
        foreach ($fixed as $f) { echo "  - $f\n"; }
        echo "\nThe next registration after the session boundary will start at 1.\n";
        echo "Tokens ALREADY ISSUED are left alone on purpose: they are printed on\n";
        echo "slips patients are holding, and renumbering them would put two people\n";
        echo "in the waiting room holding the same number.\n";
    } elseif (!$apply) {
        echo "Nothing was changed. Re-run with  &apply=1  to apply the schema fixes.\n";
    }
    if ($noSecond) {
        echo "\nNOTE: the day-sheet gap (check 4) is DATA, not schema. apply=1 cannot\n";
        echo "fix it — set the evening window for those doctors on the day sheet.\n";
    }
}
echo "\n";
