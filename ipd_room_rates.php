<?php
/**
 * In-Door (IPD) Room Categories & Rates — admin catalogue.
 *
 * Every admission is a private room; categories exist only to price them
 * differently (Basic vs Premium vs a high-dependency room). Each category
 * carries FOUR charges:
 *   - per_day_rate          the room itself, per day
 *   - nursing_per_day_rate  nursing, per day
 *   - mo_per_day_rate       medical officer, per day
 *   - consultant_visit_fee  per paid daily round
 *
 * Stored, not hardcoded — read by the IPD admit modal and the billing engine.
 *
 * RENAMING CASCADES. ipd_admissions.room_category holds the category NAME, not
 * an FK, and config/ipd_billing.php prices a stay by matching that string. So a
 * bare rename would silently drop an in-flight patient's room charge to Rs 0 —
 * every rename here updates ipd_admissions in the same transaction.
 *
 * Gated on IPD_MANAGE_WARD_RATES (the key keeps its legacy spelling: it is an
 * internal identifier checked in code and stored in the DB, and renaming it
 * would 403 every admin the instant the two fell out of step).
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/ipd_billing.php';   // reprice_ipd_bill()
refresh_session_permissions($pdo);

require_permission('IPD_MANAGE_WARD_RATES');

$error = '';
$success = '';
$uid = (int) $_SESSION['user_id'];

// How many admissions still point at a category — drives "can this be deleted?"
function room_category_usage(PDO $pdo): array {
    try {
        $rows = $pdo->query('SELECT room_category, COUNT(*) AS n FROM ipd_admissions GROUP BY room_category')->fetchAll();
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) { $out[$r['room_category']] = (int) $r['n']; }
    return $out;
}

// ---- Add a category ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_category') {
    $name = trim($_POST['room_category'] ?? '');
    if ($name === '') {
        $error = 'Enter a room category name.';
    } else {
        try {
            $pdo->prepare('
                INSERT INTO ipd_room_rates (room_category, per_day_rate, nursing_per_day_rate, mo_per_day_rate, consultant_visit_fee, is_enabled, updated_by_id)
                VALUES (?, ?, ?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE per_day_rate = VALUES(per_day_rate), nursing_per_day_rate = VALUES(nursing_per_day_rate),
                                        mo_per_day_rate = VALUES(mo_per_day_rate), consultant_visit_fee = VALUES(consultant_visit_fee),
                                        is_enabled = 1, updated_by_id = VALUES(updated_by_id)
            ')->execute([
                $name,
                (float) ($_POST['per_day_rate'] ?? 0),
                (float) ($_POST['nursing_per_day_rate'] ?? 0),
                (float) ($_POST['mo_per_day_rate'] ?? 0),
                (float) ($_POST['consultant_visit_fee'] ?? 0),
                $uid,
            ]);
            audit_log($pdo, 'ipd_room_category_added', "Room category \"$name\" saved", $uid);
            $success = "Room category \"$name\" saved.";
        } catch (Throwable $e) {
            $error = 'Could not save that room category.';
        }
    }
}

// ---- Save all rows: rates, names and the active flag ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_categories') {
    $names   = $_POST['room_category'] ?? [];       // [id => name]
    $rates   = $_POST['per_day_rate'] ?? [];
    $nursing = $_POST['nursing_per_day_rate'] ?? [];
    $mo      = $_POST['mo_per_day_rate'] ?? [];
    $fees    = $_POST['consultant_visit_fee'] ?? [];
    $enabled = $_POST['enabled'] ?? [];

    $existing = $pdo->query('SELECT id, room_category FROM ipd_room_rates')->fetchAll();
    $wasNamed = [];
    foreach ($existing as $e) { $wasNamed[(int) $e['id']] = $e['room_category']; }

    $renamed = [];
    $taken = array_map('mb_strtolower', array_values($wasNamed));

    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare('UPDATE ipd_room_rates SET room_category = ?, per_day_rate = ?, nursing_per_day_rate = ?, mo_per_day_rate = ?, consultant_visit_fee = ?, is_enabled = ?, updated_by_id = ? WHERE id = ?');
        $cascade = $pdo->prepare('UPDATE ipd_admissions SET room_category = ? WHERE room_category = ?');

        foreach ($rates as $id => $amt) {
            $id = (int) $id;
            $old = $wasNamed[$id] ?? null;
            if ($old === null) { continue; }

            $new = trim((string) ($names[$id] ?? $old));
            if ($new === '') { $new = $old; }
            // Refuse a rename that collides with a DIFFERENT category — the
            // column is UNIQUE and the two would then be indistinguishable.
            if ($new !== $old && in_array(mb_strtolower($new), $taken, true)) {
                $new = $old;
                $error = "\"$new\" is already a room category — that rename was skipped.";
            }

            $upd->execute([
                $new, (float) $amt, (float) ($nursing[$id] ?? 0), (float) ($mo[$id] ?? 0),
                (float) ($fees[$id] ?? 0), isset($enabled[$id]) ? 1 : 0, $uid, $id,
            ]);

            if ($new !== $old) {
                // The cascade is the whole point: without it every admission
                // still holding the old name prices its stay at Rs 0.
                $cascade->execute([$new, $old]);
                $renamed[] = [$old, $new];
            }
        }
        $pdo->commit();

        foreach ($renamed as [$old, $new]) {
            audit_log($pdo, 'ipd_room_category_renamed', "Room category \"$old\" renamed to \"$new\" (admissions updated)", $uid);
        }
        audit_log($pdo, 'ipd_room_rates_updated', 'Updated IPD room categories / rates', $uid);

        // Rates just moved, so any bill still OPEN is now priced on stale
        // figures. Reprice the drafts; settled bills are left alone.
        $repriced = 0;
        try {
            $open = $pdo->query("
                SELECT a.id, a.room_category, a.admitted_at, a.discharged_at
                FROM ipd_admissions a
                JOIN ipd_bills b ON b.admission_id = a.id
                WHERE b.status = 'draft'
            ")->fetchAll();
            foreach ($open as $adm) {
                if (reprice_ipd_bill($pdo, $adm)) { $repriced++; }
            }
        } catch (Throwable $e) { /* repricing is best-effort; rates are saved */ }

        if (!$error) {
            $success = 'Room rates saved.'
                . ($renamed ? ' ' . count($renamed) . ' renamed (admissions updated).' : '')
                . ($repriced ? " $repriced open bill" . ($repriced > 1 ? 's' : '') . ' repriced.' : '');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = 'Could not save the room rates.';
    }
}

