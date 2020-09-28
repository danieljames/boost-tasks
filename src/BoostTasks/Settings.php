<?php

namespace BoostTasks;

use Nette\Neon\Neon;
use Nette\Object;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Formatter\LineFormatter;
use BoostTasks\Db;
use BoostTasks\CommandLineOptions;
use BoostTasks\GitHubCache;
use BoostTasks\Log;
use BoostTasks\Migrations;
use LogicException;
use RuntimeException;

class Settings extends Object {
    static $settings_types = array(
        'data' => array('type' => 'path', 'default' => 'var/data'),
        'username' => array('type' => 'string'),
        'password' => array('type' => 'password'),
        'access-token' => array('type' => 'password'),
        'github-webhook-secret' => array('type' => 'password'),
        'cowic-username' => array('type' => 'string'),
        'cowic-password' => array('type' => 'password'),
        'website-data' => array('type' => 'path'),
        'website-archives' => array('type' => 'path'),
        'push-to-repo' => array('type' => 'boolean', 'default' => false),
        'superproject-repo' => array('type' => 'string', 'default' => 'boostorg/boost'),
        'superproject-branches' => array('type' => 'map', 'default' => array(),
            'sub' => array('type' => 'string'),
        ),
        'testing' => array('type' => 'private', 'default' => false),
    );
    static $settings_reader;

    static $instance = null;

    private $settings;
    var $database;
    var $data_root;
    var $branch_repos;
    var $github_cache;

    static function init($options = array()) {
        // CommandLineOptions returns a number to exit early.
        // Shouldn't really get here in that case, so maybe an assertion?
        if (is_numeric($options)) { exit($options); }

        if (!self::$settings_reader) {
            self::$settings_reader = new Settings_SettingsReader(self::$settings_types, BOOST_TASKS_ROOT);
        }
        self::$instance = new Settings($options);
    }

