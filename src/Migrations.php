<?php

use Nette\Object;
use Nette\Neon\Neon;
use BoostTasks\Db;
//use RuntimeException;

class Migrations extends Object {
    static $versions = array(
        'Migrations::migration_Initialise',
        'Migrations::migration_Null',
        'Migrations::migration_PullRequestEvent',
        'Migrations::migration_PullRequest',
        'Migrations::migration_PullRequestEventState',
        'Migrations::migration_HistoryDates',
        'Migrations::migration_MirrorPriority',
        'Migrations::migration_Unique',
    );

    static function migrate($db) {
        while($db->transaction(function() use($db) { return Migrations::single_migration($db); })) {}
    }

    // Runs a single migration stage, returns true if there is more to do.
    static function single_migration($db) {
        $num_versions = count(self::$versions);
        $version = $db->getCell('PRAGMA user_version');

        // Old migration system
        if ($version > 0 && $version < 100) {
            if ($version < $num_versions) {
                Log::info("Call migration {$version}: ".self::$versions[$version]);
                call_user_func(self::$versions[$version], $db);
                ++$version;
                Log::info("Migration success, now at version {$version}");
                $db->exec("PRAGMA user_version = {$version}");
                return true;
            } else {
                $version = 2017060401;
                Log::info("New migration system, start at {$version}");
                $db->exec("PRAGMA user_version = {$version}");
                return true;
            }
        }

        // Create database from latest schema
        if ($version == 0) {
            $schema = self::loadLatestSchema();
            Log::info("Create database using schema {$schema->version}.");
            foreach($schema->tables as $table_name => $columns) {
                self::createTable($db, $table_name, $columns);
            }
            Log::info("Create database success, schema {$schema->version}");
            $db->exec("PRAGMA user_version = {$schema->version}");
            return true;
        }

        // TODO: Implement other migrations, possibly as php scripts?
        //       Maybe have a special case for renaming columns?
        $latest_schema = self::loadLatestSchema();
        if ($version != $latest_schema->version) {
            $current_schema = self::loadOldSchema($version);
            Log::info("Migrate schema {$current_schema->version} to {$latest_schema->version}");
            self::migrateSchema($db, $current_schema, $latest_schema);
            Log::info("Migration success, schema {$latest_schema->version}");
            $db->exec("PRAGMA user_version = {$latest_schema->version}");
            return true;
        }

        // Nothing to do.
        return false;
    }

    static function loadLatestSchema() {
        $schemas = glob(BOOST_TASKS_ROOT.'/migrations/????????-??-schema.neon');
        sort($schemas);
        $latest_schema = end($schemas);
        $schema_version = preg_replace('~([0-9]{8})-([0-9]{2})-schema.neon~', '\1\2', basename($latest_schema));
        if (strlen($schema_version) != 10) { throw new RuntimeException("Invalid schema file: {$latest_schema}"); }
        $schema_version = intval($schema_version);
        $r = new Migrations_Schema;
        $r->version = $schema_version;
        $r->path = $latest_schema;
        $r->tables = Neon::decode(file_get_contents($latest_schema));
        return $r;
    }

    static function loadOldSchema($version) {
        $version_regexp = '~([0-9]{8})([0-9]{2})$~';
        if (!preg_match($version_regexp, $version)) {
            throw new RuntimeException("Invalid schema version: {$version}");
        }
        $filename = preg_replace($version_regexp, '\1-\2-schema.neon', $version);
        $path = BOOST_TASKS_ROOT."/migrations/{$filename}";
        if (!is_file($path)) {
            throw new RuntimeException("Unable to find schema at: {$path}");
        }
        $r = new Migrations_Schema;
        $r->version = $version;
        $r->path = $path;
        $r->tables = Neon::decode(file_get_contents($path));
        return $r;
    }

    static function migrateSchema($db, $old, $new) {
        $delete_tables = array_diff(array_keys($old->tables), array_keys($new->tables));
        $create_tables = array_diff(array_keys($new->tables), array_keys($old->tables));
        $tables_in_both = array_intersect(array_keys($new->tables), array_keys($old->tables));

        foreach ($create_tables as $table_name) {
            self::createTable($db, $table_name, $new->tables[$table_name]);
        }

        foreach ($tables_in_both as $table_name) {
            self::updateTableIfNecessary($db, $old, $new, $table_name);
        }

        foreach ($delete_tables as $table_name) {
            self::renameTable($db, $table_name, self::versionedTableName($table_name, $old));
        }
    }

