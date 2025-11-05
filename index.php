<style>
    .win { color: darkgreen; }
    .loss { color: darkred; }
    </style>
<?php
$file = "../data/TSLA.USUSD_Candlestick_1_M_BID_01.01.2025-14.06.2025_NOFLATS.csv";
$headings = ['time', 'open', 'high', 'low', 'close', 'volume'];
$data = get_csv($file, $headings, ignore_first_row: true);
format_data($data);

// expects minute bars - no premarket
// tracks day open, day high/low, 5-min open range

date_default_timezone_set('America/New_York');


// todo week high/low
// todo scale based on rating, rate by previous day/week range

$retests = [
    "inRange" => 0,
    "aboveRange" => 0,
    "belowRange" => 0,
];
$wins = [
    "inRange" => [],
    "aboveRange" => [],
    "belowRange" => [],
];
$losses = [
    "inRange" => [],
    "aboveRange" => [],
    "belowRange" => [],
];

$prevDaybar = null;
echo "<pre>";
$doi = null; // day open index
$doBar = null; // day open bar
$daybar = null; // day high/low tracker
$or5 = null; // 5-min open range bar
foreach ($data as $i => $bar) {
    $bar['i'] = $i;
    $ftime = date("H:i", $bar['time']);
    $rangeLabel = labelRange($bar['close'], $prevDaybar);
    // track the day bars
    if (is_new_day($data, $i, $bar)) {
        $prevDaybar = $daybar;
        // new day @9:30 - this dataset has no premarket
        $doi = $i;
        $doBar = $bar;
        $daybar = $bar;
        $or5 = null;
        $break5hBar = false;
        $break5l = false;
        $retest5h = false;
        $bnr5l = false;
        $retestDone = false;
        echo date("Y-m-d D ", $bar['time']) . " $rangeLabel\n";
    } else {
        [$isHigh, $isLow] = trackbar($daybar, $bar);
        if ($isHigh) $hodBar = $bar;
    }

    // track 5-min open range
    if (5 + $doi === $i) $or5 = $daybar;
    // do nothing before 5-min complete
    if (!$or5) continue;



    // 1) wait for break
    if (!$break5hBar && $bar['low'] > $or5['high']) {  // compare low not close for total breakout
        $break5hBar = $bar;
        $lowDiff = round($bar['low'] - $or5['high'], 2);
        $closeDiff = round($bar['close'] - $or5['high'], 2);
        // echo "  $ftime: total breakout above 5-min high. Low:+$lowDiff, Close:+$closeDiff\n";
    }
    if (!$break5hBar) continue;

    // 2) wait for retest
    if (!$retest5h && $bar['low'] < $or5['high']) {
        $retest5h = $bar;
        $lowDiff = round($bar['low'] - $or5['high'], 2);
        $closeDiff = round($bar['close'] - $or5['high'], 2);
        // echo "  $ftime: retest below 5-min high. Low: $lowDiff, Close: $closeDiff\n";
        $retests[$rangeLabel]++;
    }
    if (!$retest5h) continue;

    // wait for next bar after retest
    // bars since retest
    if (($bsr = $i - $retest5h['i']) === 0) continue;

    // next bar after retest (only entering here misses a lot!)
    if ($bsr === 1) {
        // todo lower weight after big jump. biggest loss was due to that.
        // todo check trend direction
        // manual inputs for live trading
        // green bar and above previous day range
        if (is_green_bar($bar) && $bar['close'] > $prevDaybar['high']) {
            $entryBar = $bar;
            $entryPrice = $bar['close'];
            // $SL = $retest5h['low'] - 0.10; // 10 cents below retest low
            $SL = $retest5h['low'];
            $SLAmount = round($entryPrice - $SL, 2);
            $plHOD = round($hodBar['high'] - $entryPrice, 2);
            $TP = $entryPrice + max($plHOD, 2 * $SLAmount); // max(2R, HOD), whichever is lower
            // $TP = $entryPrice + min($plHOD, 2 * $SLAmount); // min(2R, HOD), whichever is lower
            // $TP = $entryPrice + min($plHOD, 2 * $SLAmount); // Worse than 2R
            // $TP = $entryPrice + (2 * $SLAmount);
            $targetGain = round($TP - $entryPrice, 2);
            $RR = round($targetGain / $SLAmount, 2);
            $position = [
                'entryBar' => $bar,
                'size' => 4,
                'realized' => 0,
            ];


            echo "  $ftime <b>Entry - Size: {$position['size']} price: {$entryPrice} SL: $SL TP: $TP Gain: $targetGain, SL Amount: $SLAmount, R/R: $RR</b>\n";
        } else {
            // done for the day for now
            // todo better logic
            $retestDone = true;
        }
        continue;
    }

    if ($retestDone) continue;

    // print new HOD after retest
    if ($daybar['high'] <= $bar['high']) {
        $diff = $bar['high'] - $retest5h['close'];
        // keep printing after done
        echo "  HOD $bsr mins +$diff {$bar['high']}\n";
    }


    $barsSinceEntry = $i - $entryBar['i'];

    // 3) take profit / Stop loss
    // take profit
    if ($TP <= $bar['high']) {
        $sellSize = ceil($position['size'] / 2);
        $position['size'] -= $sellSize;
        $winAmount = round(($TP - $entryPrice) * $sellSize, 3);
        $position['realized'] += $winAmount;
        $SL += $SLAmount; // move stop loss to breakeven
        $TP += $SLAmount; // move take profit up by 1R
        if ($position['size']) {
            echo "  <b class=\"win\">$barsSinceEntry mins Scaled out. remaining size: {$position['size']} Realized: $winAmount</b>\n";
        } else {
            echo "  <b class=\"win\">$barsSinceEntry mins Closed position.  Realized: $winAmount. Realized Total: {$position['realized']}</b>\n";
            $wins[$rangeLabel][] = $position['realized'];
            $retestDone = true;
        }
    }
    // stop loss at bar close
    if ($SL >= $bar['close']) {
        
        $realized = round($position['size'] * ($bar['close'] - $entryPrice), 2);
        $position['realized'] += $realized;
        if ($position['realized'] >= 0) {
            $wins[$rangeLabel][] = $position['realized'];
            echo "  <b class=\"win\">$barsSinceEntry mins Stop loss realized: $realized Total win: {$position['realized']}</b>\n";
        } else {
            $lossAmount = round($position['realized'], 2);
            $losses[$rangeLabel][] = $lossAmount;
            echo "  <b class=\"loss\">$ftime $barsSinceEntry mins Stop loss: {$position['realized']}</b>\n";
        }
        $retestDone = true;
    }
}
echo "<hr/>\n";
$winCounts = array_map(fn($a) => count($a), $wins);
$winTotals = array_map(fn($a) => round(array_sum($a), 2), $wins);
$lossCounts = array_map(fn($a) => count($a), $losses);
$lossTotals = array_map(fn($a) => round(array_sum($a), 2), $losses);
$totalWins = array_sum($winTotals);
$totalLosses = array_sum($lossTotals);
$overallPL = round($totalWins + $totalLosses, 2);
echo "Overall P&L: " . $overallPL . "\n";
echo "Win count:" . array_sum($winCounts) . ", total: $totalWins\n";
echo "Loss count:" . array_sum($lossCounts) . ", total: $totalLosses\n";
echo "Win counts:";
print_r($winCounts);
echo "Win totals:";
print_r($winTotals);
echo "Loss counts:";
print_r($lossCounts);
echo "Loss totals:";
print_r($lossTotals);

