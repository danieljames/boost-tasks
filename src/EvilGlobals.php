<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class EvilGlobals {
    static $settings;

    // Filesystem layout
    static $data_root;
    static $mirror_root;
    static $super_root;

    static $website_data;

    static $branch_repos = array();
    static $github_cache;

    static function init() {
        // Load settings

        self::$settings = array(
            'data' => '../data',
            'username' => null,
            'password' => null,
            'website-data' => null,
            'push-to-repo' => false,
            'superproject-branches' => array(),
        );

        if (is_file(self::resolve_path('config.json'))) {
            self::$settings = array_merge(self::$settings,
                    json_decode(file_get_contents(__DIR__."/../config.json"), true));
        }
        else {
            echo "Config file not found.\n";
            exit(1);
        }

        // Set up repo directory.

        $data_root = self::resolve_path(self::$settings['data']);
        self::$data_root = $data_root;
        self::$mirror_root = "{$data_root}/mirror";
        self::$super_root = "{$data_root}/super";

        if (!is_dir(self::$data_root)) { mkdir(self::$data_root); }
        if (!is_dir(self::$mirror_root)) { mkdir(self::$mirror_root); }
        if (!is_dir(self::$super_root)) { mkdir(self::$super_root); }

        // Set up website data directory.

        if (self::$settings['website-data']) {
            self::$website_data = self::resolve_path(self::$settings['website-data']);
        }

        // Set up the logger.

        Log::$log = new Logger('boost update log');
        Log::$log->pushHandler(
                new StreamHandler("{$data_root}/log.txt", Logger::INFO));

        // Set up the database

        R::setup("sqlite:{$data_root}/cache.db", 'user', 'password');

        // Set up repos

        foreach(self::$settings['superproject-branches'] as $branch => $submodule_branch) {
            self::$branch_repos[] = array(
                'path' => self::$super_root."/".$branch,
                'superproject-branch' => $branch,
                'submodule-branch' => $submodule_branch,
            );
        }

        // Set up cache

        self::$github_cache = new \GitHubCache(
                self::$settings['username'],
                self::$settings['password']);

    }

    static function resolve_path($path) {
        if ($path[0] != '/') {
            $path = __DIR__.'/../'.$path;
        }
        $path = rtrim($path, '/');
        return $path;
    }
}