    static function createTable($db, $table, $columns) {
        $create_table_sql = "CREATE TABLE `$table` (\n";
        $columns_sql = array();
        foreach($columns as $name => $type) {
            $columns_sql[] = "    `{$name}` $type";
        }
        $create_table_sql .=  implode(",\n", $columns_sql);
        $create_table_sql .= "\n)";
        $db->exec($create_table_sql);
    }

    static function updateTableIfNecessary($db, $old_schema, $new_schema, $table) {
        $old_columns = $old_schema->tables[$table];
        $new_columns = $new_schema->tables[$table];

        $create_columns = array_diff_key($new_columns, $old_columns);
        $delete_columns = array_diff_key($old_columns, $new_columns);
        $change_columns = array();
        foreach(array_intersect_key($new_columns, $old_columns) as $name => $new_type) {
            // TODO: Normalize type?
            if ($new_type != $old_columns[$name]) {
                $change_columns[$name] = $new_type;
            }
        }

        if ($create_columns || $delete_columns || $change_columns) {
            $old_table_name = self::versionedTableName($table, $old_schema);
            self::renameTable($db, $table, $old_table_name);
            self::createTable($db, $table, $new_columns);
            $db->exec("INSERT INTO `{$table}` (`".
                implode("`, `", array_keys($change_columns)).
                "`) SELECT `".
                implode("`, `", array_keys($change_columns)).
                "` FROM {$old_table_name}");
        }
    }

    static function renameTable($db, $old_name, $new_name) {
        $db->exec("ALTER TABLE `{$old_name}` RENAME TO `{$new_name}`");
    }

    static function versionedTableName($table_name, $schema) {
        return "{$table_name}_old_{$schema->version}";
    }

    static function migration_Null($db) {
    }

