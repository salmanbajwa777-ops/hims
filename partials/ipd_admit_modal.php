<?php
/**
 * Shared "Admit to In-Door (IPD)" modal + openIpdAdmit()/closeIpdAdmit() JS.
 * The IPD counterpart to partials/admit_modal.php — separate module, separate
 * handler (config/ipd_actions.php, action=ipd_admit_patient).
 *
 * Expects in scope:
 *   $ipdAdmitFormAction — where the form POSTs (defaults to the current script).
 *   $ipdWards           — [['ward','per_day_rate','consultant_visit_fee'], ...] enabled.
 *   $ipdDoctors         — [['id','name'], ...] system doctors for the consultant picker.
 *   $ipdNurses          — [['id','name'], ...] eligible PRIMARY nurses.
 *
 * Assigning a primary nurse is MANDATORY — handle_ipd_admit() rejects an admit
 * without one. It records who is accountable for the stay and does NOT restrict
 * who may work on it: any staff member with the relevant nursing permission can
 * still log care, medications and vitals against the admission.
 *
 * openIpdAdmit(id, patientName, doctorId, doctorName, byPatient) accepts either a
 * visit id (queue context) or a patient id (all-patients context) — the hidden
 * field is chosen via byPatient so the handler resolves/creates the visit.
 */
