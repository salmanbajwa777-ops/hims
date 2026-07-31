<?php
/**
 * Payment method — the ONE place "how did the money come in" is defined.
 *
 * THE UI OFFERS EXACTLY TWO CHOICES: Cash and Online / Card.
 *
 * That is not a simplification, it is the truth the books already told. Every
 * money bucket in this app splits on `payment_method = 'cash'` vs `<> 'cash'`
 * and nothing else — day_cash_tally() (config/billing.php), the handover stream
 * summary, the drawer ledger, and cron/daily_summary.php. Not one query in the
 * codebase ever asked for 'bank_transfer' or 'cheque' as its own bucket. The
 * four-option <select> that used to sit on six money forms was therefore
 * collecting a distinction the accounts threw away a line later.
 *
 * WRITE TWO, READ FOUR.
 *   - PAY_METHODS_IN gates every form: new rows can only be 'cash' or 'card'.
 *   - pay_method_label() still understands all four, because the DB is full of
 *     historical 'bank_transfer'/'cheque' rows. Those are NOT migrated and the
 *     ENUMs are NOT narrowed — a 2025 invoice must stay reprintable. They fold
 *     into "Online / Card" so a reprint reads like everything else.
 *
 * Before this file the label map
 *
 *     ['cash'=>'Cash','card'=>'Online / Card','bank_transfer'=>'Bank Transfer', ...]
 *
 * was copy-pasted verbatim into four print partials and three more variants that
 * had already drifted ("Card", "Online-Card"), while three surfaces had no map
 * at all and printed the raw enum — ipd_invoice.php handed the patient a slip
 * reading "Paid (bank_transfer)".
 *
 * DELIBERATELY NOT COVERED:
 *   - refunds.refund_mode. Money going OUT is a different column on its own
 *     three-value vocabulary; refund.php and views/refund_print_partial.php keep
 *     their own list.
 *   - expenses.source (CASH_COUNTER/BANK/OWNER). Not a payment method at all.
 *
 * No dependencies — print partials require this before any session/DB
 * bootstrap, so it must stay require-able from anywhere. See config/brand.php,
 * which carries the same constraint for the same reason.
 */

/**
 * What a form may post. The server-side whitelist for every receive-money page.
 * Narrower than the DB ENUM on purpose — see "write two, read four" above.
 */
const PAY_METHODS_IN = ['cash', 'card'];

/**
 * The canonical label for any stored payment_method, on screen or in print.
 *
 * Reads all four ENUM members: the two legacy ones fold into "Online / Card"
 * rather than printing "Bank Transfer" next to a UI that no longer offers it.
 * An unknown/NULL value degrades to an em dash instead of blanking the line.
 */
function pay_method_label(?string $method): string
{
    switch ((string) $method) {
        case 'cash':
            return 'Cash';
        case 'card':
        case 'bank_transfer':   // legacy — folded, see file header
        case 'cheque':          // legacy — folded, see file header
            return 'Online / Card';
        default:
            return '—';
    }
}

/**
 * Normalise a POSTed value to something storable.
 *
 * Anything unrecognised becomes $fallback. Without this a crafted POST reaches
 * the UPDATE, and MySQL in non-strict mode coerces an out-of-ENUM value to '' —
 * which then reads as NOT cash in every `<> 'cash'` tally, quietly moving cash
 * into the online column where it can never be reconciled against the drawer.
 */
function pay_method_in(?string $raw, string $fallback = 'cash'): string
{
    return in_array($raw, PAY_METHODS_IN, true) ? $raw : $fallback;
}

/**
 * The two-button payment control. Drop into any money form:
 *
 *     <?= pay_method_toggle('payment_method', 'cash', 'chk') ?>
 *
 * Styling lives in assets/app.css (.paytoggle). Renders real radios, so it
 * posts, validates and keyboard-navigates like the <select> it replaces.
 *
 * @param string $name    POST field name.
 * @param string $checked Which button starts selected; '' for no default.
 * @param string $idPfx   id prefix, so two controls can share one page.
 * @param bool   $req     Add `required` — only needed when $checked is ''.
 */
function pay_method_toggle(string $name = 'payment_method', string $checked = 'cash', string $idPfx = 'pm', bool $req = false): string
{
    $options = ['cash' => 'Cash', 'card' => 'Online / Card'];
    $nameAttr = htmlspecialchars($name, ENT_QUOTES);

    $out = '<div class="paytoggle" role="radiogroup" aria-label="Payment method">';
    foreach ($options as $value => $label) {
        $id = htmlspecialchars($idPfx . '_' . $value, ENT_QUOTES);
        $out .= '<input type="radio" class="paytoggle-i" name="' . $nameAttr . '"'
            . ' id="' . $id . '" value="' . $value . '"'
            . ($checked === $value ? ' checked' : '')
            . ($req ? ' required' : '')
            . '><label class="paytoggle-b" for="' . $id . '">' . $label . '</label>';
    }

    return $out . '</div>';
}
