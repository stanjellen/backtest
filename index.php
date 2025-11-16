<style>
    .win {
        color: darkgreen;
    }

    .loss {
        color: darkred;
    }
</style>
<pre>
<?php
require_once 'include/db.php';
// $data = db_minutes('TSLA', extended: false, fromDate: '$data[] = $row;2025-01-01', toDate: '2025-06-13', debug: true);
// first date in CSV file
$data2 = db_minutes('TSLA', extended: true, fromDate: '2025-01-02', toDate: '2025-01-02', debug: true);
// first market open in DST - started at 0400
// $data2 = db_minutes('TSLA', extended: true, fromDate: '2025-03-10', toDate: '2025-03-10', debug: true);
// Monday May 12, 2025
// $data2 = db_minutes('TSLA', extended: true, fromDate: '2025-05-12', toDate: '2025-05-12', debug: true);

// old from csv file
$file = "../data/TSLA.USUSD_Candlestick_1_M_BID_01.01.2025-14.06.2025_NOFLATS.csv";
$headings = ['time', 'open', 'high', 'low', 'close', 'volume'];
$data = get_csv($file, $headings, ignore_first_row: true);
format_data($data);



$dbLen = count($data2);
$csvLen = count($data);
echo "DB rows: $dbLen, CSV rows: $csvLen<br />\n";


// compare datasets
$i = 0;
$dbRow = $data2[$i];
$csvRow = $data[17140];

echo "DB ROW:<br />\n";
debug($dbRow);

echo "CSV ROW:<br />\n";
debug($csvRow);
echo "<br />\n";


// expects minute bars - no premarket
// tracks day open, day high/low, 5-min open range

date_default_timezone_set('America/New_York');

