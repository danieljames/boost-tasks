<?php

use Nette\Neon\Neon;
use Nette\Object;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class EvilGlobals extends Object {
    static $default_settings = array(
        'data' => '../update-data',
        'username' => null,
        'password' => null,
        'website-data' => null,
        'push-to-repo' => false,
        'superproject-branches' => array(),
    );

    static $instance = null;

    var $settings;

    // Filesystem layout
    var $data_root;
    var $website_data;
    var $branch_repos;
    var $github_cache;

    static function init($path, $options) {
        self::$instance = new EvilGlobals($path, $options);
    }

    private function __construct($path, $options) {
        Log::$log = new Logger('boost update log');
        if (array_get($options, 'testing')) {
            // Just skipping configuration completely for now, will certainly
            // have to do something better in the future.
            $this->settings = self::$default_settings;
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
                $this->settings = self::read_config($path, self::$default_settings);
            }
            else {
                echo <<<EOL
Config file not found.

See README.md for configuration instructions.

EOL;
                exit(1);
            }

            // Set up repo directory.

            $data_root = self::resolve_path($this->settings['data']);
            if (!is_dir($data_root)) { mkdir($data_root); }
            $this->data_root = $data_root;

            // Set up logging again.

            if (array_get($options, 'cron')) {
                Log::$log->setHandlers(array(
                    new StreamHandler("{$this->data_root}/log.txt", Logger::INFO),
                    new StreamHandler("php://stdout", array_get($options, 'verbose') ? Logger::DEBUG : Logger::ERROR)
                ));
            }
            else {
                Log::$log->setHandlers(array(
                    new StreamHandler("{$this->data_root}/log.txt", Logger::INFO),
                    new StreamHandler("php://stdout", array_get($options, 'verbose') ? Logger::DEBUG : Logger::INFO)
                ));
            }

            // Set up website data directory.

            if ($this->settings['website-data']) {
                $this->website_data = self::resolve_path($this->settings['website-data']);
            }

            // Set up the database
            // TODO: This doesn't repeat well.

            R::setup("sqlite:{$this->data_root}/cache.db");
            Migrations::migrate();
            R::freeze(true);
        }
    }

    static function settings($key, $default = null) {
        return array_get(self::$instance->settings, $key, $default);
    }

    static function data_path($thing = null) {
        $data_root = self::$instance->data_root;
        if (is_null($thing)) {
            return $data_root;
        }
        else {
            $path = "{$data_root}/{$thing}";
            if (!is_dir($path)) { mkdir($path); }
            return $path;
        }

    }

    static function website_data() {
        return self::$instance->website_data;
    }

    static function branch_repos() {
        if (!is_array(self::$instance->branch_repos)) {
            $super_root = self::data_path('super');

            $branch_repos = array();
            foreach(self::settings('superproject-branches', array()) as $branch => $submodule_branch) {
                $branch_repos[] = array(
                    'path' => "{$super_root}/{$branch}",
                    'superproject-branch' => $branch,
                    'submodule-branch' => $submodule_branch,
                );
            }

            self::$instance->branch_repos = $branch_repos;
        }

        return self::$instance->branch_repos;
    }

    static function github_cache() {
        if (!self::$instance->github_cache) {
            self::$instance->github_cache = new \GitHubCache(
                self::$instance->settings['username'],
                self::$instance->settings['password']);
        }

        return self::$instance->github_cache;
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
