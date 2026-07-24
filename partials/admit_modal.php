<?php
/**
 * Shared "Admit patient" modal + openAdmit()/closeAdmit() JS. Included by any page
 * with an Admit action (receptionist.php, patients.php, doctor.php).
 *
 * Expects in scope:
 *   $admitFormAction  — where the form POSTs (defaults to the current script).
 *   $admTypes         — [['admission_type','rate_amount','rate_basis'], ...] (enabled).
 *   $admDoctors       — [['id','name'], ...] system doctors for the picker.
 *   $admTypeLabels    — ['ROUTINE'=>'Routine', ...] display labels.
 *
 * openAdmit(idPayload) accepts either a visit id (queue context) OR a patient id
 * (all-patients context) — pass which via the second arg. The hidden field is chosen
 * accordingly so the shared handler resolves/creates the visit as needed.
 */
$admitFormAction = $admitFormAction ?? basename($_SERVER['SCRIPT_NAME'] ?? 'receptionist.php');
$admTypes = $admTypes ?? [];
$admDoctors = $admDoctors ?? [];
$admTypeLabels = $admTypeLabels ?? ['ROUTINE' => 'Routine', 'PRIVATE' => 'Private Room', 'LONG_PRIVATE' => 'Long Private'];
?>
<style>
.admit-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 60; align-items: center; justify-content: center; padding: 20px; }
.admit-overlay.open { display: flex; }
.admit-modal { background: var(--card,#fff); border-radius: 16px; width: 100%; max-width: 460px; box-shadow: 0 20px 50px rgba(0,0,0,.25); overflow: hidden; }
.admit-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 22px 6px; }
.admit-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted,#64748b); }
.admit-name { font-size: 18px; font-weight: 700; margin-top: 2px; }
.admit-x { background: none; border: none; font-size: 24px; line-height: 1; color: var(--text-muted,#64748b); cursor: pointer; }
.admit-body { padding: 10px 22px 4px; display: flex; flex-direction: column; gap: 16px; }
.admit-field > label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-secondary,#475569); margin-bottom: 8px; }
.type-opts { display: flex; flex-direction: column; gap: 8px; }
.type-opt { display: flex; align-items: center; gap: 12px; border: 1px solid var(--border,#e2e8f0); border-radius: 12px; padding: 11px 14px; cursor: pointer; }
.type-opt:has(input:checked) { border-color: var(--primary,#1a7f7e); background: var(--primary-light,#e6f4f4); }
.type-opt .type-body { display: flex; flex-direction: column; }
.type-opt .type-name { font-size: 13.5px; font-weight: 600; }
.type-opt .type-rate { font-size: 12px; color: var(--text-muted,#64748b); }
.admit-field select, .admit-field input[type="text"] { width: 100%; padding: 10px 12px; border: 1px solid var(--border,#e2e8f0); border-radius: 12px; font: inherit; font-size: 13.5px; background: var(--bg,#f8fafc); }
.admit-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 22px 20px; }
</style>
<div class="admit-overlay" id="admitOverlay" onclick="if(event.target===this)closeAdmit()">
    <div class="admit-modal" role="dialog" aria-modal="true" aria-labelledby="admitTitle">
        <form method="POST" action="<?= htmlspecialchars($admitFormAction) ?>">
            <input type="hidden" name="action" value="admit_patient">
            <input type="hidden" name="visit_id" id="admitVisitId" value="">
            <input type="hidden" name="patient_id" id="admitPatientId" value="">
            <div class="admit-head">
                <div>
                    <div class="admit-eyebrow">Admit patient</div>
                    <div class="admit-name" id="admitTitle">—</div>
                </div>
                <button type="button" class="admit-x" onclick="closeAdmit()" aria-label="Close">&times;</button>
            </div>

            <div class="admit-body">
                <div class="admit-field">
                    <label>Admission type</label>
                    <div class="type-opts">
                        <?php foreach ($admTypes as $i => $t): ?>
                        <label class="type-opt">
                            <input type="radio" name="admission_type" value="<?= htmlspecialchars($t['admission_type']) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                            <span class="type-body">
                                <span class="type-name"><?= htmlspecialchars($admTypeLabels[$t['admission_type']] ?? $t['admission_type']) ?></span>
                                <span class="type-rate">Rs <?= number_format((float) $t['rate_amount']) ?>/<?= $t['rate_basis'] === 'DAILY' ? 'day' : 'hr' ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                        <?php if (!$admTypes): ?>
                        <div class="muted">No admission types are enabled. Set them under ER Services &amp; Rates.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="admit-field">
                    <label>Admitting doctor</label>
                    <select name="admitting_doctor_id" id="admitDoctor">
                        <option value="">— manual entry below —</option>
                        <?php foreach ($admDoctors as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="admitting_doctor_manual" id="admitDoctorManual" class="uc" placeholder="Or type the doctor's name" style="margin-top:8px;">
                </div>
            </div>

            <div class="admit-foot">
                <button type="button" class="btn secondary" onclick="closeAdmit()">Cancel</button>
                <button type="submit" class="btn" <?= $admTypes ? '' : 'disabled' ?>>Admit &amp; start stay</button>
            </div>
        </form>
    </div>
</div>
<script>
// openAdmit(id, patientName, doctorId, doctorName, byPatient)
//   byPatient=false (default): id is a VISIT id (queue context).
//   byPatient=true:            id is a PATIENT id (all-patients context) — the handler
//                              reuses today's visit or creates a shell.
function openAdmit(id, patientName, doctorId, doctorName, byPatient) {
    var vEl = document.getElementById('admitVisitId');
    var pEl = document.getElementById('admitPatientId');
    if (byPatient) { pEl.value = id; vEl.value = ''; }
    else { vEl.value = id; pEl.value = ''; }
    document.getElementById('admitTitle').textContent = patientName || 'Patient';
    var sel = document.getElementById('admitDoctor');
    if (doctorId && sel.querySelector('option[value="' + doctorId + '"]')) {
        sel.value = String(doctorId);
        document.getElementById('admitDoctorManual').value = '';
    }
    document.getElementById('admitOverlay').classList.add('open');
}
function closeAdmit() { document.getElementById('admitOverlay').classList.remove('open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAdmit(); } });
</script>