echo "Retests:";
print_r($retests);
echo "Wins:";
print_r($wins);
echo "Losses:";
print_r($losses);
echo "</pre>";
//------------------------------------------------------------------------------
function labelRange($value, $bar) {
    if (!$bar) return "";
    if ($value > $bar['high']) return "aboveRange";
    if ($value < $bar['low']) return "belowRange";
    return "inRange";
}
function isInRange($value, $bar) {
    if (!$bar) return null;
    return $value >= $bar['low'] && $value <= $bar['high'];
}
function is_green_bar($bar) {
    return $bar['close'] > $bar['open'];
}
function chart_convert_time(&$data) {
    foreach ($data as &$row) {
        $row['time'] = $row['time'] - 4 * 3600; // to new york time
    }
}
function chart_export(&$data) {
    chart_convert_time($data);
    $out = "export default ";
    $out .= json_encode($data, JSON_PRETTY_PRINT);
    return $out;
}

function is_new_day(&$data, $i, $bar) {
    if ($i === 0) return true;
    $lastBar = $i > 0 ? $data[$i - 1] : null;
    $currentDate = date('Y-m-d', $bar['time']);
    $lastDate = $lastBar ? date('Y-m-d', $lastBar['time']) : null;
    return $lastDate !== null && $currentDate !== $lastDate;
}
function trackbar(&$t, $bar) {
    $isHigh = $bar['high'] > ($t['high'] ?? PHP_FLOAT_MIN);
    if ($isHigh) {
        $t['high'] = $bar['high'];
        // $t['highTime'] = $bar['time'];
    }
    $isLow = $bar['low'] < ($t['low'] ?? PHP_FLOAT_MAX);
    if ($isLow) {
        $t['low'] = $bar['low'];
        // $t['lowTime'] = $bar['time'];
    }
    $t['close'] = $bar['close'];

    return [$isHigh, $isLow];
}
function format_data(&$data) { // in place - for lightweight charts
    foreach ($data as &$row) {
        $row['time'] = strtotime($row['time']);
        $row['open'] = (float) $row['open'];
        $row['high'] = (float) $row['high'];
        $row['low'] = (float) $row['low'];
        $row['close'] = (float) $row['close'];
        $row['volume'] = (float) $row['volume'];
    }
}
// headings
function get_csv($file, $header = NULL, $ignore_first_row = false) {
    ($handle = fopen($file, 'r')) or die("Can't open file");
    $data = [];
    $firstrow = true;
    while ($row = fgetcsv($handle, 1000, ',')) {
        if ($firstrow) {
            if (!$ignore_first_row && !$header) $header = $row;
            $firstrow = false;
            continue;
        }
        $data[] = array_combine($header, $row);
    }
    fclose($handle);
    return $data;
}
