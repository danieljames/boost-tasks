<?php

use Nette\Neon\Neon;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class EvilGlobals {
    static $settings = array(
        'data' => '../update-data',
        'username' => null,
        'password' => null,
        'website-data' => null,
        'push-to-repo' => false,
        'superproject-branches' => array(),
    );

    // Filesystem layout
    static $data_root;
    static $mirror_root;
    static $super_root;
    static $repos_root;

    static $website_data;

    static $branch_repos = array();
    static $github_cache;

    static function init($path, $options) {
        Log::$log = new Logger('boost update log');
        if (array_get($options, 'testing')) {
            // Just skipping configuration completely for now, will certainly
            // have to do something better in the future.
        }
        else {
            // Initial logging settings, for loading configuration.
            // Q: Should this be done before handling command line options?

            Log::$log->setHandlers(array(
                new StreamHandler("php://stdout", array_get($options, 'verbose') ? Logger::DEBUG : Logger::WARNING),
            ));

            // Load settings

            $path = self::resolve_path($path);
            if (is_file($path)) {
                self::$settings = self::read_config($path, self::$settings);
            }
            else {
                echo <<<EOL
Config file not found.

See README.md for configuration instructions.

EOL;
                exit(1);
            }

            // Set up repo directory.

            $data_root = self::resolve_path(self::$settings['data']);
            self::$data_root = $data_root;
            self::$mirror_root = "{$data_root}/mirror";
            self::$super_root = "{$data_root}/super";
            self::$repos_root = "{$data_root}/repos";

            if (!is_dir(self::$data_root)) { mkdir(self::$data_root); }
            if (!is_dir(self::$mirror_root)) { mkdir(self::$mirror_root); }
            if (!is_dir(self::$super_root)) { mkdir(self::$super_root); }
            if (!is_dir(self::$repos_root)) { mkdir(self::$repos_root); }

            // Set up logging again.

            if (array_get($options, 'cron')) {
                Log::$log->setHandlers(array(
                    new StreamHandler(self::$data_root."/log.txt", Logger::INFO),
                    new StreamHandler("php://stdout", array_get($options, 'verbose') ? Logger::DEBUG : Logger::ERROR)
                ));
            }
            else {
                Log::$log->setHandlers(array(
                    new StreamHandler(self::$data_root."/log.txt", Logger::INFO),
                    new StreamHandler("php://stdout", array_get($options, 'verbose') ? Logger::DEBUG : Logger::INFO)
                ));
            }

            // Set up website data directory.

            if (self::$settings['website-data']) {
                self::$website_data = self::resolve_path(self::$settings['website-data']);
            }

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

            // Set up the database

            R::setup("sqlite:".self::$data_root."/cache.db", 'user', 'password');
            Migrations::migrate();
            R::freeze(true);
        }
    }

    static function resolve_path($path) {
        if ($path[0] != '/') {
            $path = __DIR__.'/../'.$path;
        }
        $path = rtrim($path, '/');
        return $path;
    }

    static function read_config($path, $defaults = array()) {
        $config = is_readable($path) ? file_get_contents($path) : false;
        if ($config === false) {
            throw new RuntimeException("Unable to read config file: {$path}");
        }
        $config = Neon::decode($config);
        $config = $config ? array_merge($defaults, $config) : $defaults;

        if (isset($config['config-paths'])) {
            $config_paths = $config['config-paths'];
            unset($config['config-paths']);
            if (is_string($config_paths)) { $config_paths = Array($config_paths); }
            foreach ($config_paths as $config_path) {
                if (!is_string($config_path)) {
                    throw new RuntimeException("'config-paths' should only contain strings.");
                }
                if ($config_path[0] !== '/') {
                    $config_path = dirname($path).'/'.$config_path;
                }
                $config = self::read_config($config_path, $config);
            }
        }

        return $config;
    }

    static function safe_settings() {
        $settings = EvilGlobals::$settings;
        if (!empty($settings['password'])) { $settings['password'] = '********'; }
        return $settings;
    }
}