    private function __construct($options = array()) {
        Log::$log = new Logger('boost update log');
        $formatter = new LineFormatter;
        $formatter->includeStacktraces();

        // Initial logging settings, for loading configuration.
        // Q: Should this be done before handling command line options?

        $stdout_handler = new StreamHandler("php://stdout",
            array_get($options, 'verbose') ? Logger::DEBUG : Logger::WARNING);
        $stdout_handler->setFormatter($formatter);
        Log::$log->setHandlers(array($stdout_handler));

        if (array_get($options, 'testing')) {
            // Important: TestHandler has to be the first handler, so it must be pushed last.
            // TODO: Save it somewhere, so the tests don't need to rely on this.
            Log::$log->pushHandler(new TestHandler);

            // Just skipping configuration completely for now, will certainly
            // have to do something better in the future.
            $this->settings = array_merge(
                self::$settings_reader->initialSettings(),
                $options);
        }
        else {
            // Load settings

            if (array_key_exists('config-file', $options)) {
                $path = $options['config-file'];
                if ($path instanceof SplFileInfo) { $path = $path->getRealPath(); }
            }
            else {
                $path = BOOST_TASKS_ROOT.'/var/config.neon';
                if (!is_file($path)) {
                    echo "Config file not found.\n\n";
                    echo "See README.md for configuration instructions.\n";
                    exit(1);
                }
                $path = realpath($path);
            }

            $this->settings = self::$settings_reader->readConfig($path);

            // Set up repo directory.

            $data_root = $this->settings['data'];
            if (!is_dir($data_root)) { mkdir($data_root); }
            $this->data_root = $data_root;

            // Set up logging again.

            $stdout_level = array_get($options, 'verbose') ? Logger::DEBUG :
                (array_get($options, 'cron') ? Logger::WARNING : Logger::INFO);

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

    static function dataPath($thing = null) {
        $data_root = self::$instance->data_root;
        if (is_null($thing)) {
            return $data_root;
        }
        else {
            $path = "{$data_root}/{$thing}";
            if (!is_dir($path)) { mkdir($path, 0755, true); }
            return $path;
        }

    }

    static function branchRepos() {
        if (!is_array(self::$instance->branch_repos)) {
            $super_root = self::dataPath('super');

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

    static function githubCache() {
        if (!self::$instance->github_cache) {
            self::$instance->github_cache = new GitHubCache(
                self::$instance->settings['username'],
                self::$instance->settings['password'],
                self::$instance->settings['access-token']);
        }

        return self::$instance->github_cache;
    }

    static function database() {
        if (!self::$instance->database) {
            // Set up the database
            if (self::$instance->settings['testing']) {
                $db = Db::create("sqlite::memory:");
            }
            else {
                $db = Db::create("sqlite:".self::dataPath()."/cache.db");
            }

            Migrations::migrate($db);
            self::$instance->database = $db;
        }

        return self::$instance->database;
    }

    static function safeSettings() {
        $settings = Settings::$settings_reader->outputSettings(Settings::$instance->settings);
        return $settings;
    }
}

class Settings_SettingsReader {
    var $path_base;
    var $settings_types;

    function __construct($settings_types, $path_base) {
        assert(!array_key_exists('config-paths', $settings_types));
        $settings_types['config-paths'] =
            array('type' => 'array', 'default' => array(),
                'sub' => array('type' => 'path'),
            );


        $this->settings_types = $settings_types;
        $this->path_base = $path_base;
    }

    function initialSettings() {
        $settings = array();
        foreach($this->settings_types as $key => $details) {
            if ($key != 'config-paths') {
                $settings[$key] = array_get($details, 'default');
                if (!is_null($settings[$key]) && $details['type'] == 'path') {
                    $settings[$key] = self::resolvePath($settings[$key], $this->path_base);
                }
            }
        }
        return $settings;
    }

    function readConfig($path, $settings = null) {
        if (is_null($settings)) { $settings = $this->initialSettings(); }

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

                $value = $this->checkSetting($key, $value, $details, dirname($path));

                switch($key) {
                case 'config-paths':
                    foreach ($value as $config_path) {
                        $settings = $this->readConfig($config_path, $settings);
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

    function checkSetting($key, $value, $setting_details, $path) {
        switch($setting_details['type']) {
        case 'string':
        case 'password':
            if (is_array($value) || is_object($value) ) {
                throw new RuntimeException("Invalid string for setting: {$key}");
            }
            return (string) $value;
        case 'path':
            if (!is_string($value)) {
                throw new RuntimeException("Invalid path for setting: {$key}");
            }
            return self::resolvePath($value, $path);
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
                $result[] = $this->checkSetting($key, $child, $setting_details['sub'], $path);
            }
            return $result;
        case 'map':
            if (!is_array($value)) {
                throw new RuntimeException("Invalid map for setting: {$key}");
            }

            $result = array();
            foreach($value as $child_key => $child) {
                $result[$child_key] =
                    $this->checkSetting("{$key}/{$child_key}", $child, $setting_details['sub'], $path);
            }
            return $result;
        case 'private':
            // Should really make it look like 'unknown setting' warning.
            throw new RuntimeException("Private setting: {$key}");
        default:
            throw new LogicException("Invalid setting type: {$setting_details['type']}");
        }
    }

    // Transform the settings for public output.
    function outputSettings($settings) {
        $safe_settings = array();
        foreach ($settings as $key => $value) {
            $value = $this->outputSettingsValue($value, $this->settings_types[$key]);
            if (!is_null($value)) {
                $safe_settings[$key] = $value;
            }
        }
        return $safe_settings;
    }

    function outputSettingsValue($value, $setting_details) {
        if (is_null($value)) { return null; }

        switch($setting_details['type']) {
        case 'private':
            return null;
        case 'password':
            return '********';
        case 'array':
        case 'map':
            $result = array();
            foreach($value as $key => $x) {
                $result[$key] = $this->outputSettingsValue($x, $setting_details['sub']);
            }
            return $result;
        default:
            return $value;
        }
    }

    function resolvePath($path, $base) {
        if ($path[0] != '/') {
            $path = rtrim($base, '/')."/{$path}";
        }
        $path = rtrim($path, '/');
        return $path;
    }
}
