<?php

namespace BoostTasks;

use BoostTasks\Db;
use PDO;

// VERY simple schema representation.
// No support for:
//   - foreign keys.
//   - partial indexes.
//   - DESC columns in indexes.
//   - collations other than BINARY in indexes.
//   - and much, much more.
class DbSchema {
    static $mysql_type_mapping = array(
        'int' => array('int', 11),
        'integer' => array('int', 11),
        'tinyint' => array('tinyint', 4),
    );

    var $tables = array();

    static function load($db) {
        if ($db instanceof \PDO) { $db = new Db($db); }
        switch($db->pdo_connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
        case 'sqlite':
            return self::loadFromSqlite($db);
        case 'mysql':
            return self::loadFromMysql($db);
        }
    }

    static function loadFromMysql($db) {
        if ($db instanceof \PDO) { $db = new Db($db); }
        $schema = new DbSchema();

        foreach($db->getAll("SHOW TABLES") as $row) {
            $table = new DbSchema_Table;
            $table_name = reset($row);
            $schema->tables[$table_name] = $table;

            foreach($db->getAll("SHOW COLUMNS FROM `{$table_name}`") as $column_info) {
                $column = new DbSchema_Column;
                $column->name = $column_info['Field'];
                $column->type = $column_info['Type'];
                switch(strtolower($column_info['Null'])) {
                case 'yes': $column->notnull = false; break;
                case 'no': $column->notnull = true; break;
                default: echo "Invalid Null field from MySQL."; exit(0);
                }
                $column->default = $column_info['Default'];
                $column->auto_increment = !!preg_match('@\bauto_increment\b@', strtolower($column_info['Extra']));
                // TODO: Extra = on update CURRENT_TIMESTAMP/others?
                $table->columns[] = $column;
            }

            $indexes_by_name = array();
            foreach($db->getAll("SHOW INDEX FROM `{$table_name}`") as $column_info) {
                $indexes_by_name[$column_info['Key_name']][$column_info['Seq_in_index']] = $column_info;
            }

            foreach($indexes_by_name as $index_name => $index_columns) {
                $index = new DbSchema_Index();
                $index->name = $index_name;
                //
                foreach ($index_columns as $column) {
                    $index->unique = !$column['Non_unique'];

                    $schema_index_column = new DbSchema_IndexColumn();
                    $schema_index_column->name = $column['Column_name'];
                    switch(strtolower($column['Collation'])) {
                    case 'a':
                        $schema_index_column->order = 'asc';
                        break;
                    default:
                        echo "Unrecognized collation: {$column['Collation']}.\n";
                    }
                    $schema_index_column->length = $column['Sub_part'];
                    $index->columns[] = $schema_index_column;
                    // Cardinality - don't care.
                    // Packed
                    // Null - can it contain null values
                    // Index_type
                    // Comment
                    // Index_comment
                }

                /*
                // Infer suitable 'origin' based on index details.
                if ($index_name === 'PRIMARY') {
                    $index->origin = 'pk';
                }
                else if ($index->unique) {
                    $index->origin = 'u';
                }
                else {
                    $index->origin = 'c';
                }
                */

                $table->indexes[] = $index;
            }

            $table->sortIndexes();
        }

        return $schema;
    }

    static function loadFromSqlite($db) {
        if ($db instanceof \PDO) { $db = new Db($db); }
        $schema = new DbSchema();

        foreach($db->getAll("SELECT tbl_name FROM sqlite_master WHERE type='table' AND tbl_name NOT LIKE 'sqlite_%'") as $row) {
            $table = new DbSchema_Table;
            $table_name = $row['tbl_name'];

            foreach($db->getAll("PRAGMA table_info(`{$table_name}`)") as $column_info) {
                $column = new DbSchema_Column;
                $column->name = $column_info['name'];
                $column->type = $column_info['type'];
                $column->notnull = intval($column_info['notnull']) ? true : false;
                $column->default = $column_info['dflt_value'];
                // TODO: auto_increment....
                $table->columns[] = $column;
            }

            foreach($db->getAll("PRAGMA index_list(`{$table_name}`)") as $index_info) {
                $index = new DbSchema_Index();
                // Ignoring 'seq'.
                $index->name = $index_info['name'];
                $index->unique = intval($index_info['unique']) ? true : false;
                //$index->origin = $index_info['origin'];
                //assert(!$index_info['partial']); // I don't support partial indexes.

                /*
                foreach($db->getAll("PRAGMA index_xinfo(`{$index->name}`)") as $column_info) {
                    // Ignore auxillary columns.
                    if ($column_info['key'] == '0') { continue; }
                     // I only support very basic indexes for now.
                    assert($column_info['desc'] === '0' || $column_info['desc'] === '1');
                    assert($column_info['coll'] === 'BINARY');
                    $schema_index_column = new DbSchema_IndexColumn();
                    $schema_index_column->name = $column_info['name'];
                    $schema_index_column->order = $column_info['desc'] === '1' ? 'dsc' : 'asc';
                    $index->columns[] = $schema_index_column;
                }
                */

                foreach($db->getAll("PRAGMA index_info(`{$index->name}`)") as $column_info) {
                     // I only support very basic indexes for now.
                    $schema_index_column = new DbSchema_IndexColumn();
                    $schema_index_column->name = $column_info['name'];
                    // TODO: Just assuming that indexes are ascending for now.
                    $schema_index_column->order = 'asc';
                    // TODO: Maybe order by rank?
                    $index->columns[] = $schema_index_column;
                }

                $table->indexes[] = $index;
            }

            $table->sortIndexes();
            $schema->tables[$table_name] = $table;
        }

        return $schema;
    }

