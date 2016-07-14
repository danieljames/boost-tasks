<?php

use Nette\Neon\Neon;
use Nette\Object;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use BoostTasks\Db;

class EvilGlobals extends Object {
    static $settings_types = array(
        'data' => array('type' => 'path', 'default' => '../update-data'),
        'username' => array('type' => 'string'),
        'password' => array('type' => 'string'),
        'github-webhook-secret' => array('type' => 'string'),
        'website-data' => array('type' => 'path'),
        'website-archives' => array('type' => 'path'),
        'push-to-repo' => array('type' => 'boolean', 'default' => false),
        'superproject-branches' => array('type' => 'map', 'default' => array(),
            'sub' => array('type' => 'string'),
        ),
        'config-paths' => array('type' => 'array', 'default' => array(),
            'sub' => array('type' => 'path'),
        ),
    );
    static $settings_reader;

    static $instance = null;

    private $settings;
    var $database;
    var $data_root;
    var $branch_repos;
    var $github_cache;

    static function init($options = array()) {
        if (!self::$settings_reader) {
            self::$settings_reader = new EvilGlobals_SettingsReader(self::$settings_types, __DIR__.'/..');
        }
        self::$instance = new EvilGlobals($options);
    }

    private function __construct($options = array()) {
        Log::$log = new Logger('boost update log');
        $formatter = new LineFormatter;
        $formatter->includeStacktraces();

        if (array_get($options, 'testing')) {
            // Just skipping configuration completely for now, will certainly
            // have to do something better in the future.
            $this->settings = self::$settings_reader->initial_settings();
        }
        else {
            // Initial logging settings, for loading configuration.
            // Q: Should this be done before handling command line options?

            $stdout_handler = new StreamHandler("php://stdout",
                array_get($options, 'verbose') ? Logger::DEBUG : Logger::WARNING);
            $stdout_handler->setFormatter($formatter);
            Log::$log->setHandlers(array($stdout_handler));

            // Load settings

            if (array_key_exists('config-file', $options)) {
                $path = $options['config-file'];
                if ($path instanceof SplFileInfo) { $path = $path->getRealPath(); }
            }
            else {
                $path = __DIR__.'/../var/config.neon';
                if (!is_file($path)) {
                    echo "Config file not found.\n\n";
                    echo "See README.md for configuration instructions.\n";
                    exit(1);
                }
                $path = realpath($path);
            }

            $this->settings = self::$settings_reader->read_config($path);

            // Set up repo directory.

            $data_root = $this->settings['data'];
            if (!is_dir($data_root)) { mkdir($data_root); }
            $this->data_root = $data_root;

            // Set up logging again.

            $stdout_level = array_get($options, 'verbose') ? Logger::DEBUG :
                (array_get($options, 'cron') ? Logger::ERROR : Logger::INFO);

            $log_file = "{$this->data_root}/log.txt";
            $log_handler = new StreamHandler($log_file, Logger::INFO);
            $log_handler->setFormatter($formatter);
            $stdout_handler = new StreamHandler("php://stdout", $stdout_level);
            $stdout_handler->setFormatter($formatter);

            Log::$log->setHandlers(array($log_handler, $stdout_handler));
        }
    }

    static function settings($key, $default = null) {
        if (array_key_exists($key, self::$instance->settings)) {
            return self::$instance->settings[$key];
        }
        else {
            throw new LogicException("Unknown settings key: {$key}");
        }
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

    static function database() {
        if (!self::$instance->database) {
            // Set up the database

            $db = Db::create("sqlite:".self::data_path()."/cache.db");
            Migrations::migrate($db);
            self::$instance->database = $db;
        }

        return self::$instance->database;
    }

    static function safe_settings() {
        $settings = EvilGlobals::$instance->settings;
        if (!empty($settings['password'])) { $settings['password'] = '********'; }
        return $settings;
    }
}

class EvilGlobals_SettingsReader {
    var $path_base;
    var $settings_types;

    function __construct($settings_types, $path_base) {
        $this->settings_types = $settings_types;
        $this->path_base = $path_base;
    }

    function initial_settings() {
        $settings = array();
        foreach($this->settings_types as $key => $details) {
            $settings[$key] = array_get($details, 'default');
            if (!is_null($settings[$key]) && $details['type'] == 'path') {
                $settings[$key] = self::resolve_path($settings[$key], $this->path_base);
            }
        }
        return $settings;
    }

    function read_config($path, $settings = null) {
        if (is_null($settings)) { $settings = $this->initial_settings(); }

        $config = is_readable($path) ? file_get_contents($path) : false;
        if ($config === false) {
            throw new RuntimeException("Unable to read config file: {$path}");
        }
        $config = Neon::decode($config);
        if ($config) {
            foreach ($config as $key => $value) {
                $details = array_get($this->settings_types, $key);
                if (!$details) {
                    Log::warning("Unknown setting: {$key}.");
                    continue;
                }

                $value = $this->check_setting($key, $value, $details, dirname($path));

                switch($key) {
                case 'config-paths':
                    foreach ($value as $config_path) {
                        if (!is_string($config_path)) {
                            throw new RuntimeException("'config-paths' should only contain strings.");
                        }
                        if ($config_path[0] !== '/') {
                            $config_path = dirname($path).'/'.$config_path;
                        }
                        $settings = $this->read_config($config_path, $settings);
                    }
                    break;
                default:
                    $settings[$key] = $value;
                    break;
                }
            }
        }

        return $settings;
    }

    function check_setting($key, $value, $setting_details, $path) {
        switch($setting_details['type']) {
        case 'string':
            if (is_array($value) || is_object($value) ) {
                throw new RuntimeException("Invalid string for setting: {$key}");
            }
            return (string) $value;
        case 'path':
            if (!is_string($value)) {
                throw new RuntimeException("Invalid path for setting: {$key}");
            }
            return self::resolve_path($value, $path);
        case 'boolean':
            // TODO: Maybe accept 1/0/"true"/"false'?
            if (!is_bool($value) ) {
                throw new RuntimeException("Invalid boolean for setting: {$key}");
            }
            return $value;
        case 'array':
            if (!is_array($value)) { $value = array($value); }

            $result = array();
            foreach($value as $child) {
                $result[] = $this->check_setting($key, $child, $setting_details['sub'], $path);
            }
            return $result;
        case 'map':
            if (!is_array($value)) {
                throw new RuntimeException("Invalid map for setting: {$key}");
            }

            $result = array();
            foreach($value as $child_key => $child) {
                $result[$child_key] =
                    $this->check_setting("{$key}/{$child_key}", $child, $setting_details['sub'], $path);
            }
            return $result;
        }
    }

    function resolve_path($path, $base) {
        if ($path[0] != '/') {
            $path = rtrim($base, '/')."/{$path}";
        }
        $path = rtrim($path, '/');
        return $path;
    }
}