    static function migration_Initialise($db) {
        $schema = "
            CREATE TABLE `event` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `github_id` TEXT, `branch` TEXT, `repo` TEXT, `payload` TEXT, `created` NUMERIC, `sequence_start` INTEGER, `type` TEXT);
            CREATE TABLE `eventstate` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `start_id` TEXT, `last_id` TEXT, `name` TEXT);
            CREATE TABLE `githubcache` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `url` TEXT, `next_url` TEXT, `etag` TEXT, `body` TEXT);
            CREATE TABLE `history` (id INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT, `value` TEXT, `updated_on` DATETIME);
            CREATE TABLE `mirror` (id INTEGER PRIMARY KEY AUTOINCREMENT, `path` TEXT, `dirty` INTEGER, `url` TEXT);
            CREATE TABLE `queue` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT, `last_github_id` TEXT, `type` TEXT);
            CREATE TABLE `variable` (id INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT, `value` TEXT, `updated_on` DATETIME);
            CREATE INDEX index_foreignkey_event_github ON `event` (github_id);
            CREATE INDEX index_foreignkey_eventstate_last ON `eventstate` (last_id);
            CREATE INDEX index_foreignkey_eventstate_start ON `eventstate` (start_id);
            CREATE INDEX index_foreignkey_queue_last_github ON `queue` (last_github_id);
        ";

        foreach(preg_split('@\s*;\s*@', $schema) as $command) {
            if ($command) { $db->exec($command); }
        }
    }

    static function migration_PullRequestEvent($db) {
        $db->exec('
            CREATE TABLE `pull_request_event` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `action` VARCHAR(20),
                `repo_full_name` TEXT,
                `pull_request_id` INTEGER,
                `pull_request_number` INTEGER,
                `pull_request_url` TEXT,
                `pull_request_title` TEXT,
                `pull_request_created_at` TEXT,
                `pull_request_updated_at` TEXT,
                `created_on` DATETIME DEFAULT CURRENT_TIMESTAMP
            );');
    }

    static function migration_PullRequest($db) {
        // This is a tad confusing as it's in a different database
        // to pull_request_event, although I'm creating the table
        // in both because I haven't implemented having separate
        // schemas.
        //
        // id is pull_request_id from github.
        $db->exec('
            CREATE TABLE `pull_request` (
                `id` INTEGER PRIMARY KEY,
                `repo_full_name` TEXT,
                `pull_request_number` INTEGER,
                `pull_request_url` TEXT,
                `pull_request_title` TEXT,
                `pull_request_created_at` TEXT,
                `pull_request_updated_at` TEXT
            );');
    }

    static function migration_PullRequestEventState($db) {
        $db->exec('
            ALTER TABLE `pull_request_event`
            ADD COLUMN `pull_request_state` TEXT
        ');
    }

    static function migration_HistoryDates($db) {
        // I've been using isoDateTime for dates, which was the standard
        // redbean way of doing it, but is problematic since it doesn't
        // include the timezone. It returns dates in php's configured
        // timezone, but sqlite assumes dates are UTC, so the times are
        // all wrong. Update every date in the format that isoDateTime
        // returns.
        //
        // Turns out this isn't really necessary as I set the timezone
        // to UTC in the init script. Still it's probably better to
        // include the timezone anyway.

        foreach($db->getAll('SELECT id, updated_on FROM history') as $record) {
            if (preg_match('@^\d{1,4}-\d{1,2}-\d{1,2} \d\d:\d\d:\d\d$@', $record['updated_on'])) {
                $date = new DateTime($record['updated_on']);
                $db->exec('UPDATE history SET updated_on = ? WHERE id = ?',
                    array($date->format('Y-m-d H:i:sP'), $record['id']));
            }
        }

        foreach($db->getAll('SELECT id, updated_on FROM variable') as $record) {
            if (preg_match('@^\d{1,4}-\d{1,2}-\d{1,2} \d\d:\d\d:\d\d$@', $record['updated_on'])) {
                $date = new DateTime($record['updated_on']);
                $db->exec('UPDATE variable SET updated_on = ? WHERE id = ?',
                    array($date->format('Y-m-d H:i:sP'), $record['id']));
            }
        }
    }

    static function migration_MirrorPriority($db) {
        $db->exec('
            ALTER TABLE `mirror`
            ADD COLUMN `priority` INTEGER DEFAULT 0
        ');

        $db->exec('
            UPDATE `mirror` SET `priority` = 0
        ');

        $boost_mirror = $db->findOne('mirror', 'path = ?', array('/boostorg/boost.git'));
        if (!$boost_mirror) {
            // If there isn't already an entry create one. The other
            // fields should be set the first time it's updated.
            $boost_mirror = $db->dispense('mirror');
            $boost_mirror->path = '/boostorg/boost.git';
            $boost_mirror->dirty = false;
        }
        $boost_mirror->priority = -1;
        $boost_mirror->store();

    }

    static function migration_Unique($db) {
        $tables = array(
            'event' => array(
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'github_id' => 'TEXT UNIQUE',
                'type' => 'TEXT',
                'branch' => 'TEXT',
                'repo' => 'TEXT',
                'payload' => 'TEXT',
                'created' => 'NUMERIC',
                'sequence_start' => 'INTEGER',
            ),
            'eventstate' => array(
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'name' => 'TEXT UNIQUE',
                'start_id' => 'TEXT',
                'last_id' => 'TEXT',
            ),
            'githubcache' => array(
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'url' => 'TEXT UNIQUE',
                'next_url' => 'TEXT',
                'etag' => 'TEXT',
                'body' => 'TEXT',
            ),
            'history' => array(
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'name' => 'TEXT',
                'value' => 'TEXT',
                'updated_on' => 'NUMERIC',
            ),
            'mirror' => array(
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'url' => 'TEXT UNIQUE',
                'path' => 'TEXT UNIQUE',
                'dirty' => 'INTEGER',
                'priority' => 'INTEGER DEFAULT 0',
            ),
            'queue' => array(
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'name' => 'TEXT UNIQUE',
                'last_github_id' => 'TEXT',
                'type' => 'TEXT',
            ),
            'variable' => array(
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'name' => 'TEXT UNIQUE',
                'value' => 'TEXT',
                'updated_on' => 'NUMERIC',
            )
        );

        foreach($tables as $table => $columns) {
            $table_old = "{$table}_old_20170604";
            self::renameTable($db, $table, $table_old);
            self::createTable($db, $table, $columns);
            $db->exec("INSERT INTO `{$table}` (`".
                implode("`, `", array_keys($columns)).
                "`) SELECT `".
                implode("`, `", array_keys($columns)).
                "` FROM {$table_old}");
        }
    }
}

class Migrations_Schema {
    var $version;
    var $path;
    var $tables;
}
