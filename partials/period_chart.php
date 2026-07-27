<?php
/**
 * period_chart.php — shared Day/Month/Year/Total bar chart.
 *
 * One chart used by BOTH consoles, so a doctor and an admin can never be
 * looking at two differently-shaped versions of "the month report":
 *   doctor_analytics.php  bars = that doctor's EARNED share
 *   income_report.php     bars = CLINIC INCOME (gross − refunds − tax − shares)
 *
 * Two granularities, mirroring the solar-app reference the user asked for:
 *   month  — one bar PER DAY of the picked month (the headline view)
 *   year   — 12 months, each a PAIR of bars: this year beside last year
 * (An hourly Day view and an all-time Total were built first and cut on
 * 2026-07-27 — neither earned its place beside these two.)
 *
 * Caller contract — set these before requiring this file:
 *   $pcBuckets   array<string|int, float>  bucket key => amount (missing = 0)
 *   $pcKeys      array                     bucket keys in display order
 *   $pcLabels    array                     x-axis label per key (same order)
 *   $pcGran      string                    day|month|year|total
 *   $pcPeriodLbl string                    e.g. "07.2026"
 *   $pcPrevUrl   ?string  $pcNextUrl ?string   period arrows (null = hidden)
 *   $pcTabUrl    callable(string $gran): string
 *   $pcSeriesLbl string                    legend text, e.g. "Revenue"
 *   $pcUnit      string                    axis unit caption, e.g. "PKR"
 *   $pcTipFmt    ?callable(string $label, float $v): string
 *
 * OPTIONAL SECOND SERIES (the Year view's side-by-side comparison):
 *   $pcBuckets2   array   same keys as $pcBuckets — drawn as a paired bar
 *   $pcSeriesLbl2 string  legend text for it, e.g. "2025"
 * With it set, every slot renders TWO bars (current beside previous) so month 1
 * has two bars, month 2 has two, and so on. Without it the chart is a single
 * series and nothing about the other views changes.
 *
 * The bars are a plain inline SVG — the app ships no chart library and this
 * keeps the view printable and dependency-free.
 */

$pcGran     = $pcGran     ?? 'month';
$pcSeriesLbl = $pcSeriesLbl ?? 'Revenue';
$pcUnit     = $pcUnit     ?? 'PKR';
$pcBuckets  = $pcBuckets  ?? [];
$pcKeys     = $pcKeys     ?? [];
$pcLabels   = $pcLabels   ?? [];

// A paired series is opt-in; null/absent keeps the original single-bar chart.
$pcBuckets2   = $pcBuckets2 ?? null;
$pcPaired     = is_array($pcBuckets2);
$pcSeriesLbl2 = $pcSeriesLbl2 ?? 'Previous';

// ---- Axis scale -------------------------------------------------------------
// Both series share one scale, or the comparison would lie.
$pcMax = 0.0;
foreach ($pcKeys as $k) {
    $pcMax = max($pcMax, (float) ($pcBuckets[$k] ?? 0));
    if ($pcPaired) { $pcMax = max($pcMax, (float) ($pcBuckets2[$k] ?? 0)); }
}

// Round the top to a friendly step so gridlines land on readable numbers
// (80 / 60 / 40 …), the way the reference chart does.
if ($pcMax <= 0) {
    $pcAxisMax = 100.0;
} else {
    $pcStep = pow(10, floor(log10($pcMax)));
    $pcAxisMax = ceil($pcMax / $pcStep) * $pcStep;
    // Only one or two steps tall reads as a coarse chart — halve the step.
    if ($pcAxisMax / $pcStep <= 2) {
        $pcAxisMax = ceil($pcMax / ($pcStep / 2)) * ($pcStep / 2);
    }
}

// Compact axis numbers: 1.2M / 45k / 900.
$pcAxisNum = function (float $v): string {
    if ($v >= 1000000) { return rtrim(rtrim(number_format($v / 1000000, 1, '.', ''), '0'), '.') . 'M'; }
    if ($v >= 1000)    { return rtrim(rtrim(number_format($v / 1000, 1, '.', ''), '0'), '.') . 'k'; }
    return (string) round($v);
};

$pcTipFmt = $pcTipFmt ?? function (string $lab, float $v) use ($pcUnit) {
    return $lab . ' — ' . number_format($v) . ' ' . $pcUnit;
};

// ---- Geometry ---------------------------------------------------------------
$pcW = 760; $pcH = 300;
$pcPadL = 46; $pcPadR = 12; $pcPadT = 16; $pcPadB = 30;
$pcPlotW = $pcW - $pcPadL - $pcPadR;
$pcPlotH = $pcH - $pcPadT - $pcPadB;
$pcN     = max(1, count($pcKeys));
$pcSlot  = $pcPlotW / $pcN;
// Bar takes ~62% of its slot, like the reference's airy spacing. A paired slot
// splits that width between the two bars with a hairline gap, so the PAIR still
// occupies the same footprint one bar would and the rhythm stays even.
$pcGap   = $pcPaired ? max(1.0, $pcSlot * 0.05) : 0.0;
$pcBarW  = $pcPaired
    ? max(2.0, min(14.0, ($pcSlot * 0.72 - $pcGap) / 2))
    : max(2.0, min(22.0, $pcSlot * 0.62));
