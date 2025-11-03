#!/usr/bin/env php
<?php
// dd.mm.yyyy
$strings = [
    '02.01.2025 06:30:00.000 GMT-0800',
    '10.03.2025 06:30:00.000 GMT-0700',
    '13.06.2025 06:30:00.000 GMT-0700',
];

// convert to UTC timestamp minding the offset
date_default_timezone_set('GMT');
$times = [];
foreach ($strings as $s) {
    [$dmy, $time, $offset] = explode(' ', $s);
    [$d, $m, $y] = explode('.', $dmy);
    [$t, $msec] = explode('.', $time);
    $ofs = substr($offset, 3, 3) . ":00";
    $iso = "$y-$m-{$d}T$t$ofs";
    $times[] = strtotime($iso);
}

// exchange time
date_default_timezone_set('America/New_York');
foreach ($times as $t) {
    echo date("Y-m-d H:i:s\n", $t);
}