$ipdAdmitFormAction = $ipdAdmitFormAction ?? basename($_SERVER['SCRIPT_NAME'] ?? 'patients.php');
$ipdWards   = $ipdWards ?? [];
$ipdDoctors = $ipdDoctors ?? [];
$ipdNurses  = $ipdNurses ?? [];
// A primary nurse is mandatory, so an empty roster makes admitting impossible —
// the submit is disabled rather than letting the form take a server rejection.
$ipdAdmitOk = $ipdWards && $ipdNurses;
?>
<style>
.ipd-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 60; align-items: center; justify-content: center; padding: 20px; }
.ipd-overlay.open { display: flex; }
.ipd-modal { background: var(--card,#fff); border-radius: 16px; width: 100%; max-width: 460px; box-shadow: 0 20px 50px rgba(0,0,0,.25); overflow: hidden; }
.ipd-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 22px 6px; }
.ipd-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted,#64748b); }
.ipd-name { font-size: 18px; font-weight: 700; margin-top: 2px; }
.ipd-x { background: none; border: none; font-size: 24px; line-height: 1; color: var(--text-muted,#64748b); cursor: pointer; }
.ipd-body { padding: 10px 22px 4px; display: flex; flex-direction: column; gap: 16px; max-height: 70vh; overflow-y: auto; }
.ipd-field > label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-secondary,#475569); margin-bottom: 8px; }
.ipd-field select, .ipd-field input[type="text"], .ipd-field input[type="number"], .ipd-field textarea {
    width: 100%; padding: 10px 12px; border: 1px solid var(--border,#e2e8f0); border-radius: 12px; font: inherit; font-size: 13.5px; background: var(--bg,#f8fafc);
}
.ipd-field textarea { resize: vertical; min-height: 60px; }
.ipd-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.ipd-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 22px 20px; }
</style>
<div class="ipd-overlay" id="ipdAdmitOverlay" onclick="if(event.target===this)closeIpdAdmit()">
    <div class="ipd-modal" role="dialog" aria-modal="true" aria-labelledby="ipdAdmitTitle">
        <form method="POST" action="<?= htmlspecialchars($ipdAdmitFormAction) ?>">
            <input type="hidden" name="action" value="ipd_admit_patient">
            <input type="hidden" name="visit_id" id="ipdAdmitVisitId" value="">
            <input type="hidden" name="patient_id" id="ipdAdmitPatientId" value="">
            <div class="ipd-head">
                <div>
                    <div class="ipd-eyebrow">Admit to In-Door</div>
                    <div class="ipd-name" id="ipdAdmitTitle">&mdash;</div>
                </div>
                <button type="button" class="ipd-x" onclick="closeIpdAdmit()" aria-label="Close">&times;</button>
            </div>

            <div class="ipd-body">
                <div class="ipd-row2">
                    <div class="ipd-field">
                        <label>Ward</label>
                        <select name="ward" required>
                            <option value="">Select ward&hellip;</option>
                            <?php foreach ($ipdWards as $w): ?>
                            <option value="<?= htmlspecialchars($w['ward']) ?>">
                                <?= htmlspecialchars($w['ward']) ?><?= (float) $w['per_day_rate'] > 0 ? ' (Rs ' . number_format((float) $w['per_day_rate']) . '/day)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$ipdWards): ?>
                        <div class="muted" style="margin-top:6px;font-size:12px;">No wards enabled. Set them under In-Door Ward Rates.</div>
                        <?php endif; ?>
                    </div>
                    <div class="ipd-field">
                        <label>Room (1&ndash;4)</label>
                        <input type="number" name="room_no" min="1" max="4" required placeholder="1&ndash;4">
                    </div>
                </div>

                <div class="ipd-field">
                    <label>Admitting consultant <span style="color:var(--red,#dc2626);">*</span></label>
                    <select name="admitting_consultant_id" id="ipdAdmitDoctor">
                        <option value="">&mdash; manual entry below &mdash;</option>
                        <?php foreach ($ipdDoctors as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="admitting_consultant_manual" id="ipdAdmitDoctorManual" class="uc" placeholder="Or type the consultant's name" style="margin-top:8px;">
                </div>

                <div class="ipd-field">
                    <label>Primary nurse <span style="color:var(--red,#dc2626);">*</span></label>
                    <select name="assigned_nurse_id" required>
                        <option value="">Select primary nurse&hellip;</option>
                        <?php foreach ($ipdNurses as $n): ?>
                        <option value="<?= (int) $n['id'] ?>"><?= htmlspecialchars($n['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$ipdNurses): ?>
                    <div class="muted" style="margin-top:6px;font-size:12px;">
                        No staff hold the In-Door nursing permission &mdash; grant it under Permissions before admitting.
                    </div>
                    <?php else: ?>
                    <div class="muted" style="margin-top:6px;font-size:12px;">
                        Accountable for this stay. Any nursing staff can still log care and vitals.
                    </div>
                    <?php endif; ?>
                </div>

                <div class="ipd-field">
                    <label>Provisional diagnosis <span class="muted" style="font-weight:500;">(working diagnosis &mdash; seeds the first ward round)</span></label>
                    <textarea name="provisional_diagnosis" maxlength="500" placeholder="e.g. Community Acquired Pneumonia"></textarea>
                </div>
            </div>

            <div class="ipd-foot">
                <button type="button" class="btn secondary" onclick="closeIpdAdmit()">Cancel</button>
                <button type="submit" class="btn" <?= $ipdAdmitOk ? '' : 'disabled' ?>>Admit to In-Door</button>
            </div>
        </form>
    </div>
</div>
<script>
// openIpdAdmit(id, patientName, doctorId, doctorName, byPatient)
//   byPatient=false (default): id is a VISIT id (queue context).
//   byPatient=true:            id is a PATIENT id (all-patients context).
function openIpdAdmit(id, patientName, doctorId, doctorName, byPatient) {
    var vEl = document.getElementById('ipdAdmitVisitId');
    var pEl = document.getElementById('ipdAdmitPatientId');
    if (byPatient) { pEl.value = id; vEl.value = ''; }
    else { vEl.value = id; pEl.value = ''; }
    document.getElementById('ipdAdmitTitle').textContent = patientName || 'Patient';
    var sel = document.getElementById('ipdAdmitDoctor');
    if (doctorId && sel.querySelector('option[value="' + doctorId + '"]')) {
        sel.value = String(doctorId);
        document.getElementById('ipdAdmitDoctorManual').value = '';
    }
    document.getElementById('ipdAdmitOverlay').classList.add('open');
}
function closeIpdAdmit() { document.getElementById('ipdAdmitOverlay').classList.remove('open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeIpdAdmit(); } });
</script>
