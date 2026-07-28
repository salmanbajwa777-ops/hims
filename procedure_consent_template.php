<?php
/**
 * Consent template editor — the wording printed on a procedure's consent form.
 *
 * WHY THIS IS ITS OWN PAGE. procedure_master.php saves the whole catalogue as
 * one wide table of id-keyed inputs, a row per procedure. A consent template is
 * a paragraph, not a field: dropped into that grid it would either be a
 * one-line input nobody can read what they are typing in, or a textarea that
 * makes every row six lines tall. So the catalogue keeps a link per procedure
 * and the wording gets a page with room to write in.
 *
 * THE TEMPLATE IS THE SWITCH. A procedure with a template prints a consent
 * with its receipt; a procedure without one does not. mandatory_consent stays
 * what it always was — the flag saying a consent is REQUIRED — and the two are
 * deliberately separate: a procedure can be flagged while its wording is still
 * being written, and printing a blank sheet headed "CONSENT FOR ..." is worse
 * than printing none.
 *
 * EDITING IS NOT RETROACTIVE. Saving here changes what FUTURE consents say.
 * Consents already printed keep the wording frozen onto them at billing time —
 * see consent_create_for_bill(). A patient's signed sheet must never be
 * rewritten by an edit made afterwards.
 *
 * Requires sql/add_procedure_consent.sql.
 */
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/consent.php';

$error = '';
$success = '';

// Without the column there is nothing to edit. Say so plainly rather than
// fataling on the first SELECT.
$consentLive = consent_column_live($pdo);

$procId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// ---- Save ----
if ($consentLive && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_template') {
    $template = trim((string) ($_POST['consent_template'] ?? ''));
    // Requiring consent is implied by having wording for it: an admin who
    // writes a template and leaves the flag off would get a consent that prints
    // but never gates. Ticking it here keeps the two from drifting apart.
    $requireConsent = isset($_POST['mandatory_consent']) ? 1 : 0;
    if ($template !== '') {
        $requireConsent = 1;
    }

    if ($procId <= 0) {
        $error = 'No procedure selected.';
    } else {
        try {
            // Empty template stores NULL, not '': "no consent form" and "a form
            // whose wording was cleared by accident" must not look the same.
            $pdo->prepare('UPDATE procedure_master SET consent_template = ?, mandatory_consent = ? WHERE id = ?')
                ->execute([$template !== '' ? $template : null, $requireConsent, $procId]);

            $nameStmt = $pdo->prepare('SELECT name FROM procedure_master WHERE id = ?');
            $nameStmt->execute([$procId]);
            $procName = (string) $nameStmt->fetchColumn();

            audit_log($pdo, 'consent_template_saved',
                ($template !== '' ? 'Saved' : 'Cleared') . " the consent template for \"$procName\" (#$procId)",
                (int) $_SESSION['user_id']);

            $success = $template !== ''
                ? 'Consent template saved. It applies to consents printed from now on — sheets already printed keep the wording they were signed with.'
                : 'Consent template cleared. This procedure will no longer print a consent form.';
        } catch (PDOException $e) {
            error_log('[consent_template] ' . $e->getMessage());
            $error = 'Could not save the template. Please try again.';
        }
    }
}