// Pick a band count that divides the axis into ROUND numbers. A fixed 8 bands
// turns a 5,000 top into the unreadable 625 / 1.3k / 1.9k ladder; preferring a
// divisor that lands on whole steps gives 1k / 2k / 3k instead.
$pcGrid = 8;
foreach ([8, 6, 5, 4, 10, 7, 9, 3] as $pcTryBands) {
    $stepv = $pcAxisMax / $pcTryBands;
    // "Round" = a 1/2/5 × power-of-ten step, the ladders people read easily.
    $mag = pow(10, floor(log10(max($stepv, 1e-9))));
    $lead = $stepv / $mag;
    if (abs($lead - round($lead)) < 0.001 && in_array((int) round($lead), [1, 2, 5, 10], true)) {
        $pcGrid = $pcTryBands;
        break;
    }
}

$pcYFor = function (float $v) use ($pcPadT, $pcPlotH, $pcAxisMax) {
    return $pcPadT + $pcPlotH - ($pcAxisMax > 0 ? ($v / $pcAxisMax) * $pcPlotH : 0);
};

// x-axis labels get thinned so they never collide: show every Nth.
$pcEvery = 1;
if ($pcGran === 'month') { $pcEvery = $pcN > 20 ? 3 : 2; }   // 1,4,7,10… like the reference
elseif ($pcGran === 'day') { $pcEvery = 3; }
elseif ($pcN > 12) { $pcEvery = 2; }

