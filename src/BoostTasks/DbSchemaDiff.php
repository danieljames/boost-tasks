<?php

namespace BoostTasks;

// Find differences between schemas.
// Automatic updates are creating tables + adding columns + changing indexes,
// everything else has to be done by manual migrations.
class DbSchemaDiff {
    var $deleted_tables = array();
    var $new_tables = array();
    var $changed_tables = array();

    function has_changes() {
        return $this->deleted_tables || $this->new_tables || $this->changed_tables;
    }

    static function compare($schema1, $schema2) {
        $schema_diff = new DbSchemaDiff;

        $tables1 = array_keys($schema1->tables);
        $tables2 = array_keys($schema2->tables);

        $schema_diff->deleted_tables = array_values(array_diff($tables1, $tables2));
        $schema_diff->new_tables = array_values(array_diff($tables2, $tables1));

        foreach(array_intersect($tables1, $tables2) as $table_name) {
            $diff = new DbSchemaDiff_Table();

            $table1 = $schema1->tables[$table_name];
            $table2 = $schema2->tables[$table_name];

            // Column changes

            $columns_by_name1 = self::objects_by_name($table1->columns);
            $columns_by_name2 = self::objects_by_name($table2->columns);

            $columns1 = array_keys($columns_by_name1);
            $columns2 = array_keys($columns_by_name2);

            $diff->deleted_columns = array_values(array_diff($columns1, $columns2));
            $diff->new_columns = array_values(array_diff($columns2, $columns1));

            foreach(array_intersect($columns1, $columns2) as $column_name) {
                $column1 = $columns_by_name1[$column_name];
                $column2 = $columns_by_name2[$column_name];
                if (self::check_column_for_changes($column1, $column2)) {
                    $diff->changed_columns[] = $column_name;
                }
            }

            // Index changes

            $indexes_by_name1 = self::objects_by_name($table1->indexes);
            $indexes_by_name2 = self::objects_by_name($table2->indexes);

            $indexes1 = array_keys($indexes_by_name1);
            $indexes2 = array_keys($indexes_by_name2);

            $diff->deleted_indexes = array_diff($indexes1, $indexes2);
            $diff->new_indexes = array_diff($indexes2, $indexes1);

            foreach(array_intersect($indexes1, $indexes2) as $index_name) {
                $index1 = $indexes_by_name1[$index_name];
                $index2 = $indexes_by_name2[$index_name];
                if (!DbSchema_Index::same($index1, $index2)) {
                    $diff->changed_indexes[] = $index_name;
                }
            }

            // Record changes

            if ($diff->has_changes()) {
                $schema_diff->changed_tables[$table_name] = $diff;
            }
        }

        return $schema_diff;
    }

    static function objects_by_name($objects) {
        $objects_by_name = array();
        foreach($objects as $object) { $objects_by_name[$object->name] = $object; }
        return $objects_by_name;
    }

    static function check_column_for_changes($column1, $column2) {
        return $column1->name !== $column2->name ||
            $column1->type !== $column2->type ||
            $column1->notnull !== $column2->notnull ||
            $column1->default !== $column2->default;
    }
}

class DbSchemaDiff_Table {
    var $deleted_columns = array();
    var $new_columns = array();
    var $changed_columns = array();
    var $deleted_indexes = array();
    var $new_indexes = array();
    var $changed_indexes = array();

    function has_changes() {
        return ($this->deleted_columns || $this->new_columns || $this->changed_columns ||
                $this->deleted_indexes || $this->new_indexes || $this->changed_indexes);
    }
}