// ---- Delete a category (only when nothing references it) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_category') {
    $id = (int) ($_POST['id'] ?? 0);
    $row = $pdo->prepare('SELECT room_category FROM ipd_room_rates WHERE id = ?');
    $row->execute([$id]);
    $name = $row->fetchColumn();
    if ($name === false) {
        $error = 'That room category no longer exists.';
    } else {
        $usage = room_category_usage($pdo);
        if (($usage[$name] ?? 0) > 0) {
            // Deleting would orphan those admissions' rate lookup.
            $error = "\"$name\" is used by " . $usage[$name] . ' admission(s) — switch it off instead of deleting.';
        } else {
            $pdo->prepare('DELETE FROM ipd_room_rates WHERE id = ?')->execute([$id]);
            audit_log($pdo, 'ipd_room_category_deleted', "Room category \"$name\" deleted", $uid);
            $success = "Room category \"$name\" deleted.";
        }
    }
}

try {
    $categories = $pdo->query('SELECT * FROM ipd_room_rates ORDER BY room_category')->fetchAll();
} catch (Throwable $e) {
    $categories = [];
    $error = $error ?: 'Room rates table not found — run sql/ipd/add_ipd_room_charges.sql.';
}
$usage = room_category_usage($pdo);

$pageTitle = 'In-Door Room Categories & Rates';
$headExtra = <<<CSS
<style>
.page-title { letter-spacing: -.02em; }
.card { padding: 20px; }
.rate-table { width: 100%; border-collapse: collapse; }
.rate-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); font-weight: 700; padding: 8px 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.rate-table td { padding: 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.row-inp { padding: 8px 10px; border: 1px solid var(--border); border-radius: 9px; font: inherit; font-size: 13px; background: #fff; width: 108px; }
.row-inp:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.name-inp { padding: 8px 10px; border: 1px solid var(--border); border-radius: 9px; font: inherit; font-size: 13.5px; font-weight: 600; background: #fff; width: 200px; }
.name-inp:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.add-row { display: grid; grid-template-columns: 1.4fr 1fr 1fr 1fr 1fr auto; gap: 10px; align-items: end; margin-bottom: 20px; }
.add-row label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.add-row input { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); }
.add-row input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.active-toggle { display: inline-flex; align-items: center; cursor: pointer; }
.active-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.active-toggle span { width: 40px; height: 22px; border-radius: 20px; background: var(--border); position: relative; transition: background .15s; display: inline-block; }
.active-toggle span::after { content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
.active-toggle input:checked + span { background: var(--primary); }
.active-toggle input:checked + span::after { transform: translateX(18px); }
.cur { color: var(--text-muted); font-weight: 600; font-size: 12px; }
.in-use { font-size: 11.5px; color: var(--text-muted); font-weight: 600; }
.del-btn { background: none; border: none; color: var(--red-text); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; text-decoration: underline; padding: 2px 4px; }
.hint { font-size: 12px; color: var(--text-secondary); margin-top: 14px; line-height: 1.6; }
@media (max-width: 900px){ .add-row { grid-template-columns: 1fr 1fr; } }
CSS;
$headExtra .= "\n</style>";
require __DIR__ . '/partials/head.php';
$navActive = 'ipd_room_rates';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">In-Door Room Categories &amp; Rates</div>
                    <div class="page-sub">Per-day room, nursing and medical-officer charges, plus the fee for each daily round</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="card">
                <form method="POST" action="ipd_room_rates.php">
                    <input type="hidden" name="action" value="add_category">
                    <div class="add-row">
                        <div><label>New room category</label><input type="text" name="room_category" placeholder="e.g. Premium Private Room" required></div>
                        <div><label>Room / day (Rs)</label><input type="number" step="0.01" min="0" name="per_day_rate" value="0"></div>
                        <div><label>Nursing / day (Rs)</label><input type="number" step="0.01" min="0" name="nursing_per_day_rate" value="0"></div>
                        <div><label>MO / day (Rs)</label><input type="number" step="0.01" min="0" name="mo_per_day_rate" value="0"></div>
                        <div><label>Consultant / round (Rs)</label><input type="number" step="0.01" min="0" name="consultant_visit_fee" value="0"></div>
                        <button type="submit" class="btn">Add</button>
                    </div>
                </form>

                <form method="POST" action="ipd_room_rates.php">
                    <input type="hidden" name="action" value="save_categories">
                    <div style="overflow-x:auto;">
                    <table class="rate-table">
                        <thead>
                            <tr>
                                <th>Room category</th>
                                <th>Room / day</th>
                                <th>Nursing / day</th>
                                <th>MO / day</th>
                                <th>Consultant / round</th>
                                <th>Active</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$categories): ?>
                            <tr><td colspan="7" class="muted" style="padding:18px 10px;">No room categories yet — add one above.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($categories as $c): $id = (int) $c['id']; $used = $usage[$c['room_category']] ?? 0; ?>
                            <tr>
                                <td><input class="name-inp" type="text" name="room_category[<?= $id ?>]" value="<?= htmlspecialchars($c['room_category']) ?>" maxlength="100"></td>
                                <td><span class="cur">Rs</span> <input class="row-inp" type="number" step="0.01" min="0" name="per_day_rate[<?= $id ?>]" value="<?= htmlspecialchars((string) $c['per_day_rate']) ?>"></td>
                                <td><span class="cur">Rs</span> <input class="row-inp" type="number" step="0.01" min="0" name="nursing_per_day_rate[<?= $id ?>]" value="<?= htmlspecialchars((string) ($c['nursing_per_day_rate'] ?? 0)) ?>"></td>
                                <td><span class="cur">Rs</span> <input class="row-inp" type="number" step="0.01" min="0" name="mo_per_day_rate[<?= $id ?>]" value="<?= htmlspecialchars((string) ($c['mo_per_day_rate'] ?? 0)) ?>"></td>
                                <td><span class="cur">Rs</span> <input class="row-inp" type="number" step="0.01" min="0" name="consultant_visit_fee[<?= $id ?>]" value="<?= htmlspecialchars((string) $c['consultant_visit_fee']) ?>"></td>
                                <td>
                                    <label class="active-toggle">
                                        <input type="checkbox" name="enabled[<?= $id ?>]" value="1" <?= $c['is_enabled'] ? 'checked' : '' ?>>
                                        <span></span>
                                    </label>
                                </td>
                                <td>
                                    <?php if ($used > 0): ?>
                                    <span class="in-use">in use &middot; <?= $used ?></span>
                                    <?php else: ?>
                                    <button type="submit" form="delcat<?= $id ?>" class="del-btn">Delete</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if ($categories): ?>
                    <div style="margin-top:16px;"><button type="submit" class="btn">Save all</button></div>
                    <?php endif; ?>
                </form>

                <?php // Delete forms live outside the save form — nested forms are invalid HTML. ?>
                <?php foreach ($categories as $c): if (($usage[$c['room_category']] ?? 0) > 0) continue; ?>
                <form id="delcat<?= (int) $c['id'] ?>" method="POST" action="ipd_room_rates.php" style="display:none;">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                </form>
                <?php endforeach; ?>

                <div class="hint">
                    Renaming a category here also updates every patient already admitted under the old name, so their
                    bill keeps pricing correctly. A category still in use can be switched off but not deleted.
                    Saving re-prices any bill that has not been settled yet.
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
