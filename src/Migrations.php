<?php

use Nette\Object;
use BoostTasks\Db;

class Migrations extends Object {
    static $versions = array(
        'Migrations::migration_Initialise',
        'Migrations::migration_Null',
    );

    static function migrate() {
        while(Db::transaction('Migrations::single_migration')) {}
    }

    static function single_migration() {
        $num_versions = count(self::$versions);
        $version = Db::getCell('PRAGMA user_version');
        if ($version < $num_versions) {
            Log::info("Call migration {$version}: ".self::$versions[$version]);
            call_user_func(self::$versions[$version]);
            ++$version;
            Log::info("Migration success, now at version {$version}");
            Db::exec("PRAGMA user_version = {$version}");
        }
        return $version < $num_versions;
    }

    static function migration_Null() {
    }

    static function migration_Initialise() {
        $schema = "
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
        ";

        foreach(explode(';', $schema) as $command) {
            Db::exec($command);
        }
    }
}
