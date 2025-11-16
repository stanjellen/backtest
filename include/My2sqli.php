<?php
namespace My2sqli; // MyMysqli -- (My^2)sqli
/**
 * mysqli wrapper that uses prepared statements and has timing and debug features
 * for php 7.3
 */
class My2sqli extends \mysqli {
    public $TIME_QUERIES = false; // psquery()
    public $query_times = [/* [time, query] */];
    public $DEBUG = false; // psquery() echo query


    /**
     * simple prepared statement query.
     * Records timing if $TIME_QUERIES is set.
     * echos if $DEBUG is set
     * @param string $query as given to prepare() https://www.php.net/manual/en/mysqli.prepare.php
     * @param mixed $vars... zero or more values
     * @return My2sqliResult
     */
    function psquery($query, ...$vars) {
        // prepared statement only for the purpose of escaping
        $TIME_QUERIES = $this->TIME_QUERIES;

        if ($this->DEBUG) {
            echo "$query\n";
            if ($vars) echo "vars: (" . implode(", ", $vars) . ")\n";
        }
        ($stmt = $this->prepare($query)) || die($this->error);
        // mysql works the same when giving int value as a string
        $types = str_repeat("s", $count = count($vars));
        if ($count) $stmt->bind_param($types, ...$vars) || die("bind error: " . $this->error);
        if ($TIME_QUERIES) $start = microtime(1);
        ($stmt->execute()) || die("execute error: " . $this->error);
        if ($TIME_QUERIES) $this->query_times[] = [microtime(1) - $start, $query];
        return new My2sqliResult($stmt);
    }
    function get_query_times() {
        return $this->query_times;
    }
    function query_scalar($query) { // return single value
        return $this->psquery($query)->fetch_row()[0];;
    }
}
//------------------------------------------------------------------------------
// Result
/**
 * DB Result class with statement properties and added methods
 * decorator class because can't extend mysqli_stmt to return extended result
 * https://stackoverflow.com/questions/13437846/extending-mysqli-stmt-to-use-a-custom-mysqli-result
 *  tried this https://stackoverflow.com/a/38657955
 */
class My2sqliResult implements \IteratorAggregate {
    // combines these two objects
    public $stmt; // mysqli_stmt
    public $result; // mysqli_result

    //stmt properties
    public $insert_id;
    public $affected_rows;
    // result properties
    public $num_rows = 0;
    /*
    properties should be readonly but readonly properties started in php 8.1
    possible to implement readonly using __get() and __set() magic methods
    https://stackoverflow.com/a/402227
    not using because it's supposedly very slow
    -----------------------------
    child object properties not implemented. use stmt/result or implement
    result properties
        public readonly int $current_field;
        public readonly int $field_count;
        public readonly ?array $lengths;
        public int $type;
    stmt properties
        public readonly int|string $affected_rows;
        public readonly int|string $insert_id;
        public readonly int|string $num_rows;
        public readonly int $param_count;
        public readonly int $field_count;
        public readonly int $errno;
        public readonly string $error;
        public readonly array $error_list;
        public readonly string $sqlstate;
        public int $id;
    -----------------------------
    */

    function __construct($mysqli_stmt) {
        $this->stmt = $mysqli_stmt;
        $this->insert_id = $this->stmt->insert_id;
        $this->affected_rows = $this->stmt->affected_rows;
        $this->result = $this->stmt->get_result();
        if (!$this->result) return;

        $this->num_rows = $this->result->num_rows;
    }

   
    // result methods ------------------
    public function fetch_all(int $mode = MYSQLI_NUM) { // :array
        return $this->result->fetch_all($mode);
    }
    public function fetch_array(int $mode = MYSQLI_BOTH) { // : array|null|false
        return $this->result->fetch_array($mode);
    }
    public function fetch_assoc() { // : array|null|false
        return $this->result->fetch_assoc();
    }
    public function fetch_column(int $column = 0) { // : null|int|float|string|false
        return $this->result->fetch_column($column);
    }
    public function fetch_field_direct(int $index) { // : object|false
        return $this->result->fetch_field_direct($index);
    }
    public function fetch_field() { // : object|false
        return $this->result->fetch_field();
    }
    public function fetch_fields() { // : array
        return $this->result->fetch_fields();
    }
    public function fetch_object(string $class = "stdClass", array $constructor_args = []) { // : object|null|false
        return $this->result->fetch_object($class,$constructor_args);
    }
    public function fetch_row() { // : array|null|false
        return $this->result->fetch_row();
    }
    public function field_seek(int $index) { // : bool
        return $this->result->field_seek($index);
    }
    public function free() { // : void
        return $this->result->free();
    }
    public function close() { // : void
        return $this->result->close();
    }
    public function free_result() { // : void
        return $this->result->free_result();
    }
    #[\ReturnTypeWillChange]
    public function getIterator() { 
        var_dump($this->result);
        $stuff = $this->result->getIterator(); // Error: Call to undefined method mysqli_result::getIterator()
        return @$stuff;
    }

    // new methods
    function to_array($column = 0) {
        if (!$this->result) return [];
        $ret = [];
        foreach ($this->result->fetch_all() as $row) {
            $ret[] = $row[$column];
        }
        return $ret;
    }
    function to_assoc() { // from first two columns
        if (!$this->result) return [];
        $ret = [];
        foreach ($this->result->fetch_all() as $row) {
            $ret[$row[0]] = $row[1];
        }
        return $ret;
    }
    function to_text() {
        if ($this->num_rows == 0) return "";

        $headings = array_map(function ($r) {
            return $r->name;
        }, $this->result->fetch_fields());

        $rows = $this->result->fetch_all();

        return rows_to_text(array_merge([$headings], $rows));
    }
}
//------------------------------------------------------------------------------
// used by to_text()
function rows_to_text($rows) {
    // first row is headings row.  this doesn't use keys as headings.
    if (!$rows) return "";

    foreach (array_keys($rows[0]) as $k) {
        $column = rows_get_column($rows, $k);
        $colmax[$k] = max(array_map('strlen', $column)) + 2; // space on either side
        array_shift($column); // remove heading
        $col_pad_type[$k] = (any_non_numeric($column)
            ? STR_PAD_RIGHT
            : STR_PAD_LEFT
        );
    }

    $headings = array_shift($rows);

    foreach ($headings as $k => $v) {
        $len = $colmax[$k];
        $padHeadings[] = str_pad(" $v ", $len, ' ', STR_PAD_BOTH);
        $lines[] = str_repeat('-', $len);
    }
    $headings = implode_surround('|', $padHeadings);
    $divider = implode_surround('+', $lines);

    $table = "$divider\n";
    $table .= "$headings\n";
    $table .= "$divider\n";
    foreach ($rows as $row) {
        $paddedRow = [];
        foreach ($row as $i => $v) {
            $len = $colmax[$i];
            $paddedRow[] = str_pad(" $v ", $len, ' ', $col_pad_type[$i]);
        }
        $table .= implode_surround('|', $paddedRow) . "\n";
    }
    $table .= "$divider\n";
    return $table;
}
function any_non_numeric($array) {
    foreach ($array as $v) {
        if (!is_numeric($v)) return true;
    }
    return false;
}
function implode_surround($glue, $array) {
    return $glue . implode($glue, $array) . $glue;
}