$pcTotal = 0.0;
foreach ($pcKeys as $k) { $pcTotal += (float) ($pcBuckets[$k] ?? 0); }
$pcNonZero = 0;
foreach ($pcKeys as $k) { if ((float) ($pcBuckets[$k] ?? 0) > 0) { $pcNonZero++; } }
$pcPeak = $pcMax;
$pcPeakLabel = '';
foreach ($pcKeys as $i => $k) {
    if ((float) ($pcBuckets[$k] ?? 0) >= $pcPeak && $pcPeak > 0) {
        $pcPeakLabel = $pcLabels[$i] ?? '';
        break;
    }
}
?>
<div class="pchart">
    <div class="pchart-bar">
        <div class="pchart-seg" role="group" aria-label="Chart granularity">
            <?php // Month and Year only (2026-07-27): a 24-bar hourly Day view and an
                  // all-time Total weren't useful next to the two that are.
                  foreach (['month' => 'Month', 'year' => 'Year'] as $g => $lab): ?>
            <a class="<?= $pcGran === $g ? 'on' : '' ?>" href="<?= htmlspecialchars($pcTabUrl($g)) ?>"><?= $lab ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="pchart-nav">
        <?php if (!empty($pcPrevUrl)): ?>
        <a class="pchart-arrow" href="<?= htmlspecialchars($pcPrevUrl) ?>" aria-label="Previous period">&lsaquo;</a>
        <?php else: ?><span class="pchart-arrow off" aria-hidden="true">&lsaquo;</span><?php endif; ?>

        <span class="pchart-period">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
                <rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 3v3M16 3v3"/>
            </svg>
            <b class="tnum"><?= htmlspecialchars($pcPeriodLbl) ?></b>
        </span>

        <?php if (!empty($pcNextUrl)): ?>
        <a class="pchart-arrow" href="<?= htmlspecialchars($pcNextUrl) ?>" aria-label="Next period">&rsaquo;</a>
        <?php else: ?><span class="pchart-arrow off" aria-hidden="true">&rsaquo;</span><?php endif; ?>
    </div>

    <div class="pchart-legend">
        <span class="pchart-unit"><?= htmlspecialchars($pcUnit) ?></span>
        <span class="pchart-key"><i></i><?= htmlspecialchars($pcSeriesLbl) ?></span>
        <?php if ($pcPaired): ?>
        <span class="pchart-key alt"><i></i><?= htmlspecialchars($pcSeriesLbl2) ?></span>
        <?php endif; ?>
    </div>

    <div class="pchart-plot">
    <svg viewBox="0 0 <?= $pcW ?> <?= $pcH ?>" preserveAspectRatio="xMidYMid meet"
         role="img" aria-label="<?= htmlspecialchars($pcSeriesLbl . ' by ' . $pcGran . ' for ' . $pcPeriodLbl) ?>">
        <defs>
            <linearGradient id="pcFill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%"   stop-color="var(--primary-accent)" stop-opacity="1"/>
                <stop offset="100%" stop-color="var(--primary-accent)" stop-opacity=".18"/>
            </linearGradient>
            <?php if ($pcPaired): ?>
            <!-- Previous period: same gradient shape, muted, so the current
                 year stays the figure the eye lands on first. -->
            <linearGradient id="pcFill2" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%"   stop-color="var(--text-secondary)" stop-opacity=".55"/>
                <stop offset="100%" stop-color="var(--text-secondary)" stop-opacity=".10"/>
            </linearGradient>
            <?php endif; ?>
        </defs>

        <!-- dashed gridlines + value ladder -->
        <?php for ($g = 0; $g <= $pcGrid; $g++):
            $gy = $pcPadT + $pcPlotH * $g / $pcGrid;
            $gv = $pcAxisMax * (1 - $g / $pcGrid);
            $isBase = ($g === $pcGrid); ?>
        <line x1="<?= $pcPadL ?>" y1="<?= round($gy, 1) ?>" x2="<?= $pcW - $pcPadR ?>" y2="<?= round($gy, 1) ?>"
              stroke="<?= $isBase ? 'var(--border-strong)' : 'var(--border)' ?>"
              stroke-width="1"<?= $isBase ? '' : ' stroke-dasharray="4 5"' ?>/>
        <text class="pchart-ax" x="<?= $pcPadL - 8 ?>" y="<?= round($gy + 3.5, 1) ?>" text-anchor="end"><?= $pcAxisNum($gv) ?></text>
        <?php endfor; ?>

        <!-- bars -->
        <?php foreach ($pcKeys as $i => $k):
            $v = (float) ($pcBuckets[$k] ?? 0);
            $lab = (string) ($pcLabels[$i] ?? $k);
            $slotX = $pcPadL + $pcSlot * $i;
            $y = $pcYFor($v);
            $h = $pcYFor(0) - $y;
            $rx = $pcBarW > 8 ? 3 : 1.5;

            if ($pcPaired) {
                // Current bar sits left of centre, previous right, the pair
                // centred in the slot so month 1 has two bars, month 2 two, …
                $pairW = 2 * $pcBarW + $pcGap;
                $x  = $slotX + ($pcSlot - $pairW) / 2;
                $x2 = $x + $pcBarW + $pcGap;
                $v2 = (float) ($pcBuckets2[$k] ?? 0);
                $y2 = $pcYFor($v2);
                $h2 = $pcYFor(0) - $y2;
                $tip = $lab . ' — ' . $pcSeriesLbl . ': ' . number_format($v)
                     . ' · ' . $pcSeriesLbl2 . ': ' . number_format($v2) . ' ' . $pcUnit;
            } else {
                $x = $slotX + ($pcSlot - $pcBarW) / 2;
                $tip = $pcTipFmt($lab, $v);
            }
        ?>
        <g class="pchart-b">
            <title><?= htmlspecialchars($tip) ?></title>
            <!-- full-height hit area so hovering an empty slot still reads its tooltip -->
            <rect x="<?= round($slotX, 1) ?>" y="<?= $pcPadT ?>" width="<?= round($pcSlot, 1) ?>" height="<?= $pcPlotH ?>" fill="transparent"/>
            <?php if ($pcPaired && $v2 > 0): ?>
            <rect class="pchart-fill" x="<?= round($x2, 1) ?>" y="<?= round($y2, 1) ?>"
                  width="<?= round($pcBarW, 1) ?>" height="<?= round(max(1.5, $h2), 1) ?>"
                  rx="<?= $rx ?>" fill="url(#pcFill2)"/>
            <?php endif; ?>
            <?php if ($v > 0): ?>
            <rect class="pchart-fill" x="<?= round($x, 1) ?>" y="<?= round($y, 1) ?>"
                  width="<?= round($pcBarW, 1) ?>" height="<?= round(max(1.5, $h), 1) ?>"
                  rx="<?= $rx ?>" fill="url(#pcFill)"/>
            <?php endif; ?>
            <?php if ($i % $pcEvery === 0): ?>
            <text class="pchart-ax" text-anchor="middle" x="<?= round($slotX + $pcSlot / 2, 1) ?>" y="<?= $pcH - $pcPadB + 18 ?>"><?= htmlspecialchars($lab) ?></text>
            <?php endif; ?>
        </g>
        <?php endforeach; ?>
    </svg>
    </div>

    <div class="pchart-foot">
        <div class="pcf">
            <span class="pcf-k">Total</span>
            <span class="pcf-v tnum"><?= number_format($pcTotal) ?> <?= htmlspecialchars($pcUnit) ?></span>
        </div>
        <div class="pcf">
            <span class="pcf-k">Best <?= $pcGran === 'month' ? 'day' : 'month' ?></span>
            <span class="pcf-v tnum"><?= $pcPeak > 0 ? htmlspecialchars($pcPeakLabel) . ' · ' . number_format($pcPeak) : '—' ?></span>
        </div>
        <div class="pcf">
            <span class="pcf-k">Average</span>
            <span class="pcf-v tnum"><?= $pcNonZero > 0 ? number_format($pcTotal / $pcNonZero) : '0' ?> <?= htmlspecialchars($pcUnit) ?></span>
        </div>
    </div>
</div>
