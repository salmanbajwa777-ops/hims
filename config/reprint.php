<?php
// Reprint marking — a re-issued receipt is a facsimile, not a new document.
//
// Every printable money document (consultation slip, ER bill, procedure bill,
// dental receipt, refund voucher) carries a printed_at / printed_by_id pair that
// records the FIRST time it left a printer. Reception reprints constantly: the
// patient loses the slip, the doctor's copy walks out of the building, the printer
// jams halfway. Two things have to be true of that second copy:
//
//   1. It must be visibly distinguishable from the original, so a duplicate can
//      never be presented at the counter as proof of a second payment.
//   2. Nothing else on it may differ from the original — same amounts, same names,
//      same date, same cashier. A duplicate that disagrees with the original about
//      WHEN it was printed or WHO printed it is a worse artefact than no duplicate
//      at all, because the two copies then contradict each other on paper.
//
// Point 2 is why the partials must not call date() at render time. They render
// print_stamp()'s frozen value instead, which is the stored printed_at — so the
// footer of a slip reprinted in December still reads the July afternoon it was
// first issued.
//
// Requires no migration: printed_at / printed_by_id already exist on all five
// tables (sql/add_billing.sql, add_er_bills.sql, add_procedure_bills.sql,
// add_dental_module.sql, add_refunds.sql).

/**
 * Is this render a reprint?
 *
 * True once the row carries a printed_at, i.e. the document has been printed
 * before. Callers must read the row BEFORE stamping printed_at, otherwise the
 * first print stamps itself and then reports as a duplicate.
 *
 * @param array $row  the bill/payment/refund row
 */
function is_reprint(array $row): bool
{
    return !empty($row['printed_at']);
}

/**
 * The timestamp the document should show — frozen at first print.
 *
 * Falls back to now() only for a document that has never been printed (the
 * original), which is the one render where "now" is the correct answer.
 * Also falls back for rows predating the printed_at backfill, where the column
 * is null on an already-issued document; those print as originals, which is the
 * safe direction — we never claim a document is a duplicate without evidence.
 *
 * printed_at is stored by MySQL in UTC (see fix_timezone.php); strtotime + date
 * render it in the app's Asia/Karachi zone, matching every other displayed time.
 */
function print_stamp(array $row): string
{
    return !empty($row['printed_at'])
        ? date('Y-m-d H:i:s', strtotime($row['printed_at']))
        : date('Y-m-d H:i:s');
}

/**
 * Name of the person who first printed the document, for the "Front Desk" line.
 *
 * Looked up from printed_by_id so a reprint by a different member of staff still
 * credits the original cashier. $fallback is the caller's existing source for the
 * name (the bill's generating user, usually) and is used for never-printed rows.
 */
function print_stamp_by(PDO $pdo, array $row, string $fallback = 'Front Desk'): string
{
    if (empty($row['printed_by_id'])) {
        return $fallback;
    }
    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([(int) $row['printed_by_id']]);
    return $stmt->fetch()['name'] ?? $fallback;
}

/**
 * The DUPLICATE watermark markup, or '' when this is an original.
 *
 * Emitted as a single self-contained block (scoped styles + the element) so each
 * partial adds exactly one call and nothing else. The five partials are separate
 * forks of one design with slightly different class names, so a shared stylesheet
 * would have to be edited into all five anyway — inlining keeps the change to one
 * line per file.
 *
 * Design constraints:
 *  - position:fixed and a print-colour-adjust override, because every one of these
 *    documents is a fixed-size print sheet and browsers drop backgrounds/colours in
 *    print by default.
 *  - Behind the content (z-index -1, on a body that establishes no stacking
 *    context) and light grey, so it never obscures a figure. Legibility of the
 *    amounts beats prominence of the mark.
 *  - Sized in mm, not a viewport unit, so the word fits the printed sheet itself
 *    on both the A5 and the A4 slip (see the rule below).
 */
function reprint_watermark(array $row): string
{
    if (!is_reprint($row)) {
        return '';
    }
    return <<<'HTML'
<style>
    /* Sized so the whole word fits inside an A5 sheet's diagonal — at 26vmin it
       overflowed the page and only the middle letters showed, which reads as a
       smudge rather than as the word DUPLICATE. Fixed mm (not a viewport unit)
       because these sheets are fixed-size print documents; the A4 slip is wider
       still, so the same value stays comfortably inside it. */
    .dup-watermark {
        position: fixed; top: 50%; left: 50%;
        transform: translate(-50%, -50%) rotate(-32deg);
        font-family: Arial, Helvetica, sans-serif;
        font-size: 22mm; font-weight: bold; letter-spacing: .06em;
        color: rgba(0, 0, 0, .11);
        white-space: nowrap; pointer-events: none; user-select: none;
        z-index: -1;
    }
    @media print {
        .dup-watermark {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    }
</style>
<div class="dup-watermark">DUPLICATE</div>
HTML;
}

/**
 * DUPLICATE watermark confined to ONE page of a multi-page document.
 *
 * The procedure receipt prints its signed consent sheets as further pages of the
 * same document. A position:fixed mark repeats on every printed page, which would
 * stamp DUPLICATE across those consent forms — they are signed records in their
 * own right and say nothing about whether the receipt has been printed before.
 * So this variant is absolutely positioned and must be placed INSIDE the receipt's
 * own .sheet (which needs position:relative), keeping the mark on page one.
 *
 * @param array $row  the bill row
 */
function reprint_watermark_scoped(array $row): string
{
    if (!is_reprint($row)) {
        return '';
    }
    return <<<'HTML'
<style>
    /* The receipt .sheet must be position:relative for this to anchor to page one. */
    .dup-watermark-scoped {
        position: absolute; top: 50%; left: 50%;
        transform: translate(-50%, -50%) rotate(-32deg);
        font-family: Arial, Helvetica, sans-serif;
        font-size: 22mm; font-weight: bold; letter-spacing: .06em;
        color: rgba(0, 0, 0, .11);
        white-space: nowrap; pointer-events: none; user-select: none;
        z-index: 0;
    }
    @media print {
        .dup-watermark-scoped {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    }
</style>
<div class="dup-watermark-scoped">DUPLICATE</div>
HTML;
}