    // Use a temporary sqlite database to load a schema from SQL.
    // This is probably a bit slow, the assumption is that this would be used
    // to generate a json or serialized php representation.
    static function loadFromSql($sql) {
        $comment_regex = "
            --[^\n\r]*[\r\n]? |
            /\*(?:(?!\*/).)*(?:\*/)?
        ";
        // TODO: Not sure about how square brackets work.
        $quote_regex = "
            '(?:[^']|'')*'? |
            \"(?:[^\"]|\"\")*\"? |
            \[[^\[\]]*\]? |
            `(?:[^`]|``)*`?
        ";

        // Match statements.
        preg_match_all("@
            # Don't match at the end.
            (?!\Z)
            # Skip over leading comments/whitespace/separators.
            (?: {$comment_regex} | [\s;] )*
            # Match contents of statement.
            (?<statement>
                (?: {$comment_regex} | {$quote_regex} | [^;] )*
            )
            # Skip over trailing comments/whitespace/separators.
            (?: {$comment_regex} | [\s;] )*
            @smx", $sql, $matches);

        $db = Db::createSqlite(':memory:');
        foreach($matches['statement'] as $sql_statement) {
            if ($sql_statement) { $db->exec($sql_statement); }
        }

        return self::loadFromSqlite($db);
    }

    static function as_json($schema) {
        // TODO: Requires at least PHP 5.4.0
        // return json_encode($schema, JSON_PRETTY_PRINT |  JSON_UNESCAPED_SLASHES);

        return json_encode($schema, JSON_UNESCAPED_SLASHES);
    }

    // Note: This doesn't do much in the way of error checking, and will
    //       probably go horribly wrong if given something that wasn't
    //       generated by 'as_json'.
    static function from_json($json) {
        $map = array(
            'DbSchema_Table::columns' => 'DbSchema_Column',
            'DbSchema_Table::indexes' => 'DbSchema_Index',
            'DbSchema_Index::columns' => 'DbSchema_IndexColumn',
        );

        $object = json_decode($json, true);
        $schema = new DbSchema();
        foreach($object['tables'] as $table_name => $table_data) {
            $table = self::from_json_impl($map, 'DbSchema_Table', $table_data);
            $table->sortIndexes();
            $schema->tables[$table_name] = $table;
        }

        return $schema;
    }

    static function from_json_impl($map, $type, $value) {
        $full_type = "BoostTasks\\{$type}";
        $result = new $full_type();
        foreach ($value as $field => $field_value) {
            $lookup = "{$type}::{$field}";
            if (array_key_exists($lookup, $map)) {
                foreach ($field_value as $array_item) {
                    $result->{$field}[] = self::from_json_impl($map, $map[$lookup], $array_item);
                }
            }
            else {
                $result->{$field} = $field_value;
            }
        }
        return $result;
    }
}

class DbSchema_Table {
    var $columns = array();
    var $indexes = array();

    function sortIndexes() {
        usort($this->indexes, function($x, $y) {
            return
                /* ($x->origin != 'pk' && $y->origin == 'pk') ?:
                -($x->origin == 'pk' && $y->origin != 'pk') ?: */
                ($x->name > $y->name) ?:
                -($x->name < $y->name) ?:
                0;
        });
    }
}

class DbSchema_Column {
    var $name;
    var $type;
    var $notnull = false;
    var $auto_increment = false;
    var $default = null;
}

class DbSchema_Index {
    var $name;
    var $unique = false;
    // origin doesn't seem to be supported by older versions of sqlite, so
    // just ignore it for now.
    // var $origin; // 'c' = create index, 'u' = unique, 'pk' = primary key
    var $columns = array();

    static function same($index1, $index2) {
        // Q: Do we really care about 'origin'?
        return $index1->name === $index2->name &&
            $index1->unique === $index2->unique &&
            /* $index1->orgin === $index2->origin && */
            self::sameColumns($index1->columns, $index2->columns);

    }

    static function sameColumns($columns1, $columns2) {
        if (count($columns1) !== count($columns2)) { return false; }

        for($column1 = reset($columns1), $column2 = reset($columns2);
            $column1 !== false;
            $column1 = next($columns1), $column2 = next($columns2))
        {
            if (!DbSchema_IndexColumn::same($column1, $column2)) { return false; }
        }

        return true;
    }

    static function compareColumns($columns1, $columns2) {
        $compare = count($columns1) - count($columns2);
        if ($compare) { return $compare; }

        for($column1 = reset($columns1), $column2 = reset($columns2);
            $column1 !== false;
            $column1 = next($columns1), $column2 = next($columns2))
        {
            if ($column1->name > $column2->name) { return 1; }
            if ($column1->name < $column2->name) { return -1; }
            // length/order???
        }

        return 0;
    }
}

class DbSchema_IndexColumn {
    var $name;
    var $length;
    var $order;

    static function same($column1, $column2) {
        // Q: Really need to check length/order?
        return $column1->name == $column2->name &&
            $column1->length == $column2->length &&
            $column1->order == $column2->order;
    }
}