// ---- Load ----
$procedure = null;
$procedures = [];
if ($consentLive) {
    $procedures = $pdo->query("
        SELECT id, name, fee, mandatory_consent,
               (consent_template IS NOT NULL AND TRIM(consent_template) <> '') AS has_tpl
          FROM procedure_master
         WHERE is_active = 1
         ORDER BY name
    ")->fetchAll();

    if ($procId > 0) {
        $s = $pdo->prepare('SELECT id, name, fee, mandatory_consent, consent_template FROM procedure_master WHERE id = ?');
        $s->execute([$procId]);
        $procedure = $s->fetch() ?: null;
    }
}

$pageTitle = 'Consent Template';
$headExtra = <<<CSS
<style>
.tpl-wrap { display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 20px; align-items: start; }
@media (max-width: 860px) { .tpl-wrap { grid-template-columns: 1fr; } }
.proc-list { border: 1px solid var(--border); border-radius: var(--radius-card); overflow: hidden; }
.proc-list a { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; border-bottom: 1px solid var(--border); text-decoration: none; color: var(--text-primary); font-size: 13.5px; }
.proc-list a:last-child { border-bottom: 0; }
.proc-list a.on { background: var(--primary-light); color: var(--primary-dark); font-weight: 600; }
.proc-list .tag { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 2px 7px; border-radius: 999px; white-space: nowrap; }
.proc-list .tag.has { background: var(--green-bg); color: var(--green-text); }
.proc-list .tag.no { background: var(--surface-2, #eee); color: var(--text-muted); }
.tpl-area { width: 100%; min-height: 230px; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; line-height: 1.65; resize: vertical; background: var(--surface); color: var(--text-primary); }
.ph-table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-top: 6px; }
.ph-table td { padding: 5px 8px; border-bottom: 1px solid var(--border); vertical-align: top; }
.ph-table td:first-child { white-space: nowrap; width: 1%; }
.ph-table code { background: var(--primary-light); color: var(--primary-dark); padding: 1.5px 6px; border-radius: 4px; font-size: 12px; cursor: pointer; }
.frozen-note { font-size: 12.5px; color: var(--amber-text, #92400e); background: var(--amber-bg, #fef3c7); border-radius: var(--radius-input); padding: 9px 12px; margin-top: 14px; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'procedure_master';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>
        <div class="content">
            <div class="page-head">
                <h1>Consent template</h1>
                <p>The wording printed on a procedure's consent form. Two copies print with the receipt &mdash; one the clinic keeps signed, one the patient takes.</p>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if (!$consentLive): ?>
            <div class="alert error">
                Consent templates are not enabled on this database yet.
                Run <code>sql/add_procedure_consent.sql</code>, then reload this page.
            </div>
            <?php else: ?>

            <div class="tpl-wrap">
                <div class="proc-list">
                    <?php foreach ($procedures as $p): ?>
                    <a href="procedure_consent_template.php?id=<?= (int) $p['id'] ?>"
                       class="<?= (int) $p['id'] === $procId ? 'on' : '' ?>">
                        <span><?= htmlspecialchars($p['name']) ?></span>
                        <span class="tag <?= $p['has_tpl'] ? 'has' : 'no' ?>"><?= $p['has_tpl'] ? 'Form' : 'None' ?></span>
                    </a>
                    <?php endforeach; ?>
                    <?php if (!$procedures): ?>
                    <a href="procedure_master.php">No active procedures &mdash; add one first</a>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <?php if (!$procedure): ?>
                        <p style="color:var(--text-muted);font-size:14px;margin:0;">
                            Pick a procedure on the left to write or edit its consent wording.
                        </p>
                    <?php else: ?>
                        <h2 style="margin:0 0 4px;font-size:17px;"><?= htmlspecialchars($procedure['name']) ?></h2>
                        <p style="margin:0 0 16px;color:var(--text-muted);font-size:13px;">
                            Rs <?= number_format((float) $procedure['fee'], 0) ?>
                        </p>

                        <form method="POST" action="procedure_consent_template.php">
                            <input type="hidden" name="action" value="save_template">
                            <input type="hidden" name="id" value="<?= (int) $procedure['id'] ?>">

                            <label for="tplArea" style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);margin-bottom:6px;">
                                Consent wording
                            </label>
                            <textarea class="tpl-area" name="consent_template" id="tplArea"
                                      placeholder="Leave empty for no consent form."><?= htmlspecialchars((string) ($procedure['consent_template'] ?? '')) ?></textarea>

                            <p style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);margin:16px 0 0;">
                                Placeholders <span style="font-weight:400;text-transform:none;letter-spacing:0;">&mdash; click to insert</span>
                            </p>
                            <table class="ph-table">
                                <?php foreach (CONSENT_PLACEHOLDERS as $ph => $desc): ?>
                                <tr>
                                    <td><code class="ph" data-ph="<?= htmlspecialchars($ph) ?>"><?= htmlspecialchars($ph) ?></code></td>
                                    <td style="color:var(--text-muted);"><?= htmlspecialchars($desc) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </table>

                            <label class="consent-check" style="display:flex;align-items:center;gap:8px;margin-top:16px;font-size:13.5px;">
                                <input type="checkbox" name="mandatory_consent" value="1"
                                       <?= (int) $procedure['mandatory_consent'] === 1 ? 'checked' : '' ?>
                                       style="width:15px;height:15px;accent-color:var(--primary);">
                                Requires consent
                            </label>
                            <p style="font-size:12px;color:var(--text-muted);margin:6px 0 0;">
                                Ticked automatically when wording is saved &mdash; a procedure with a consent form always requires one.
                            </p>

                            <div class="frozen-note">
                                Editing this changes consents printed <b>from now on</b>. Sheets already
                                printed keep the wording they were signed with &mdash; a signed consent is
                                never rewritten by a later edit.
                            </div>

                            <div style="display:flex;gap:10px;margin-top:18px;">
                                <button type="submit" class="btn primary">Save template</button>
                                <a href="procedure_master.php" class="btn">Back to catalogue</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

<script>
// Click a placeholder to drop it at the cursor — retyping {{signer_relation}}
// by hand is where a silent typo (and a placeholder that never substitutes)
// comes from.
(function () {
    var area = document.getElementById('tplArea');
    if (!area) return;
    document.querySelectorAll('.ph').forEach(function (el) {
        el.addEventListener('click', function () {
            var ph = el.dataset.ph;
            var s = area.selectionStart, e = area.selectionEnd;
            area.value = area.value.slice(0, s) + ph + area.value.slice(e);
            area.focus();
            area.selectionStart = area.selectionEnd = s + ph.length;
        });
    });
})();
</script>
</body>
</html>
