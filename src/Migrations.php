<?php

use Nette\Object;
use BoostTasks\Db;
use BoostTasks\DbSchema;
use BoostTasks\DbSchemaDiff;

class Migrations extends Object {
    static $schema = "
        CREATE TABLE `event` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `github_id` TEXT, `branch` TEXT, `repo` TEXT, `payload` TEXT, `created` NUMERIC, `sequence_start` INTEGER, `type` TEXT);
        CREATE TABLE `eventstate` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `start_id` TEXT, `last_id` TEXT, `name` TEXT);
        CREATE TABLE `githubcache` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `url` TEXT, `next_url` TEXT, `etag` TEXT, `body` TEXT);
        CREATE TABLE `history` (id INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT, `value` TEXT, `updated_on` NUMERIC);
        CREATE TABLE `mirror` (id INTEGER PRIMARY KEY AUTOINCREMENT, `path` TEXT, `dirty` INTEGER, `url` TEXT);
        CREATE TABLE `queue` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT, `last_github_id` TEXT, `type` TEXT);
        CREATE TABLE `variable` (id INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT, `value` TEXT, `updated_on` NUMERIC);
        CREATE INDEX index_foreignkey_event_github ON `event` (github_id);
        CREATE INDEX index_foreignkey_eventstate_last ON `eventstate` (last_id);
        CREATE INDEX index_foreignkey_eventstate_start ON `eventstate` (start_id);
        CREATE INDEX index_foreignkey_queue_last_github ON `queue` (last_github_id);
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
            `pull_request_state` TEXT,
            `created_on` DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE `pull_request` (
            `id` INTEGER PRIMARY KEY,
            `repo_full_name` TEXT,
            `pull_request_number` INTEGER,
            `pull_request_url` TEXT,
            `pull_request_title` TEXT,
            `pull_request_created_at` TEXT,
            `pull_request_updated_at` TEXT
        );
        ";

    static function migrate($db) {
        //while($db->transaction(function() use($db) { return Migrations::single_migration($db); })) {}
        $db->transaction(function() use($db) {
            $current_schema = DbSchema::load($db);
            $new_schema = DbSchema::loadFromSql(Migrations::$schema);
            $diff = DbSchemaDiff::compare($current_schema, $new_schema);

            foreach($diff->new_tables as $table_name) {
                $new_schema->tables[$table_name]->create($db, $table_name);
            }
        });
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
}
