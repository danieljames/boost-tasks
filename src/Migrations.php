<?php

use Nette\Object;
use BoostTasks\Db;

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

    static function single_migration($db) {
        $num_versions = count(self::$versions);
        $version = $db->getCell('PRAGMA user_version');
        if ($version < $num_versions) {
            Log::info("Call migration {$version}: ".self::$versions[$version]);
            call_user_func(self::$versions[$version], $db);
            ++$version;
            Log::info("Migration success, now at version {$version}");
            $db->exec("PRAGMA user_version = {$version}");
        }
        return $version < $num_versions;
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
        $tables = [
            'event' => [
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'github_id' => 'TEXT UNIQUE',
                'type' => 'TEXT',
                'branch' => 'TEXT',
                'repo' => 'TEXT',
                'payload' => 'TEXT',
                'created' => 'NUMERIC',
                'sequence_start' => 'INTEGER',
            ],
            'eventstate' => [
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'name' => 'TEXT UNIQUE',
                'start_id' => 'TEXT',
                'last_id' => 'TEXT',
            ],
            'githubcache' => [
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'url' => 'TEXT UNIQUE',
                'next_url' => 'TEXT',
                'etag' => 'TEXT',
                'body' => 'TEXT',
            ],
            'history' => [
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'name' => 'TEXT',
                'value' => 'TEXT',
                'updated_on' => 'NUMERIC',
            ],
            'mirror' => [
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'url' => 'TEXT UNIQUE',
                'path' => 'TEXT UNIQUE',
                'dirty' => 'INTEGER',
                'priority' => 'INTEGER DEFAULT 0',
            ],
            'queue' => [
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'name' => 'TEXT UNIQUE',
                'last_github_id' => 'TEXT',
                'type' => 'TEXT',
            ],
            'variable' => [
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'name' => 'TEXT UNIQUE',
                'value' => 'TEXT',
                'updated_on' => 'NUMERIC',
            ]
        ];

        foreach($tables as $table => $columns) {
            $table_old = "{$table}_old_20170604";
            $db->exec("ALTER TABLE `$table` RENAME TO `{$table_old}`");
            $create_table_sql = "CREATE TABLE `$table` (\n";
            $columns_sql = [];
            foreach($columns as $name => $type) {
                $columns_sql[] = "    `{$name}` $type";
            }
            $create_table_sql .=  implode(",\n", $columns_sql);
            $create_table_sql .= "\n)";
            $db->exec($create_table_sql);
            $db->exec("INSERT INTO `{$table}` (`".
                implode("`, `", array_keys($columns)).
                "`) SELECT `".
                implode("`, `", array_keys($columns)).
                "` FROM {$table_old}");
        }
    }
}
