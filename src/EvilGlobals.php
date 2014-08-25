<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class EvilGlobals {
    static $data_root;
    static $branch_repos;
    static $github_cache;
    static $settings;

    static function init() {
        // Set up repo directory.

        $data_root = __DIR__."/../data";
        self::$data_root = $data_root;

        if (!is_dir($data_root)) {
            mkdir($data_root);
        }

        // Load settings

        self::$settings = array(
            'username' => null,
            'password' => null,
            'website-data' => null,
            'push-to-repo' => false,
        );

        if (is_file(__DIR__."/../config.json")) {
            self::$settings = array_merge(self::$settings,
                    json_decode(file_get_contents(__DIR__."/../config.json"), true));
        }

        // Set up the logger.

        Log::$log = new Logger('boost update log');
        Log::$log->pushHandler(
                new StreamHandler("{$data_root}/log.txt", Logger::INFO));

        // Set up the database

        R::setup("sqlite:{$data_root}/cache.db", 'user', 'password');

        // Set up repos

        self::$branch_repos = array(
            "develop" => "{$data_root}/develop",
            "master" => "{$data_root}/master"
        );

        // Set up cache

        self::$github_cache = new \GitHubCache(
                self::$settings['username'],
                self::$settings['password']);

    }
}
