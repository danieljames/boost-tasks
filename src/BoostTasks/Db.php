<?php

namespace BoostTasks;

use PDO;
use RuntimeException;
use Exception;
use Iterator;
use Nette\Object;

// This is an incredibly crude little library for database stuff that I
// threw together when I had issues with RedBean in another project.
// Slightly adapted to be used here. It's pretty rubbish, okay?

// Convenience front end, for when you're only using one database.
class Db extends Object {
    static $entity_object = 'BoostTasks\\Db_Entity';
    var $pdo_connection;
    private $saved_pdo_connection;
    var $schema = Array();
    var $is_explicit_transaction = false;
    var $transaction_level = 0;

    static function create($dsn, $username = null, $password = null) {
        return new Db(new PDO($dsn, $username, $password));
    }

    static function createFromPdo($pdo) {
        return new Db($pdo);
    }

    static function createSqlite($path) {
        return new Db(new PDO("sqlite:{$path}"));
    }

    public function __construct($pdo) {
        $this->pdo_connection = $pdo;
        $this->pdo_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo_connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function getDriverName() {
        return $this->pdo_connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function error($message) {
        $connection = $this->pdo_connection ?: $this->saved_pdo_connection;
        switch($connection->getAttribute(PDO::ATTR_ERRMODE)) {
        case PDO::ERRMODE_SILENT:
            return false;
        case PDO::ERRMODE_WARNING:
            trigger_error($message, E_USER_WARNING);
            return false;
        default:
            throw new RuntimeException($message);
        }
    }

    //
    // Transactions
    //

    // Run $callback and wrap in a (possibly nested) transaction.
    // If there's an exception rollback the transaction, even if nested.
    //
    // This throws exceptions on error regardless of PDO's error mode
    public function transaction($callback) {
        if ($this->nestedBegin() === false) {
            throw new RuntimeException("Error starting transaction");
        }

        try { $result = call_user_func($callback); }
        catch(Exception $e) {
            $this->nestedRollback();
            throw $e;
        }

        if (!$this->nestedEnd()) {
            throw new RuntimeException("Error ending transaction");
        }
        return $result;
    }

    public function begin() {
        if ($this->transaction_level) {
            return $this->error("begin called inside of transaction");
        }
        if (!$this->pdo_connection->beginTransaction()) { return false; }
        ++$this->transaction_level;
        $this->is_explicit_transaction = true;
        return true;
    }

    public function nestedBegin() {
        if (!$this->transaction_level) {
            if (!$this->pdo_connection->beginTransaction()) { return false; }
        }
        ++$this->transaction_level;
        return true;
    }

    public function rollback() {
        if (!$this->transaction_level) {
            return $this->error("rollback called outside of transaction");
        }
        if ($this->is_explicit_transaction) {
            --$this->transaction_level;
            $this->is_explicit_transaction = false;
        }
        return $this->rollbackTransactionImpl();
    }

    public function nestedRollback() {
        if ($this->transaction_level <= ($this->is_explicit_transaction ? 1 : 0)) {
            return $this->error("nestedRollback called outside of nested transaction");
        }
        --$this->transaction_level;
        return $this->rollbackTransactionImpl();
    }

    private function rollbackTransactionImpl() {
        if ($this->pdo_connection) {
            if (!$this->endTransactionImpl()->rollback()) { return false; }
        } else {
            if (!$this->transaction_level) {
                $this->pdo_connection = $this->saved_pdo_connection;
                $this->saved_pdo_connection = null;
            }
        }
        return true;
    }

    public function commit() {
        if (!$this->transaction_level || !$this->pdo_connection) {
            return $this->error("commit called outside of active transaction");
        }
        if ($this->is_explicit_transaction) {
            --$this->transaction_level;
            $this->is_explicit_transaction = false;
        }
        return !!$this->endTransactionImpl()->commit();
    }

    public function nestedEnd() {
        if ($this->transaction_level <= ($this->is_explicit_transaction ? 1 : 0)) {
            return $this->error("nestedRollback called outside of nested transaction");
        }
        --$this->transaction_level;

        if ($this->pdo_connection) {
            if (!$this->transaction_level) {
                if (!$this->pdo_connection->commit()) { return false; }
            }
        } else {
            if (!$this->transaction_level) {
                $this->pdo_connection = $this->saved_pdo_connection;
                $this->saved_pdo_connection = null;
            }
        }

        return true;
    }

    // Prevent the user from doing anything more with the database after a transaction has ended,
    // but the nested transactions haven't.
    private function endTransactionImpl() {
        $connection = $this->pdo_connection;
        if ($this->transaction_level) {
            $this->saved_pdo_connection = $this->pdo_connection;
            $this->pdo_connection = null;
        }
        return $connection;
    }

    //
    // SQL commands
    //

    public function exec($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        return $statement && $statement->execute($query_args);
    }

    public function getAll($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        if ($statement && $statement->execute($query_args)) {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        else {
            return false;
        }
    }

    // This throws exceptions on error regardless of PDO's error mode
    public function getIterator($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        if ($statement && $statement->execute($query_args)) {
            return new Db_SelectIterator($statement);
        }
        else {
            throw new RuntimeException("Error creating get iterator");
        }
    }

    public function getCol($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        if ($statement && $statement->execute($query_args)) {
            $col = array();
            while ($row = $statement->fetch(PDO::FETCH_NUM)) {
                $col[] = $row[0];
            }
            return $col;
        }
        else {
            return false;
        }
    }

    public function getCell($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        if ($statement && $statement->execute($query_args)) {
            $row = $statement->fetch(PDO::FETCH_NUM);
            return $row !== false ? $row[0] : null;
        }
        else {
            return false;
        }
    }

    public function getRow($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        if ($statement && $statement->execute($query_args)) {
            return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        else {
            return false;
        }
    }

    //
    // 'Bean' getters/finders
    //

    public function dispense($table_name) {
        $table = $this->getTable($table_name);
        if (!$table) { return false; }

        $object = new self::$entity_object();
        foreach ($table->columns as $name => $default_value) {
            $object->{$name} = $default_value;
        }
        $object->__meta = new Db_EntityMetaData($this, $table, true, null);
        return $object;
    }

    public function load($table_name, $id) {
        $table = $this->getTable($table_name);
        if (!$table) { return false; }

        $args = func_get_args();
        array_shift($args);
        assert(count($args) === count($table->primary_key));

        $sql = '';
        foreach($table->primary_key as $index => $column_name) {
            if ($index) { $sql .= " AND "; }
            $sql .= "`{$column_name}` = ?";
        }

        return $this->findOne($table_name, $sql, $args);
    }

    public function find($table_name, $query = '', array $query_args = array()) {
        $table = $this->getTable($table_name);
        if (!$table) { return false; }

        $statement = $this->createFindStatement($table, $query, $query_args);
        if (!$statement) { return false; }

        $result = array();
        while($object = $this->_fetchBean($table, $statement)) {
            $result[] = $object;
        }

        return $result;
    }

    /* Redbean has a 'findAll' method which is identical to 'find'. I guess it's for
     * backwards compatibility. */
    public function findAll($table_name, $query = '', array $query_args = array()) {
        return $this->find($table_name, $query, $query_args);
    }

    public function findOne($table_name, $query = '', array $query_args = array()) {
        $table = $this->getTable($table_name);
        if (!$table) { return false; }

        $statement = $this->createFindStatement($table, $query, $query_args);
        if (!$statement) { return false; }

        return $this->_fetchBean($table, $statement);
    }

    // This throws exceptions on error regardless of PDO's error mode
    public function findIterator($table_name, $query = '', array $query_args = array()) {
        $table = $this->getTable($table_name);
        if (!$table) {
            throw new RuntimeException("Error finding table for iterator");
        }

        $statement = $this->createFindStatement($table, $query, $query_args);
        if (!$statement) {
            throw new RuntimeException("Error creating find iterator");
        }
        return new Db_Iterator($this, $table, $statement);
    }

    private function createFindStatement($table, $query, array $query_args) {
        $sql = "SELECT ";
        $sql .= implode(', ', array_map(
            function($x) use($table) { return "`{$table->name}`.`{$x}`"; },
            array_keys($table->columns)));
        $sql .= " FROM `{$table->name}` ";
        if ($query) {
            if (preg_match('/^(where|join|order|limit)\b/i', $query)) {
                $sql .= $query;
            } else {
                $sql .= "WHERE {$query}";
            }
        }
        $statement = $this->pdo_connection->prepare($sql);
        $success = $statement && $statement->execute($query_args);
        return $success ? $statement : false;
    }

    // Public so that the iterator can use it...
    public function _fetchBean($table, $statement) {
        $object = $statement->fetchObject(self::$entity_object);
        if (!$object) { return null; }

        $primary_key = array();
        foreach ($table->primary_key as $column_name) {
            $primary_key[$column_name] = $object->{$column_name};
        }

        $object->__meta = new Db_EntityMetaData($this, $table, false, $primary_key);
        return $object;
    }

    //
    // Convert to bean.
    //

    public function convertToBeans($table_name, $objects) {
        $table = $this->getTable($table_name);
        if (!$table) { return false; }

        $result = array();
        foreach($objects as $array) {
            if (!is_array($array)) {
                throw new RuntimeException("Not an array of arrays in convertToBeans.");
            }
            $object = new self::$entity_object();
            foreach($array as $key => $value) {
                $object->$key = $value;
            }
            $primary_key = array();
            foreach ($table->primary_key as $column_name) {
                $primary_key[$column_name] = $object->{$column_name};
            }
            $object->__meta = new Db_EntityMetaData($this, $table, false, $primary_key);
            $result[] = $object;
        }
        return $result;
    }

    //
    // Bean modifiers
    //

    public function store($object) {
        $table_name = $object->__meta->table->name;
        $is_new = $object->__meta->is_new;

        $update = array();
        $default_columns = array();

        foreach(get_object_vars($object) as $key => $value) {
            switch(strtolower($key)) {
            case '__meta':
                break;
            default:
                if ($value instanceof Db_Default) {
                    $default_columns[] = "`{$key}`";
                } else if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
                    $value = clone $value;
                    $value->setTimezone(new \DateTimeZone('UTC'));
                    $update[$key] = $value->format('Y-m-d H:i:s');
                } else {
                    $update[$key] = $value;
                }
                break;
            }
        }

        if ($is_new) {
            $sql = "INSERT INTO `{$table_name}` ";
            if (!$update) {
                if ($this->getDriverName() == 'sqlite') {
                    $sql .= "DEFAULT VALUES";
                    $query_args = array();
                }
                else {
                    $sql .= "VALUES()";
                    $query_args = array();
                }
            }
            else {
                $sql .= '(`'.implode('`,`', array_keys($update)).'`) ';
                $sql .= 'VALUES('.str_repeat('?,', count($update) - 1).'?)';
                $query_args = array_values($update);
            }

            $statement = $this->pdo_connection->prepare($sql);
            $success = $statement && $statement->execute($query_args);
            if (!$success) { return false; }

            if ($default_columns) {
                $sql = 'SELECT '.implode(',', $default_columns)." FROM `{$table_name}` WHERE ";
                $query_args = array();
                if ($object->__meta->table->row_id) {
                    $sql .= "`{$object->__meta->table->row_id}` = ?";
                    $query_args[] = $this->pdo_connection->lastInsertId();
                } else {
                    $first_row = true;
                    foreach ($object->__meta->table->primary_key as $x) {
                        if (!$first_row) { $sql .= " AND "; }
                        $first_row = false;
                        $sql .= "`{$x}` = ?";
                        $query_args[] = $object->$x;
                    }
                }
                $new_values = $this->getRow($sql, $query_args);
                if (!$new_values) { return false; }
                foreach($new_values as $key => $value) { $object->$key = $value; }
            }

            $object->__meta->is_new = false;
        } else {
            if ($default_columns) { return $this->error("Default column in update object"); }

            $sql = "UPDATE `{$table_name}` SET ";
            $sql .= implode(',', array_map(function($name) { return "`{$name}` = ?"; }, array_keys($update)));
            $query_args = array_values($update);
            $sql .= " WHERE ";
            $first_row = true;
            foreach ($object->__meta->primary_key_value as $column_name => $column_value) {
                if (!$first_row) { $sql .= " AND "; }
                $first_row = false;
                $sql .= "`{$column_name}` = ?";
                $query_args[] = $column_value;
            }
            $statement = $this->pdo_connection->prepare($sql);
            if (!($statement && $statement->execute($query_args))) { return false; }
        }

        $primary_key = array();
        foreach ($object->__meta->table->primary_key as $column_name) {
            $primary_key[$column_name] = $object->{$column_name};
        }
        $object->__meta->primary_key_value = $primary_key;

        return true;
    }

    public function trash($object) {
        assert(!$object->__meta->is_new);
        $sql = "DELETE FROM `{$object->__meta->table->name}` WHERE ";
        $first_row = true;
        foreach ($object->__meta->primary_key_value as $column_name => $column_value) {
            if (!$first_row) { $sql .= " AND "; }
            $first_row = false;
            $sql .= "`{$column_name}` = ?";
            $query_args[] = $column_value;
        }
        $statement = $this->pdo_connection->prepare($sql);
        return $statement && $statement->execute($query_args);
    }

    private function getTable($table_name) {
        if (array_key_exists($table_name, $this->schema)) { return $this->schema[$table_name]; }
        switch($this->getDriverName()) {
        case 'sqlite':
            return $this->schema[$table_name] = $this->getTableFromSqlite($table_name);
        case 'mysql':
            return $this->schema[$table_name] = $this->getTableFromMysql($table_name);
        default:
            echo "Unrecognized database type";
            exit(1);
        }
    }

    private function getTableFromSqlite($table_name) {
        $sql = "PRAGMA table_info(`{$table_name}`)";
        $statement = $this->pdo_connection->prepare($sql);
        $success = $statement && $statement->execute(array());
        if (!$success) { return $this->error("Error finding table: {$table_name}"); }

        $columns = array();
        $primary_key = array();
        $is_pk_int = false;

        while($column = $statement->fetchObject()) {
            $name = $column->name;
            if ($column->pk) {
                $primary_key[$column->pk] = $column->name;
                if (strtolower($column->type) == 'integer') {
                    $is_pk_int = true;
                }
            }
            $default = trim(strtolower($column->dflt_value));
            if ($default === '') { $default = 'null'; }
            switch($default[0]) {
            case 'n':
                $default_value = null;
                break;
            case '"':
                if (preg_match('@^"(.*)"$@', $column->dflt_value, $matches)) {
                    $default_value = str_replace('""', '"', $matches[1]);
                }
                else {
                    return $this->error("Invalid string default");
                }
                break;
            case "'":
                if (preg_match('@^\'(.*)\'$@', $column->dflt_value, $matches)) {
                    $default_value = str_replace("''", "'", $matches[1]);
                }
                else {
                    return $this->error("Invalid string default");
                }
                break;
            case '`':
                if (preg_match('@^`(.*)`$@', $column->dflt_value, $matches)) {
                    $default_value = str_replace('``', '`', $matches[1]);
                }
                else {
                    return $this->error("Invalid string default");
                }
                break;
            case '+': case '-':
            case '0': case '1': case '2': case '3': case '4':
            case '5': case '6': case '7': case '8': case '9':
                $default_value = $default;
                break;
            case 'c': // current_date/current_time/current_timestamp
            case '(': // expression
                $default_value = Db_Default::$instance;
                break;
            default:
                Log::warning("Unrecognized default: {$default}");
                break;
            }

            $columns[$column->name] = $default_value;
        }
        if (!$columns) { return $this->error("Error finding table: {$table_name}"); }

        ksort($primary_key);
        $primary_key = array_values($primary_key);

        $sql = "SELECT sqlite_version() AS version";
        $statement = $this->pdo_connection->query($sql);
        if (!$statement) { return $this->error("Error getting sqlite3 version"); }
        if (version_compare($statement->fetchObject()->version, '3.14', '<')) {
            if (count($primary_key) == 1 && $is_pk_int) {
                $row_id = $primary_key[0];
            } else {
                $row_id = true;
            }

        } else {
            // Get the name of the primary key, in order to check if it includes a rowid.
            $primary_key_name = null;
            if ($primary_key) {
                $sql = "PRAGMA index_list(`{$table_name}`)";
                $statement = $this->pdo_connection->prepare($sql);
                $success = $statement && $statement->execute(array());
                if (!$success) { return $this->error("Error getting indexes for: {$table_name}"); }
                while($column = $statement->fetchObject()) {
                    if ($column->origin == 'pk') {
                        $primary_key_name = $column->name;
                    }
                }
            }

            // Check if the primary key includes a rowid
            $row_id = null;
            if ($primary_key_name) {
                $sql = "PRAGMA index_xinfo(`{$primary_key_name}`)";
                $statement = $this->pdo_connection->prepare($sql);
                $success = $statement && $statement->execute(array());
                if (!$success) { return $this->error("Error getting primary key for: {$table_name}"); }
                while($column = $statement->fetchObject()) {
                    if ($column->cid == -1) { $row_id = true; }
                }
            } else if ($primary_key) {
                assert(count($primary_key) == 1);
                $row_id = $primary_key[0];
            } else {
                $row_id = true;
            }
        }

        // There's a rowid, but no name for it, so check if the standard names are available
        if ($row_id === true) {
            if (!array_key_exists('rowid', $columns)) { $row_id = 'rowid'; }
            else if (!array_key_exists('oid', $columns)) { $row_id = 'oid'; }
            else if (!array_key_exists('_rowid_', $columns)) { $row_id = '_rowid_'; }
            else if ($primary_key) { $row_id = null; }
            else return $this->error("Can't get rowid column for {$table_name}");
        }

        if ($row_id) { $columns[$row_id] = Db_Default::$instance; }

        return new Db_TableSchema($table_name, $columns, $primary_key ?: array($row_id), $row_id);
    }

    private function getTableFromMysql($table_name) {
        $sql = "DESCRIBE `{$table_name}`";
        $statement = $this->pdo_connection->prepare($sql);
        $success = $statement && $statement->execute(array());
        if (!$success) { return $this->error("Error finding table: {$table_name}"); }

        $columns = array();
        $primary_key = array();
        $first_auto_increment = null;
        while($column = $statement->fetchObject()) {
            $name = $column->Field;
            if (preg_match('@\bauto_increment\b@', strtolower($column->Extra))) {
                $default_value = Db_Default::$instance;
                if (!$first_auto_increment) { $first_auto_increment = $name; }
            }
            else if (strtolower($column->Default) == 'current_timestamp' &&
                (strtolower($column->Type) === 'timestamp' || strtolower($column->Type) === 'datetime'))
            {
                $default_value = Db_Default::$instance;
            }
            else {
                $default_value = $column->Default;
            }
            if ($column->Key == 'PRI') {
                $primary_key[] = $name;
            }
            $columns[$name] = $default_value;
        }

        if (!$primary_key) {
            return $this->error("Mysql currently requires a primary key");
        }

        // This is weird. Is it right?
        $row_id = count($primary_key) == 1 && $primary_key[0] == $first_auto_increment ?
            $first_auto_increment : null;

        return new Db_TableSchema($table_name, $columns, $primary_key, $row_id);
    }
}

class Db_Iterator implements Iterator {
    var $db;
    var $table;
    var $statement;
    var $index = 0;
    var $current;

    function __construct($db, $table, $statement) {
        $this->db = $db;
        $this->table = $table;
        $this->statement = $statement;
        $this->fetchObject();
    }

    function current() {
        if ($this->current === null) {
            throw new RuntimeException("current() called past end of database iterator.");
        }
        return $this->current;
    }

    function key() {
        return $this->index;
    }

    function next() {
        $this->fetchObject();
        ++$this->index;
    }

    function rewind() {
        if ($this->index) {
            throw new RuntimeException("Db_Iterator doesn't support rewind.");
        }
    }

    function valid() {
        return $this->current !== null;
    }

    private function fetchObject() {
        $this->current = $this->db->_fetchBean($this->table, $this->statement);
        if (!$this->current) {
            $this->db = null;
            $this->statement = null;
        }
    }
}

class Db_SelectIterator implements Iterator {
    var $statement;
    var $index = 0;
    var $current;

    function __construct($statement) {
        $this->statement = $statement;
        $this->fetchRow();
    }

    function current() {
        if ($this->current === null) {
            throw new RuntimeException("current() called past end of database iterator.");
        }
        return $this->current;
    }

    function key() {
        return $this->index;
    }

    function next() {
        $this->fetchRow();
        ++$this->index;
    }

    function rewind() {
        if ($this->index) {
            throw new RuntimeException("Db_Iterator doesn't support rewind.");
        }
    }

    function valid() {
        return $this->current !== null;
    }

    private function fetchRow() {
        $this->current = $this->statement->fetch(PDO::FETCH_ASSOC);
        if (!$this->current) {
            $this->current = null;
            $this->db = null;
            $this->statement = null;
        }
    }
}

// A database entity.
// Fields are dynamically added.
class Db_Entity {
    var $__meta;

    function store() {
        return $this->__meta->connection->store($this);
    }

    function trash() {
        return $this->__meta->connection->trash($this);
    }

    function __set($name, $value) {
        if ($this->__meta) {
            throw new RuntimeException("Unknown column: {$name}");
        } else {
            $this->$name = $value;
        }
    }

    function __get($name) {
        throw new RuntimeException("Unknown column: {$name}");
    }
}

// A little bit of extra data about the entity.
class Db_EntityMetaData extends Object {
    var $connection;
    var $table;
    var $is_new;
    var $primary_key_value;

    function __construct($connection, $table, $is_new, $primary_key_value) {
        $this->connection = $connection;
        $this->table = $table;
        $this->is_new = $is_new;
        $this->primary_key_value = $primary_key_value;
    }
}

class Db_TableSchema extends Object {
    var $name;
    var $columns;
    var $primary_key;
    var $row_id;

    function __construct($name, $columns, $primary_key, $row_id) {
        $this->name = $name;
        $this->columns = $columns;
        $this->primary_key = $primary_key;
        $this->row_id = $row_id;
    }
}

// Used for automatically filled in fields.
// TODO: Could do a lot better by tracking when a field is set, or perhaps
//       even by not setting such fields in a new entity.
class Db_Default extends Object {
    static $instance;
}
Db_Default::$instance = new Db_Default();
