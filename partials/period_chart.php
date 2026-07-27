<?php
/**
 * period_chart.php — shared Day/Month/Year/Total bar chart.
 *
 * One chart used by BOTH consoles, so a doctor and an admin can never be
 * looking at two differently-shaped versions of "the month report":
 *   doctor_analytics.php  bars = that doctor's EARNED share
 *   income_report.php     bars = CLINIC INCOME (gross − refunds − tax − shares)
 *
 * The four granularities mirror the solar-app reference the user asked for:
 *   day    — 24 hourly bars for one date
 *   month  — one bar PER DAY of the picked month (the headline view)
 *   year   — 12 monthly bars
 *   total  — one bar per year since the first record
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
 * The bars are a plain inline SVG — the app ships no chart library and this
 * keeps the view printable and dependency-free.
 */

$pcGran     = $pcGran     ?? 'month';
$pcSeriesLbl = $pcSeriesLbl ?? 'Revenue';
$pcUnit     = $pcUnit     ?? 'PKR';
$pcBuckets  = $pcBuckets  ?? [];
$pcKeys     = $pcKeys     ?? [];
$pcLabels   = $pcLabels   ?? [];

// ---- Axis scale -------------------------------------------------------------
$pcMax = 0.0;
foreach ($pcKeys as $k) { $pcMax = max($pcMax, (float) ($pcBuckets[$k] ?? 0)); }

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
// Bar takes ~62% of its slot, like the reference's airy spacing.
$pcBarW  = max(2.0, min(22.0, $pcSlot * 0.62));
$pcGrid  = 8;   // 8 bands → the reference's 0,10,20…80 ladder

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
            <?php foreach (['day' => 'Day', 'month' => 'Month', 'year' => 'Year', 'total' => 'Total'] as $g => $lab): ?>
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
    </div>

    <div class="pchart-plot">
    <svg viewBox="0 0 <?= $pcW ?> <?= $pcH ?>" preserveAspectRatio="xMidYMid meet"
         role="img" aria-label="<?= htmlspecialchars($pcSeriesLbl . ' by ' . $pcGran . ' for ' . $pcPeriodLbl) ?>">
        <defs>
            <linearGradient id="pcFill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%"   stop-color="var(--primary-accent)" stop-opacity="1"/>
                <stop offset="100%" stop-color="var(--primary-accent)" stop-opacity=".18"/>
            </linearGradient>
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
            $x = $slotX + ($pcSlot - $pcBarW) / 2;
            $y = $pcYFor($v);
            $h = $pcYFor(0) - $y;
        ?>
        <g class="pchart-b">
            <title><?= htmlspecialchars($pcTipFmt($lab, $v)) ?></title>
            <!-- full-height hit area so hovering an empty day still reads its tooltip -->
            <rect x="<?= round($slotX, 1) ?>" y="<?= $pcPadT ?>" width="<?= round($pcSlot, 1) ?>" height="<?= $pcPlotH ?>" fill="transparent"/>
            <?php if ($v > 0): ?>
            <rect class="pchart-fill" x="<?= round($x, 1) ?>" y="<?= round($y, 1) ?>"
                  width="<?= round($pcBarW, 1) ?>" height="<?= round(max(1.5, $h), 1) ?>"
                  rx="<?= $pcBarW > 8 ? 3 : 1.5 ?>" fill="url(#pcFill)"/>
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
            <span class="pcf-k">Best <?= $pcGran === 'day' ? 'hour' : ($pcGran === 'month' ? 'day' : ($pcGran === 'year' ? 'month' : 'year')) ?></span>
            <span class="pcf-v tnum"><?= $pcPeak > 0 ? htmlspecialchars($pcPeakLabel) . ' · ' . number_format($pcPeak) : '—' ?></span>
        </div>
        <div class="pcf">
            <span class="pcf-k">Average</span>
            <span class="pcf-v tnum"><?= $pcNonZero > 0 ? number_format($pcTotal / $pcNonZero) : '0' ?> <?= htmlspecialchars($pcUnit) ?></span>
        </div>
    </div>
</div>
