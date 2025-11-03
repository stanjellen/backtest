#!/usr/bin/env php
<?php
// outputs timestamp in New York time zone
$file = "../data/TSLA.USUSD_Candlestick_1_M_BID_01.01.2025-14.06.2025_NOFLATS.csv";

$headings = ['time', 'open', 'high', 'low', 'close', 'volume'];
$data = get_csv($file, $headings, ignore_first_row: true);
date_default_timezone_set('UTC');
format_data($data);

echo chart_export($data);
// file_put_contents("../data/chart_export.js", chart_export($data));

//------------------------------------------------------------------------------
function dmy_time_gmtofs_to_timestamp_ny($s) {
    // input sample '13.06.2025 06:30:00.000 GMT-0700'
    // inputs given in Pacific time with DST (GMT-7 or GMT-8)
    // so just add a constant 3h and ignore the offset
    // no point doing any conversion because php is set to use UTC and timestamp won't change
    [$dmy, $time, $offset] = explode(' ', $s);
    [$d, $m, $y] = explode('.', $dmy);
    [$t, $msec] = explode('.', $time);
    return strtotime("$y-$m-{$d}T$t") + (3600 * 3);
}
function dmy_time_gmtofs_to_iso($s) {
    // input sample '13.06.2025 06:30:00.000 GMT-0700'
    [$dmy, $time, $offset] = explode(' ', $s);
    [$d, $m, $y] = explode('.', $dmy);
    $ofs = substr($offset, 3, 3) . ":00";
    [$t, $msec] = explode('.', $time);
    return "$y-$m-{$d}T$t$ofs";
}
function chart_export(&$data) {
    // chart_convert_time($data);
    $out = "export default ";
    $out .= json_encode($data, JSON_PRETTY_PRINT);
    return $out;
}
function format_data(&$data) { // in place - for lightweight charts
    foreach ($data as &$row) {
        // $row['time'] = strtotime(dmy_time_gmtofs_to_iso($row['time']));
        $row['time'] = dmy_time_gmtofs_to_timestamp_ny($row['time']);
        $row['open'] = (float) $row['open'];
        $row['high'] = (float) $row['high'];
        $row['low'] = (float) $row['low'];
        $row['close'] = (float) $row['close'];
        $row['volume'] = (float) $row['volume'];
    }
}
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
