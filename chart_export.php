#!/usr/bin/env php
<?php
$file = "../data/TSLA.USUSD_Candlestick_1_M_BID_01.01.2025-14.06.2025_NOFLATS.csv";
$headings = ['time', 'open', 'high', 'low', 'close', 'volume'];
$data = get_csv($file, $headings, ignore_first_row: true);
format_data($data);

 echo chart_export($data);
// file_put_contents("../data/chart_export.js", chart_export($data));

//------------------------------------------------------------------------------
// function chart_convert_time(&$data) { // doesn't deal with DST
//     foreach ($data as &$row) {
//         $row['time'] = $row['time'] - 5*3600; // to new york time
//     }
// }
function chart_export(&$data) {
    // chart_convert_time($data);
    $out = "export default ";
    $out .= json_encode($data, JSON_PRETTY_PRINT);
    return $out;
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
