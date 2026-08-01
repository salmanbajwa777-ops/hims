<?php
/**
 * Drug formulary — the admin-managed drug master behind the treatment sheet.
 *
 * The formulary is what makes the safety checks possible at all: a free-typed
 * drug name cannot answer "is this patient allergic to penicillins" or "is this
 * a duplicate of something already running". Each row carries the molecule, its
 * trade names, its therapeutic class, and its cross-reactivity group.
 *
 * generic_name and brand_name are SEPARATE columns and stay that way — the
 * generic is what the drug IS (what the checks reason about), the brand is what
 * the ward physically stocks.
 *
 * Rows are disabled, never deleted: an old order snapshots its drug details, so
 * removing a row would orphan nothing, but keeping the history browsable is
 * worth more than a tidy table.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/ipd_treatment.php';
refresh_session_permissions($pdo);

require_permission('IPD_MANAGE_FORMULARY');

$uid = (int) $_SESSION['user_id'];
$flash = ''; $err = '';

// ---------------- Save (create or update) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_drug') {
    $id      = (int) ($_POST['id'] ?? 0);
    $generic = mb_strtoupper(trim($_POST['generic_name'] ?? ''), 'UTF-8');
    $form    = trim($_POST['dose_form'] ?? '');
    $stren   = trim($_POST['strength'] ?? '');
    $brand   = trim($_POST['brand_name'] ?? '');

    // Brands arrive as up to 5 alternate boxes (primary + 5 = IPD_MAX_BRANDS).
    // Collected here into the comma-separated brand_names column, de-duped
    // against the primary so the dropdown never shows the same name twice.
    $altList = [];
    foreach (($_POST['brand_alt'] ?? []) as $b) {
        $b = trim((string) $b);
        if ($b === '') { continue; }
        $lower = mb_strtolower($b, 'UTF-8');
        if ($lower === mb_strtolower($brand, 'UTF-8')) { continue; }
        if (!in_array($lower, array_map(fn($x) => mb_strtolower($x, 'UTF-8'), $altList), true)) {
            $altList[] = $b;
        }
    }
    $alts = implode(',', array_slice($altList, 0, IPD_MAX_BRANDS - 1));
    $class   = trim($_POST['drug_class'] ?? '');
    $group   = mb_strtoupper(trim($_POST['allergy_group'] ?? ''), 'UTF-8');
    $high    = !empty($_POST['is_high_alert']) ? 1 : 0;
    $unit    = trim($_POST['default_dose_unit'] ?? '');
    $routes  = strtoupper(trim($_POST['default_routes'] ?? ''));
    $freqs   = strtoupper(trim($_POST['default_frequencies'] ?? ''));
    $enabled = !empty($_POST['is_enabled']) ? 1 : 0;

    if ($generic === '') {
        $err = 'The generic (molecule) name is required.';
    } elseif ($form === '') {
        // Uniqueness is (generic_name, dose_form), so a blank form would collide
        // with any other blank-form row for the same molecule.
        $err = 'The dose form is required — it is what separates the tablet from the injection.';
    } elseif ($unit !== '' && !in_array($unit, IPD_DOSE_UNITS, true)) {
        $err = 'Pick a valid default dose unit.';
    } else {
        try {
            if ($id > 0) {
                $pdo->prepare('
                    UPDATE ipd_drug_formulary
                    SET generic_name = ?, dose_form = ?, strength = ?, brand_name = ?, brand_names = ?,
                        drug_class = ?, allergy_group = ?,
                        is_high_alert = ?, default_dose_unit = ?, default_routes = ?, default_frequencies = ?, is_enabled = ?
                    WHERE id = ?
                ')->execute([
                    $generic, $form, $stren ?: null, $brand ?: null, $alts ?: null,
                    $class ?: null, $group ?: null,
                    $high, $unit ?: null, $routes ?: null, $freqs ?: null, $enabled, $id,
                ]);
                audit_log($pdo, 'ipd_formulary_updated', "Formulary drug #$id updated: $generic ($form)");
                $flash = 'Drug updated.';
            } else {
                $pdo->prepare('
                    INSERT INTO ipd_drug_formulary
                        (generic_name, dose_form, strength, brand_name, brand_names, drug_class, allergy_group,
                         is_high_alert, default_dose_unit, default_routes, default_frequencies, is_enabled, created_by_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    $generic, $form, $stren ?: null, $brand ?: null, $alts ?: null,
                    $class ?: null, $group ?: null,
                    $high, $unit ?: null, $routes ?: null, $freqs ?: null, $enabled, $uid,
                ]);
                audit_log($pdo, 'ipd_formulary_added', "Formulary drug added: $generic ($form)");
                $flash = 'Drug added to the formulary.';
            }
        } catch (Throwable $e) {
            // Uniqueness is now (generic_name, dose_form) — name the BOTH parts,
            // because "that generic already exists" was the misleading message
            // that made this look like a hard limit of one product per molecule.
            $err = 'Could not save — ' . htmlspecialchars($generic) . ' already has a "'
                 . htmlspecialchars($form) . '" entry. Use a different dose form, or edit the existing row.';
        }
    }
}

$q = trim($_GET['q'] ?? '');
try {
    // Ordered by generic THEN form, so a molecule's several forms sit together.
    if ($q !== '') {
        $st = $pdo->prepare('
            SELECT * FROM ipd_drug_formulary
            WHERE generic_name LIKE ? OR brand_name LIKE ? OR brand_names LIKE ?
               OR drug_class LIKE ? OR dose_form LIKE ?
            ORDER BY generic_name, dose_form
        ');
        $like = '%' . $q . '%';
        $st->execute([$like, $like, $like, $like, $like]);
        $drugs = $st->fetchAll();
    } else {
        $drugs = $pdo->query('SELECT * FROM ipd_drug_formulary ORDER BY generic_name, dose_form')->fetchAll();
    }
} catch (Throwable $e) {
    $drugs = [];
    // Distinguish "no treatment sheet at all" from "the dose-form migration is
    // outstanding" — they need different SQL run.
    $hasBase = false;
    try { $pdo->query('SELECT 1 FROM ipd_drug_formulary LIMIT 1'); $hasBase = true; } catch (Throwable $e2) {}
    $err = $err ?: ($hasBase
        ? 'Dose forms are not set up yet — run sql/ipd/add_formulary_dose_forms.sql.'
        : 'The formulary table is not set up yet — run sql/ipd/add_ipd_treatment_sheet.sql.');
}

$edit = null;
if (($_GET['edit'] ?? '') !== '') {
    $e = $pdo->prepare('SELECT * FROM ipd_drug_formulary WHERE id = ?');
    $e->execute([(int) $_GET['edit']]);
    $edit = $e->fetch() ?: null;
}

$pageTitle = 'Drug Formulary';
$headExtra = <<<'CSS'
<style>
.fm-wrap { max-width: 1150px; }
.fm-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 16px 18px; margin-bottom: 18px; box-shadow: var(--shadow-sm); }
.fm-card h2 { margin: 0 0 12px; font-size: var(--fs-card); }
.fm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.fm-field label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
.fm-field input, .fm-field select { width: 100%; padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: var(--radius-input); font: inherit; font-size: var(--fs-cell); background: var(--card); color: var(--text); }
.fm-hint { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }
table.fm { width: 100%; border-collapse: collapse; font-size: var(--fs-cell); }
table.fm th { text-align: left; background: var(--card-alt); padding: 8px 10px; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); border-bottom: 1px solid var(--border); }
table.fm td { padding: 8px 10px; border-bottom: 1px solid var(--row-line); }
.fm-gen { font-weight: 700; }
.fm-brand { color: var(--text-secondary); font-size: 12.5px; }
.fm-hi { background: var(--danger); color: #fff; font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 3px; margin-left: 5px; }
.fm-off { opacity: .5; }
.fm-btn { border: 1px solid var(--border-strong); background: var(--card); border-radius: var(--radius-btn); padding: 5px 12px; font-size: var(--fs-btn-sm); font-weight: 600; cursor: pointer; color: var(--text-secondary); text-decoration: none; display: inline-block; }
.fm-btn-ok { background: var(--ok); border-color: var(--ok); color: #fff; }
.fm-flash { padding: 10px 14px; border-radius: var(--radius-input); margin-bottom: 14px; font-size: var(--fs-cell); font-weight: 500; }
.fm-flash-ok { background: var(--ok-bg); color: var(--ok); border: 1px solid var(--ok); }
.fm-flash-err { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger); }
</style>
CSS;

include __DIR__ . '/partials/head.php';
$navActive = 'ipd_formulary';
include __DIR__ . '/partials/sidebar.php';
?>
<main class="page-wrap">
  <div class="fm-wrap">
    <div class="page-head"><h1 class="page-title">Drug Formulary</h1></div>

    <?php if ($flash): ?><div class="fm-flash fm-flash-ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="fm-flash fm-flash-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <form method="post" class="fm-card">
      <h2><?= $edit ? 'Edit drug' : 'Add a drug' ?></h2>
      <input type="hidden" name="action" value="save_drug">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="fm-grid">
        <div class="fm-field">
          <label>Generic (molecule) *</label>
          <input name="generic_name" required value="<?= htmlspecialchars($edit['generic_name'] ?? '') ?>" placeholder="AMOXICILLIN">
          <div class="fm-hint">What the drug IS. Drives the safety checks.</div>
        </div>
        <div class="fm-field">
          <label>Dose form *</label>
          <input name="dose_form" list="fmForms" required
                 value="<?= htmlspecialchars($edit['dose_form'] ?? '') ?>" placeholder="Injection">
          <datalist id="fmForms">
            <?php foreach (IPD_DOSE_FORMS as $f): ?><option value="<?= htmlspecialchars($f) ?>"></option><?php endforeach; ?>
          </datalist>
          <div class="fm-hint">Tablet / Injection / Suppository&hellip; The same generic can have one row per form.</div>
        </div>
        <div class="fm-field">
          <label>Strength</label>
          <input name="strength" value="<?= htmlspecialchars($edit['strength'] ?? '') ?>" placeholder="500 mg">
          <div class="fm-hint">Optional. Shown when picking the form.</div>
        </div>
        <div class="fm-field">
          <label>Brand (primary)</label>
          <input name="brand_name" value="<?= htmlspecialchars($edit['brand_name'] ?? '') ?>" placeholder="Amoxil">
          <div class="fm-hint">Auto-loads when a doctor picks this drug.</div>
        </div>
        <?php
        /* Up to 6 brands per product: the primary above plus 5 alternates.
           Separate boxes rather than one comma-separated field — the admin
           should not have to know the storage format. */
        $altBrands = array_values(array_filter(array_map('trim', explode(',', (string) ($edit['brand_names'] ?? '')))));
        for ($bi = 0; $bi < IPD_MAX_BRANDS - 1; $bi++): ?>
          <div class="fm-field">
            <label>Brand <?= $bi + 2 ?><?= $bi === 0 ? ' (alternate)' : '' ?></label>
            <input name="brand_alt[]" value="<?= htmlspecialchars($altBrands[$bi] ?? '') ?>"
                   placeholder="<?= $bi === 0 ? 'Hizone' : '' ?>">
            <?php if ($bi === 0): ?><div class="fm-hint">Other brands of the SAME product.</div><?php endif; ?>
          </div>
        <?php endfor; ?>
        <div class="fm-field">
          <label>Therapeutic class</label>
          <input name="drug_class" value="<?= htmlspecialchars($edit['drug_class'] ?? '') ?>" placeholder="Penicillin antibiotic">
          <div class="fm-hint">Drives duplicate-therapy warnings.</div>
        </div>
        <div class="fm-field">
          <label>Allergy / cross-reactivity group</label>
          <input name="allergy_group" value="<?= htmlspecialchars($edit['allergy_group'] ?? '') ?>" placeholder="PENICILLIN">
          <div class="fm-hint">Drugs sharing this group cross-react.</div>
        </div>
        <div class="fm-field">
          <label>Default dose unit</label>
          <select name="default_dose_unit">
            <option value="">—</option>
            <?php foreach (IPD_DOSE_UNITS as $u): ?>
              <option value="<?= $u ?>" <?= ($edit['default_dose_unit'] ?? '') === $u ? 'selected' : '' ?>><?= $u ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fm-field">
          <label>Default routes</label>
          <input name="default_routes" value="<?= htmlspecialchars($edit['default_routes'] ?? '') ?>" placeholder="PO,IV">
        </div>
        <div class="fm-field">
          <label>Default frequencies</label>
          <input name="default_frequencies" value="<?= htmlspecialchars($edit['default_frequencies'] ?? '') ?>" placeholder="TDS,BD">
        </div>
        <div class="fm-field" style="display:flex;flex-direction:column;justify-content:flex-end;gap:8px;">
          <label style="display:flex;align-items:center;gap:7px;font-weight:600;">
            <input type="checkbox" name="is_high_alert" value="1" style="width:auto;" <?= !empty($edit['is_high_alert']) ? 'checked' : '' ?>>
            High-alert drug
          </label>
          <label style="display:flex;align-items:center;gap:7px;font-weight:600;">
            <input type="checkbox" name="is_enabled" value="1" style="width:auto;" <?= (!$edit || !empty($edit['is_enabled'])) ? 'checked' : '' ?>>
            Available to prescribe
          </label>
        </div>
      </div>
      <div style="margin-top:14px;display:flex;gap:8px;">
        <button class="fm-btn fm-btn-ok" style="padding:9px 20px;"><?= $edit ? 'Save changes' : 'Add drug' ?></button>
        <?php if ($edit): ?><a class="fm-btn" href="ipd_formulary.php" style="padding:9px 18px;">Cancel</a><?php endif; ?>
      </div>
    </form>

    <div class="fm-card">
      <h2>Formulary <span style="font-weight:500;color:var(--text-muted);font-size:13px;">(<?= count($drugs) ?>)</span></h2>
      <form method="get" style="margin-bottom:12px;display:flex;gap:8px;">
        <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search generic, brand or class"
               style="flex:1;max-width:340px;padding:8px 10px;border:1px solid var(--border-strong);border-radius:var(--radius-input);font:inherit;font-size:var(--fs-cell);">
        <button class="fm-btn">Search</button>
        <?php if ($q !== ''): ?><a class="fm-btn" href="ipd_formulary.php">Clear</a><?php endif; ?>
      </form>
      <table class="fm">
        <thead><tr>
          <th style="width:22%">Generic</th><th style="width:14%">Form</th><th style="width:18%">Brand(s)</th>
          <th style="width:18%">Class</th><th style="width:12%">Allergy group</th>
          <th style="width:9%">Routes</th><th style="width:7%"></th>
        </tr></thead>
        <tbody>
        <?php
        // Rule a line between generics so the several forms of one molecule read
        // as a group rather than as accidental duplicates.
        $prevGeneric = null;
        foreach ($drugs as $d):
            $newGroup = $prevGeneric !== null && $prevGeneric !== $d['generic_name'];
            $prevGeneric = $d['generic_name'];
        ?>
          <tr class="<?= empty($d['is_enabled']) ? 'fm-off' : '' ?>" style="<?= $newGroup ? 'border-top:2px solid var(--border-strong);' : '' ?>">
            <td>
              <span class="fm-gen"><?= htmlspecialchars($d['generic_name']) ?></span>
              <?php if (!empty($d['is_high_alert'])): ?><span class="fm-hi">HIGH ALERT</span><?php endif; ?>
              <?php if (empty($d['is_enabled'])): ?><div class="fm-brand">(not available)</div><?php endif; ?>
            </td>
            <td>
              <b><?= htmlspecialchars($d['dose_form'] ?? '—') ?></b>
              <?php if (!empty($d['strength'])): ?><div class="fm-brand"><?= htmlspecialchars($d['strength']) ?></div><?php endif; ?>
            </td>
            <td class="fm-brand">
              <?php if ($d['brand_name']): ?><b><?= htmlspecialchars($d['brand_name']) ?></b><?php endif; ?>
              <?php if ($d['brand_names']): ?><div><?= htmlspecialchars($d['brand_names']) ?></div><?php endif; ?>
              <?php if (!$d['brand_name'] && !$d['brand_names']): ?>&mdash;<?php endif; ?>
            </td>
            <td><?= htmlspecialchars($d['drug_class'] ?? '—') ?></td>
            <td><?= htmlspecialchars($d['allergy_group'] ?? '—') ?></td>
            <td class="fm-brand"><?= htmlspecialchars(trim(($d['default_routes'] ?? '') . ' ' . ($d['default_frequencies'] ?? ''))) ?: '—' ?></td>
            <td><a class="fm-btn" href="ipd_formulary.php?edit=<?= (int) $d['id'] ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$drugs): ?>
          <tr><td colspan="7" style="color:var(--text-muted);padding:16px 10px;">No drugs match.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
</body>
</html>
