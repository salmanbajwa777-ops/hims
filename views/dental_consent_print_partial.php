<?php
// Dental consent form, included from dental_consent.php's ?print= branch.
// Expects $consent (a dental_consents row joined to patient/doctor/account) and
// $snapshot (the decoded financial_snapshot, or null).
//
// A4 rather than A5, unlike every other document here: this one is signed and
// filed, needs room for real wording, and gets scanned back in — an A5 scan of
// small print is unpleasant to read on screen.
//
// THE SNAPSHOT IS PRINTED, NOT RECOMPUTED. $snapshot was frozen when the
// consent was generated and is rendered verbatim. Items added or voided since
// then have moved the live account, but they must not move what the patient
// signed — that figure is the agreement. This is the single reason
// financial_snapshot exists as a column instead of being derived on the fly.

require_once __DIR__ . '/../config/brand.php';
$b = brand($consent['doctor_specialty'] ?? null);
$clinicName = $b['name'];
$clinicTagline = $b['tagline'];
$clinicEmail = $b['email'];
$clinicPhone = $b['phone'];
$clinicWebsite = $b['website'];

$logoFile = brand_logo($consent['doctor_specialty'] ?? null);

$patientNameUpper = mb_strtoupper($consent['patient_name'], 'UTF-8');
$fatherNameUpper = $consent['father_name'] ? mb_strtoupper($consent['father_name'], 'UTF-8') : '';
$doctorNameUpper = mb_strtoupper($consent['doctor_name'] ?? '', 'UTF-8');
$patientDobDisplay = $consent['dob'] ? date('d/m/Y', strtotime($consent['dob'])) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dental Consent - <?= htmlspecialchars($consent['patient_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 210mm; margin: 0; padding: 0; }
        body { font-family: 'Lora', Georgia, 'Times New Roman', serif; font-size: 11px; line-height: 1.45; color: #000; background: #fff; }
        .sheet { width: 100%; padding: 12mm 14mm 8mm; display: flex; flex-direction: column; min-height: 297mm; }

        .head-box { border: 1px solid #B0B0B0; padding: 4mm 5mm 2mm; display: flex; gap: 6mm; }
        .head-left, .head-right { width: 50%; display: flex; flex-direction: column; }
        .band-1 { height: 38px; display: flex; align-items: center; }
        .band-2 { height: 30px; display: flex; flex-direction: column; justify-content: center; }
        .brandline { display: flex; align-items: center; gap: 4px; }
        .clinic-logo { height: 38px; display: block; background: #fff; }
        .brandtext { display: flex; flex-direction: column; justify-content: center; height: 38px; }
        .clinic-name { font-family: Arial, Helvetica, sans-serif; font-size: 23px; font-weight: bold; letter-spacing: .2px; color: #0F7362; white-space: nowrap; line-height: 1; }
        .website { font-family: Arial, Helvetica, sans-serif; font-size: 9px; font-weight: bold; letter-spacing: 2px; color: #4A4A4A; line-height: 1; margin-top: 3px; }
        .addr { font-size: 10px; line-height: 1.4; }
        .tagline { font-family: Arial, Helvetica, sans-serif; font-size: 17px; font-weight: bold; line-height: 1.15; }

        .doctype { text-align: center; font-weight: bold; font-size: 15px; letter-spacing: 3px; margin: 6mm 0 4mm; }

        .pt { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        .pt td { border: 1px solid #C8C8C8; padding: 2px 6px; height: 22px; vertical-align: middle; }
        .pt td.k { background: #F4F4F4; font-weight: bold; width: 18%; }
        .pt td.v { font-weight: bold; width: 32%; }

        .sect { font-size: 11px; font-weight: bold; letter-spacing: 1.2px; margin: 6mm 0 2mm; text-transform: uppercase; border-bottom: 1px solid #B0B0B0; padding-bottom: 2px; }

        .body-text { font-size: 11px; line-height: 1.6; text-align: justify; }
        .body-text p { margin-bottom: 3mm; }
        .body-text ul { margin: 0 0 3mm 6mm; }
        .body-text li { margin-bottom: 1.5mm; }

        .tbl { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        .tbl td, .tbl th { border: 1px solid #C8C8C8; padding: 2px 6px; height: 21px; vertical-align: middle; }
        .tbl th { background: #F4F4F4; font-weight: bold; text-align: left; }
        .tbl .num { text-align: right; }
        .tbl .ctr { text-align: center; }
        .tbl tr.total td { background: #F4F4F4; font-weight: bold; border-top: 1.5px solid #000; }
        .tbl tr.agreed td { background: #EDEDED; font-weight: bold; font-size: 12px; height: 26px; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; }

        .frozen-note { font-size: 9.5px; color: #444; font-style: italic; margin-top: 2mm; }

        .sigblock { display: flex; justify-content: space-between; gap: 14mm; margin-top: auto; padding-top: 14mm; }
        .sigbox { flex: 1; }
        .sigline { border-top: 1px solid #444; padding-top: 3px; font-size: 10px; font-weight: bold; }
        .sigsub { font-size: 9px; color: #555; margin-top: 1px; }

        .foot { display: flex; justify-content: space-between; gap: 10px; border-top: 1px solid #B0B0B0; margin-top: 6mm; padding-top: 3px; font-size: 8.5px; }

        @media print {
            html, body { width: 210mm; }
            .sheet { min-height: 297mm; padding: 12mm 14mm 8mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A4; margin: 0; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="sheet">

        <div class="head-box">
            <div class="head-left">
                <div class="band-1">
                    <div class="brandline">
                        <img class="clinic-logo" src="assets/images/<?= htmlspecialchars($logoFile) ?>" alt="">
                        <span class="brandtext">
                            <span class="clinic-name"><?= $clinicName ?></span>
                            <span class="website"><?= $clinicWebsite ?></span>
                        </span>
                    </div>
                </div>
                <div class="band-2 addr">
                    <div><b><?= htmlspecialchars($b['address_lead']) ?></b> <?= htmlspecialchars($b['address_line1']) ?></div>
                    <div><?= htmlspecialchars($b['address_line2']) ?></div>
                </div>
            </div>
            <div class="head-right">
                <div class="band-1"><div class="tagline"><?= $clinicTagline ?></div></div>
                <div class="band-2 addr">
                    <div><b>Email:</b> <?= $clinicEmail ?></div>
                    <div><b>Phone:</b> <?= $clinicPhone ?></div>
                </div>
            </div>
        </div>

        <div class="doctype">DENTAL TREATMENT CONSENT</div>

        <table class="pt">
            <tr>
                <td class="k">Patient</td><td class="v"><?= htmlspecialchars($patientNameUpper) ?></td>
                <td class="k">MR #</td><td class="v"><?= htmlspecialchars($consent['mrn']) ?></td>
            </tr>
            <tr>
                <td class="k">S/D/W Of</td><td class="v"><?= htmlspecialchars($fatherNameUpper) ?></td>
                <td class="k">Date of birth</td><td><?= $patientDobDisplay ?></td>
            </tr>
            <tr>
                <td class="k">Dentist</td><td class="v"><?= htmlspecialchars($doctorNameUpper) ?></td>
                <td class="k">Date</td><td><?= date('d/m/Y') ?></td>
            </tr>
        </table>

        <div class="sect">Treatment proposed</div>
        <div class="body-text">
            <p><b><?= htmlspecialchars($consent['procedure_ref'] ?: 'Dental treatment') ?></b></p>
            <?php if ($consent['consent_text']): ?>
            <p><?= nl2br(htmlspecialchars($consent['consent_text'])) ?></p>
            <?php endif; ?>
        </div>

        <div class="sect">Declaration</div>
        <div class="body-text">
            <p>I confirm that the proposed treatment has been explained to me in a language I understand, and that I have had the opportunity to ask questions and have them answered to my satisfaction.</p>
            <p>I understand that:</p>
            <ul>
                <li>Dentistry is not an exact science, and no guarantee has been given to me as to the result of the treatment.</li>
                <li>The treatment may involve local anaesthesia, and that discomfort, swelling or bruising can follow.</li>
                <li>Further or altered treatment may prove necessary once work has begun, and that this would be discussed with me and may change the cost.</li>
                <li>Failure to complete the treatment, or to attend for review, may compromise the result.</li>
                <li>I should tell the dentist about any medical condition, allergy or medication before treatment begins.</li>
            </ul>
            <p>I consent to the treatment described above being carried out.</p>
        </div>

        <?php if ($snapshot): ?>
        <!-- FINANCIAL CONTRACT. For a package this consent is also the money
             agreement, so the itemised quote is frozen into it at signing. -->
        <div class="sect">Agreed cost of treatment — account <?= htmlspecialchars($snapshot['account_number']) ?></div>
        <table class="tbl">
            <colgroup>
                <col style="width:46%"><col style="width:10%"><col style="width:8%">
                <col style="width:18%"><col style="width:18%">
            </colgroup>
            <thead>
                <tr><th>Treatment</th><th class="ctr">Tooth</th><th class="ctr">Qty</th>
                    <th class="num">Fee (Rs)</th><th class="num">Lab (Rs)</th></tr>
            </thead>
            <tbody>
                <?php foreach (($snapshot['items'] ?? []) as $it): ?>
                <tr>
                    <td><?= htmlspecialchars($it['description'] ?? '') ?></td>
                    <td class="ctr"><?= !empty($it['tooth_fdi']) ? htmlspecialchars($it['tooth_fdi']) : '—' ?></td>
                    <td class="ctr"><?= (int) ($it['quantity'] ?? 1) ?></td>
                    <td class="num"><?= number_format((float) ($it['amount'] ?? 0), 2) ?></td>
                    <td class="num"><?= (float) ($it['lab_charge'] ?? 0) > 0 ? number_format((float) $it['lab_charge'], 2) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ((float) ($snapshot['lab'] ?? 0) > 0): ?>
                <tr><td colspan="4">Laboratory charges</td><td class="num"><?= number_format((float) $snapshot['lab'], 2) ?></td></tr>
                <?php endif; ?>
                <tr class="agreed"><td colspan="4">TOTAL AGREED</td><td class="num"><?= number_format((float) ($snapshot['total'] ?? 0), 2) ?></td></tr>
                <tr class="total"><td colspan="4">Paid at signing</td><td class="num"><?= number_format((float) ($snapshot['paid_upfront'] ?? 0), 2) ?></td></tr>
                <tr class="total"><td colspan="4">Balance payable over treatment</td><td class="num"><?= number_format((float) ($snapshot['remaining'] ?? 0), 2) ?></td></tr>
            </tbody>
        </table>
        <div class="frozen-note">
            Figures as agreed on <?= !empty($snapshot['frozen_at']) ? date('d/m/Y', strtotime($snapshot['frozen_at'])) : date('d/m/Y') ?>.
            Any additional treatment agreed later will be quoted separately.
            Consultation fees are billed at each visit and are not part of this account.
        </div>
        <?php endif; ?>

        <div class="sigblock">
            <div class="sigbox">
                <div class="sigline">Patient / Parent / Guardian</div>
                <div class="sigsub">Name: <?= $consent['signed_name'] ? htmlspecialchars($consent['signed_name']) : '________________________' ?><?= $consent['signed_relation'] ? ' (' . htmlspecialchars($consent['signed_relation']) . ')' : '' ?></div>
                <div class="sigsub">Date: ____________________</div>
            </div>
            <div class="sigbox">
                <div class="sigline">Dentist</div>
                <div class="sigsub">Name: <?= $doctorNameUpper ? htmlspecialchars($doctorNameUpper) : '________________________' ?></div>
                <div class="sigsub">Date: ____________________</div>
            </div>
            <div class="sigbox">
                <div class="sigline">Witness</div>
                <div class="sigsub">Name: ________________________</div>
                <div class="sigsub">Date: ____________________</div>
            </div>
        </div>

        <div class="foot">
            <span>Consent ref #<?= (int) $consent['id'] ?> &middot; printed <?= date('Y-m-d H:i:s') ?></span>
            <span><?= $clinicName ?> &middot; <?= $clinicPhone ?></span>
        </div>
    </div>
    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
