<?php
// Procedure consent sheet, appended to the A5 procedure slip.
//
// Expects $bill (procedure_bills joined to patient + doctor), $consents (rows
// from consent_for_bill()) and the brand variables already in scope — this
// partial is included from views/procedure_invoice_print_partial.php AFTER the
// receipt's .sheet has closed, so it emits only <div class="sheet"> blocks and
// no document chrome of its own.
//
// TWO COPIES PER CONSENT, and that is the whole point of the document: the
// clinic keeps one signed and the patient takes the other. They are identical
// apart from the copy tag, so a dispute is settled by comparing two sheets that
// say the same thing.
//
// A5 to match the receipt. The original paper form was A4 with the invoice
// printed above the consent, but the receipt is its own sheet here, which frees
// the consent to be A5 like every other document the clinic prints — one paper
// size in the drawer, one tray in the printer.
//
// THE WORDING IS NOT RENDERED FROM THE CATALOGUE. consent_text was frozen onto
// the row when the bill was raised; this reprints it verbatim. An admin editing
// the template must not retroactively change what a patient already signed.

$copyTags = [
    'CLINIC COPY — RETAIN IN RECORD',
    'PATIENT COPY',
];
?>
<?php foreach ($consents as $consent): ?>
<?php foreach ($copyTags as $copyTag): ?>
    <div class="sheet consent-sheet">

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

        <div class="doctype">CONSENT FOR <?= htmlspecialchars(mb_strtoupper($consent['procedure_ref'], 'UTF-8')) ?> PROCEDURE</div>
        <div class="copytag"><?= htmlspecialchars($copyTag) ?></div>

        <table class="pt">
            <tr>
                <td class="k">Patient</td><td class="v"><?= htmlspecialchars($patientNameUpper) ?></td>
                <td class="k">MR #</td><td class="v"><?= htmlspecialchars($bill['mrn']) ?></td>
            </tr>
            <tr>
                <td class="k">S/D/W Of</td><td class="v"><?= htmlspecialchars($fatherNameUpper) ?></td>
                <td class="k">Date of birth</td><td><?= $patientDobDisplay ?></td>
            </tr>
            <tr>
                <td class="k">Doctor</td><td class="v"><?= htmlspecialchars($doctorNameUpper) ?></td>
                <td class="k">Invoice #</td><td class="v"><?= htmlspecialchars($bill['invoice_number']) ?></td>
            </tr>
        </table>

        <div class="body-text">
            <?php
            // consent_text was frozen as plain text with the signer fields
            // already substituted (or left empty). Re-running it through
            // consent_render() in PRINT mode is what turns an empty signer into
            // a rule to write on, and escapes the rest for output.
            //
            // The vars passed are the ones ALREADY baked into the frozen text,
            // so this second pass changes nothing but the signer blanks — it
            // cannot reintroduce a value that was not there at signing.
            echo '<p>' . consent_render(
                $consent['consent_text'],
                [
                    'signer_name'     => (string) ($consent['signed_name'] ?? ''),
                    'signer_relation' => (string) ($consent['signed_relation'] ?? ''),
                ],
                true
            ) . '</p>';
            ?>
            <?php if (empty($consent['signed_name'])): ?>
            <p class="fillhint">First blank: name of the person signing &nbsp;&middot;&nbsp; second blank: relation to the patient.</p>
            <?php endif; ?>
        </div>

        <div class="sect">Declaration</div>
        <div class="body-text">
            <p>I confirm that the procedure has been explained to me in a language I understand, and that I
            have had the opportunity to ask questions and have them answered to my satisfaction. I confirm
            that I am legally entitled to give consent on behalf of the patient.</p>
        </div>

        <div class="sigblock">
            <div class="sigbox2">
                <div class="sigline2">Consent given by</div>
                <div class="sigsub">Name: <?= !empty($consent['signed_name']) ? htmlspecialchars($consent['signed_name']) : '____________________' ?></div>
                <div class="sigsub">Relation: <?= !empty($consent['signed_relation']) ? htmlspecialchars($consent['signed_relation']) : '__________________' ?></div>
                <div class="sigsub">Signature: _________________</div>
                <div class="sigsub">Date: _____________________</div>
            </div>
            <div class="sigbox2">
                <div class="sigline2">Doctor</div>
                <div class="sigsub">Name: <?= htmlspecialchars($doctorNameUpper) ?></div>
                <div class="sigsub">Signature: _________________</div>
                <div class="sigsub">Date: _____________________</div>
            </div>
            <div class="sigbox2">
                <div class="sigline2">Witness</div>
                <div class="sigsub">Name: ____________________</div>
                <div class="sigsub">Signature: _________________</div>
                <div class="sigsub">Date: _____________________</div>
            </div>
        </div>

        <div class="foot">
            <span>Consent ref #<?= (int) $consent['id'] ?> &middot; Invoice <?= htmlspecialchars($bill['invoice_number']) ?> &middot; printed <?= $printTimestamp ?></span>
            <span><?= $clinicName ?> &middot; <?= $clinicPhone ?></span>
        </div>
    </div>
<?php endforeach; ?>
<?php endforeach; ?>
