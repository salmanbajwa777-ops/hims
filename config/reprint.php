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
 * MUST be emitted INSIDE the document's sheet element (.sheet, or
 * .invoice-container on the refund voucher), and that element needs
 * position:relative. The mark is anchored to the sheet, not the page, which is
 * what keeps it centred on the paper at any window size — and, on the procedure
 * receipt, what keeps it off the consent sheets appended behind it.
 *
 * Design constraints:
 *  - A print-colour-adjust override, because browsers drop backgrounds and
 *    colours in print by default and the mark would vanish on paper.
 *  - Behind the content and light grey, so it never obscures a figure.
 *    Legibility of the amounts beats prominence of the mark.
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
    /* Anchored to the SHEET, not the viewport.
       This was position:fixed and centred with top/left:50%, which pins the mark
       to the browser WINDOW. On screen that dropped it far below the content into
       the blank handwriting area — it only looked right in a window whose height
       happened to equal the sheet's. Absolute + a positioned .sheet centres it on
       the paper at any window size, and print output is unchanged either way.

       Sized in mm, not a viewport unit: these are fixed-size print documents, and
       22mm keeps the whole word inside an A5 diagonal (at 26vmin it overflowed and
       only the middle letters showed, reading as a smudge). The A4 slip is wider
       still, so the same value sits comfortably inside it. */
    .dup-watermark {
        position: absolute; top: 50%; left: 50%;
        transform: translate(-50%, -50%) rotate(-32deg);
        font-family: Arial, Helvetica, sans-serif;
        font-size: 22mm; font-weight: bold; letter-spacing: .06em;
        color: rgba(0, 0, 0, .11);
        white-space: nowrap; pointer-events: none; user-select: none;
        z-index: 0;
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
 * Alias kept for the procedure receipt's call site.
 *
 * There were briefly two variants: a page-fixed one for single-sheet documents
 * and this sheet-scoped one for the procedure receipt, whose consent sheets are
 * further pages of the same document. The fixed variant turned out to be wrong
 * everywhere — it pinned the mark to the browser window rather than the paper —
 * so reprint_watermark() adopted the scoped behaviour and the two collapsed into
 * one. This name simply forwards.
 */
function reprint_watermark_scoped(array $row): string
{
    return reprint_watermark($row);
}