debug("DB TIME: " . date("Y-m-d H:i", $dbRow['time']));
debug("CSV TIME: " . date("Y-m-d H:i", $csvRow['time']));
die;



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
$position = null;
//        "yyyy-mm-dd DDD "
$indent = "               ";
$trueRanges = []; // for ATR calculation. ignore market open gaps, reset each day
foreach ($data as $i => $bar) {
    $bar['i'] = $i;
    $ymd = date("Y-m-d", $bar['time']);
    $dow = date("D", $bar['time']);
    $ftime = date("H:i", $bar['time']);
    $rangeLabel = labelRange($bar['close'], $prevDaybar);
    // track the day bars
    if (is_new_day($data, $i, $bar)) {
        $prevDaybar = $daybar;
        // new day @9:30 - this dataset has no premarket
        $doi = $i;
        $doBar = $bar;
        $daybar = $bar;
        // if not still holding position from yesterday, reset all trackers
        if ($position) {
            echo "$ymd $dow Position held since yesterday\n";
        } else {
            $or5 = null;
            $break5hBar = false;
            $break5l = false;
            $retest5h = false;
            $bnr5l = false;
            $retestDone = false;
        }
        $trueRanges = [true_range($bar, null)];
        // echo date("Y-m-d D ", $bar['time']) . " $rangeLabel\n";
    } else {
        [$isHigh, $isLow] = trackbar($daybar, $bar);
        if ($isHigh) $hodBar = $bar;
        $trueRanges[] = true_range($bar, $data[$i - 1]);
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

    // 3) Entry logic ----------------------------------------------------------------------
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
            $SL = $retest5h['low']; // Bar close stop loss
            $R = round($entryPrice - $SL, 2);
            $plHOD = round($hodBar['high'] - $entryPrice, 2);
            echo ' ATR=' . round(ATR(), 2) . "\n";
            // take profit logic
            // $TP = $entryPrice + max($plHOD, 2 * $R); // max(2R, HOD),
            // $TP = $entryPrice + min($plHOD, 2 * $R); // min(2R, HOD), Worse than 2R
            // $TP = $entryPrice + (2 * $R);
            $TP = $entryPrice + min(max($plHOD, 2 * $R), 3 * ATR()); // max(2R, HOD)  capped at 3*ATR - best so far. P&L 17.38
            $targetGain = round($TP - $entryPrice, 2);
            $RR = round($targetGain / $R, 2);
            $position = [
                'entryBar' => $bar,
                'size' => 4,
                'realized' => 0,
            ];


            echo "$ymd $dow $ftime <b>Entry - Size: {$position['size']} price: {$entryPrice} SL: $SL TP: $TP Gain: $targetGain, SL Amount: $R, R/R: $RR</b>\n";
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
        echo "$indent HOD $bsr mins +$diff {$bar['high']}\n";
    }


    $barsSinceEntry = $i - $entryBar['i'];

    // 4) Exit logic ----------------------------------------------------------------------
    // take profit
    if ($TP <= $bar['high']) {
        $sellSize = ceil($position['size'] / 2);
        $position['size'] -= $sellSize;
        $winAmount = round(($TP - $entryPrice) * $sellSize, 3);
        $position['realized'] += $winAmount;
        $SL += $R; // move stop loss to breakeven
        $TP += $R; // move take profit up by 1R
        if ($position['size']) {
            echo "$indent<b class=\"win\">$barsSinceEntry mins Scaled out. remaining size: {$position['size']} Realized: $winAmount</b>\n";
        } else {
            echo "$indent<b class=\"win\">$barsSinceEntry mins Closed position.  Realized: $winAmount. Realized Total: {$position['realized']}</b>\n";
            $wins[$rangeLabel][] = $position['realized'];
            $position = null;
            $retestDone = true;
        }
    }
    // stop loss
    // $isInstantStop = $bar['low'] <= $IntraBarSL;
    $isInstantStop = false;
    $IntraBarSL = $SL - $R; // Immediate Stop at SL - 1R
    $isBarStop = $bar['close'] <= $SL;
    if ($isInstantStop || $isBarStop) {
        $exitPrice = $isInstantStop ? $IntraBarSL : $SL;
        $realized = round($position['size'] * ($exitPrice - $entryPrice), 2);
        $position['realized'] += $realized;
        if ($position['realized'] >= 0) {
            // todo check if actually a win. bar could close below breakeven SL
            $wins[$rangeLabel][] = $position['realized'];
            echo "$indent<b class=\"win\">$barsSinceEntry mins Stop loss realized: $realized Total win: {$position['realized']}</b>\n";
        } else {
            $lossAmount = round($position['realized'], 2);
            $losses[$rangeLabel][] = $lossAmount;
            echo "$indent<b class=\"loss\">$ftime $barsSinceEntry mins Stop loss: {$position['realized']}</b>\n";
        }
        $position = null;
        $retestDone = true;
    }
    if ($bar['time'] >= strtotime($ymd . " 15:55:00")) {
        // close any open position at market close
        if ($position) {
            $realized = round($position['size'] * ($bar['close'] - $entryPrice), 2);
            $position['realized'] += $realized;
            if ($position['realized'] >= 0) {
                $wins[$rangeLabel][] = $position['realized'];
                echo "$indent<b class=\"win\">$barsSinceEntry mins Market close realized: $realized Total win: {$position['realized']}</b>\n";
            } else {
                $lossAmount = round($position['realized'], 2);
                $losses[$rangeLabel][] = $lossAmount;
                echo "$indent<b class=\"loss\">$ftime $barsSinceEntry mins Market close loss: {$position['realized']}</b>\n";
            }
            $position = null;
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
function ATR($numBars = 14) {
    // average true range over last $numBars
    // use as few bars as available
    global $trueRanges;
    $ranges = array_slice($trueRanges, -$numBars);
    return array_sum($ranges) / count($ranges);
}
function true_range($bar, $lastBar) {
    if (!$lastBar) return $bar['high'] - $bar['low'];
    // https://en.wikipedia.org/wiki/Average_true_range
    return max($bar['high'], $lastBar['close']) - min($bar['low'], $lastBar['close']);
}
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
// function get_symbol_data($symbol) {
//     $table = 'minute';
//     return 
// }

function db_minutes($symbol, $extended = false, $fromDate = null, $toDate = null, $debug = false) {
    $where_sql = implode(
        "\n",
        array_filter([
            "symbol = ?",
            $extended ? "" : " AND TIME(`minute`) >= '09:30' AND TIME(`minute`) < '16:00'",
            $fromDate ? " AND DATE(`minute`) >= ?" : "",
            $toDate ? " AND DATE(`minute`) <= ?" : "",
        ])
    );
    $values = [
        $symbol,
        // regular hours clause needs no value
        ...$fromDate ? [$fromDate] : [],
        ...$toDate ? [$toDate] : [],
    ];
    $query = <<<SQL
        -- SELECT *, UNIX_TIMESTAMP(CONVERT_TZ(minute, 'UTC', 'America/New_York')) as time from minute
        SELECT *, UNIX_TIMESTAMP(minute) as time from minute
        WHERE $where_sql
        ORDER BY minute ASC
        SQL;

    if ($debug) debug($query,'Query VALUES', $values);

    $result = psquery($query, ...$values);
    return $result->fetch_all(MYSQLI_ASSOC);
}

function debug($var){
    if (func_num_args() > 1){
        $args = func_get_args();
        foreach ($args as $v){
            debug($v);
        }
        return;
    }
    echo '<pre>';
    if (!isset($var)){
        echo "NULL";
    }else if (is_array($var) || is_object($var)){
        print_r($var);
    }else{
        echo htmlentities($var,ENT_QUOTES,'utf-8');
    }
    echo '</pre>';
}