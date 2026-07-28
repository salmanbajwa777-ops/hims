<?php
/**
 * Clinic identity — the ONE place the printed name, tagline and contact block live.
 *
 * Before this file, the same five lines
 *
 *     $clinicName = 'BABY MEDICS'; $clinicTagline = '...'; $clinicEmail = '...';
 *     $clinicPhone = '...'; $clinicWebsite = '...';
 *
 * were copy-pasted into SEVEN print views plus admission_invoice.php, and the
 * street address was additionally hardcoded as raw <div>s in four of them while
 * three others kept it in a $clinicAddress variable. A rename therefore meant
 * editing eleven places and hoping none was missed — exactly the kind of edit
 * where one stale slip survives and prints the old name for months.
 *
 * Everything that appears on a patient-facing document now reads from here.
 *
 * DELIBERATELY NOT COVERED:
 *   - App chrome. The staff-facing UI (sidebar mark, <title>, login) stays
 *     "HIMS" — that is the software's name, not the clinic's.
 *   - Mail. config/mail*.php, mailer.php, notify.php and cron/* still send from
 *     the babymedics domain. Changing that is a DNS + mailbox move; doing it
 *     here would break live email the moment it deployed.
 *   - The Google Sheets tab pattern, which is keyed to existing invoice history.
 *
 * No dependencies — print partials and index.php require this before any
 * session/DB bootstrap, so it must stay require-able from anywhere.
 */

// TWO BRANDS, CHOSEN BY THE TREATING DOCTOR.
//
// The clinic trades as BABY MEDICS; SMILE RESORT is its dental identity. Which
// one a document prints follows the DOCTOR on it, exactly as brand_logo() below
// already chose the logo — a dental doctor's slip is a Smile Resort slip, every
// other slip is a Baby Medics slip.
//
// This used to return SMILE RESORT unconditionally, so a paediatric visit printed
// the dental name over the baby-face logo: the wordmark and the mark disagreed on
// the same sheet. The f29fd01 rebrand noted the logo half of that mismatch as an
// open item; the real fix was that the NAME had to vary too, not that the artwork
// was wrong. Both marks are correct as they stand.
//
// Contact details are shared — one reception desk, one address, one phone — so
// only the name and tagline differ between the two.
//
// Callers pass users.specialty straight in, same as brand_logo(). A NULL
// specialty (an ER bill, a day-closing slip, a doctor share statement — anything
// with no single treating doctor) takes the house brand, which is the clinic's
// own name and the right default for an administrative document.
function brand(?string $specialty = null): array {
    $isDental = $specialty === 'DENTAL';
    return [
        'name'          => $isDental ? 'SMILE RESORT' : 'BABY MEDICS',
        'tagline'       => $isDental ? 'Smile Without the Stress' : 'Premium Healthcare | Vaccines',
        'email'         => 'info@babymedics.com',
        'phone'         => '+92 51 5735006',
        // Hand letter-spaced rather than styled with CSS letter-spacing: the
        // print stylesheet is shared with the metadata column, and spacing the
        // whole footer row threw the alignment off.
        'website'       => 'b a b y m e d i c s . c o m',
        'address'       => 'Polymedics, 2165-F, NPF, PWD Double Road, Islamabad, Pakistan.',
        // The same address split for the two-line footer block. Line 1 is
        // rendered with "Polymedics," bolded by the templates.
        'address_line1' => '2165-F, NPF, PWD Double Road',
        'address_line2' => 'Islamabad, Pakistan.',
        'address_lead'  => 'Polymedics,',
    ];
}

// Which logo a document prints, chosen by the treating doctor's specialty.
//
// The clinic runs ONE reception desk and ONE login; what makes a visit "dental"
// is the DOCTOR selected on it. So the slip follows the doctor: a dental doctor
// prints the tooth mark, everyone else prints the house mark. A NULL specialty
// (an ER bill, a day-closing slip — documents with no treating doctor) also
// takes the house mark.
//
// Callers pass users.specialty straight in. See
// sql/add_doctor_specialty_categories.sql: only DENTAL swaps, every other value
// falls back.
function brand_logo(?string $specialty = null): string {
    return $specialty === 'DENTAL' ? 'logo-dental.png' : 'logo-general.png';
}
