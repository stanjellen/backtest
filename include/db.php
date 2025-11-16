<?php
//require_once 'string_polyfill.php'; // str_ends_with()
require_once 'My2sqli.php';
use My2sqli as db;


$env = parse_ini_file('.env');
$db = db_connect($env);
//------------------------------------------------------------------------------

function db_connect($env) {
    // avoid checking for error on every db call
    mysqli_report(MYSQLI_REPORT_STRICT);
    try {
        return new db\My2sqli($env['DB_HOST'],$env['DB_USER'],$env['DB_PASSWORD'],$env['DB_NAME']);
    } catch (Exception $e) {
        echo $e->getMessage(), "\n";
        return false;
    }
}
// alias to pure data f'n defined in My2sqli
function rows_to_text($rows) {
    return db\rows_to_text($rows);
}
//------------------------------------------------------------------------------
// uses $db
function db_insert($table, $data=[], $no_escape=[]) {
    $columns = array_merge(array_keys($data), array_keys($no_escape));
    // php7.4 $values = array_merge(array_map(fn()=>'?', $data), array_values($no_escape));
    $values = array_merge(array_map(function(){return '?';}, $data), array_values($no_escape));
    $query = "INSERT INTO $table
        (". implode(',', $columns) .") VALUES
        (". implode(',', $values) .")";
    return psquery($query, ...array_values($data));
}
function where_condition($wheres = [], $and_not_or = true) {
    // [ col => val, ... ] --> "col=val" [" AND " | " OR " ...]
    global $db;
    $and_or = ($and_not_or ? ' AND ' : ' OR ');
    $where_strs = [];
    foreach ($wheres as $c => $v) {
        $where_strs[] = "$c=" . $db->real_escape_string($v);
    }
    return implode($and_or, $where_strs);
}
function explain($query) {
    return psquery("EXPLAIN $query")->to_text();
}
function show_query_times($n = 10) { // time (first line of query) of n longest queries
    // first (non-empty) line  identifiable ? : -- add commant
    global $db;
    if (!$db->TIME_QUERIES) return;

    $query_times = $db->get_query_times();
    sort_rows_by(0, $query_times);
    $subset = array_slice($query_times, -$n);
    $max_query_len = max(0, intval(`tput cols`) - 30); // console width - 30
    $formatted = array_map(function ($tq) use ($max_query_len) {
        list($t, $q) = $tq;
        $line = truncate(trim(strtok($q, "\n")), $max_query_len);
        return [$t, $line];
    }, $subset);

    $headings = ['time', 'query'];
    array_unshift($formatted, $headings);
    echo rows_to_text($formatted);
}

function db_ai_is_consecutive_or_die() { // unused. best not to rely on this for future proofing
    // *** innodb_autoinc_lock_mode = 2 is default in MySQL 8.0! ------
    // bch is good for now.

    // MySQL documetation describes when we can rely on consecutive ids
    // https://dev.mysql.com/doc/refman/8.0/en/innodb-auto-increment-handling.html
    // 
    // possible non-consecutive cases described here can all be avoided
    // https://stackoverflow.com/a/55009330
    //      1 specifying null for some rows results in incorrect last_insert_id()
    //          can be avoided. we never specify the pk if it's autoinc
    //      2 using "ON DUPLICATE KEY UPDATE or REPLACE"
    //          can be avoided
    //      3 innodb_autoinc_lock_mode = 2 will interleave concurrent inserts
    //          can be avoided
    // bill mentions that
    //      "an official MySQL Connector assumes id's generated in a multi-row INSERT are consecutive"
    $mode = psquery("SHOW VARIABLES LIKE 'innodb_autoinc_lock_mode'")->fetch_row()[1];
    if (in_array($mode, [0, 1])) {
        die("Error: Can't rely on consecutive auto increment ids when innodb_autoinc_lock_mode = 2\n");
    }
}
function db_get_row($table, $where=[], $unique=true) {
    list($wheres, $values) = wheres_values($where);
    $query = "SELECT * from $table WHERE " . implode(" AND ", $wheres);
    $result =  psquery($query, ...$values);
    if ($unique && $result->num_rows > 1) {
        echo "$query\n";
        echo json_encode($values, JSON_PRETTY_PRINT);
        throw new Exception('Not unique');
    }
    return $result->fetch_assoc();
}
function db_delete_row($table, $where=[]) {
    return db_delete($table, $where, 1);
}
function db_delete($table, $where=[], $limit=null) {
    // returns mysqli_stmt
    list($wheres, $values) = wheres_values($where);
    $query = "DELETE FROM $table WHERE " . implode(" AND ", $wheres);
    if ($limit = intval($limit)) $query .= " LIMIT $limit";
    return psquery($query, ...$values);
}
function wheres_values($where) {
    // using IS NULL
    $wheres = [];
    $values = [];
    foreach ($where as $k => $v) {
        if (is_null($v)) {
            $wheres[] = "$k IS NULL";
        } else {
            $wheres[] = "$k=?";
            $values[] = $v;
        }
    }
    return [$wheres, $values];
}
function query_scalar($query) { // return single value
    return psquery($query)->fetch_row()[0];
}
function psquery($query, ...$vars) {
    global $db;
    return $db->psquery($query, ...$vars);
}
function db_name(){
    global $db;
    $results = $db->query("SELECT DATABASE() AS db");
    $row = $results->fetch_assoc();
    return $row['db'];
}
//------------------------------------------------------------------------------

