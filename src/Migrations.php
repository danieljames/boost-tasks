<?php

class Migrations {
    static $versions = array(
        'GitHubEventQueue::migration_AddType',
    );

    static function migrate() {
        $version = R::findOne('version');
        if (!$version) {
            $version = R::dispense('version');
            $version->version = 0;
            R::store($version);
        }

        $num_versions = count(self::$versions);
        if ($version->version < $num_versions) {
            call_user_func(self::$versions[$version->version]);
            ++$version->version;
            R::store($version);
        }
    }

    static function newColumn($table, $column, $initial_value) {
        if (!array_key_exists($column, R::getColumns($table))) {
            $x = R::findOne($table);
            $x->{$column} = $initial_value;
            R::store($x);
        }
        R::exec("UPDATE {$table} SET {$column} = ? WHERE {$column} IS NULL",
            array($initial_value));
    }
}
