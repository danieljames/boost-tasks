<?php

use Tester\Assert;
use BoostTasks\TempDirectory;

require_once(__DIR__.'/bootstrap.php');

class EvilGlobalsTest extends Tester\TestCase {
    function testSettings() {
        EvilGlobals::init(array('config-file' => __DIR__.'/test-config1.neon'));
        Assert::same('name', EvilGlobals::settings('username'));
        Assert::null(EvilGlobals::settings('website-data'));
        // Q: Do I really need to use realpath here?
        Assert::same(realpath(__DIR__.'/data'), realpath(EvilGlobals::settings('data')));
    }

    function testConfigPaths() {
        EvilGlobals::init(array('config-file' => __DIR__.'/test-config2.neon'));
        Assert::same('name', EvilGlobals::settings('username'));
        Assert::null(EvilGlobals::settings('website-data'));
        Assert::same(__DIR__.'/overwrite-config-paths', EvilGlobals::settings('data'));
    }

    function testSafeSettings() {
        EvilGlobals::init(array('config-file' => __DIR__.'/test-config1.neon'));

        $safe_settings = EvilGlobals::safe_settings();
        Assert::same('name', $safe_settings['username']);
        Assert::same('********', $safe_settings['password']);
        Assert::false(strpos(print_r($safe_settings, true), 'private'));
    }

    function testGithubCache() {
        EvilGlobals::init(array('config-file' => __DIR__.'/test-config1.neon'));

        $github_cache = EvilGlobals::github_cache();
        Assert::same('name', $github_cache->username);
        Assert::same('private', $github_cache->password);
    }

    function testUnknownSetting() {
        Assert::exception(function() { EvilGlobals::settings('non-existant'); },
            'LogicException');
    }
}

class EvilGlobals_SettingsReaderTest extends Tester\TestCase {
    function testPathSetting() {
        $reader = new EvilGlobals_SettingsReader(array(
            'path1' => array('type' => 'path', 'default' => '.'),
            'path2' => array('type' => 'path', 'default' => '..'),
            'path3' => array('type' => 'path', 'default' => basename(__FILE__)),
            'config-paths' => array('type' => 'array', 'default' => array(),
                'sub' => array('type' => 'path'),
            ),
        ), __DIR__);

        $settings1 = $reader->initial_settings();
        Assert::same(array('path1','path2','path3','config-paths'), array_keys($settings1));
        Assert::same(realpath(__DIR__), realpath($settings1['path1']));
        Assert::same(realpath(dirname(__DIR__)), realpath($settings1['path2']));
        Assert::same(realpath(__FILE__), realpath($settings1['path3']));

        $temp_directory = new TempDirectory();
        $config_path = "{$temp_directory->path}/config.neon";
        file_put_contents($config_path, "path1: config.neon\n");
        $settings2 = $reader->read_config($config_path);
        Assert::same(array('path1','path2','path3','config-paths'), array_keys($settings2));
        Assert::same(realpath($config_path), realpath($settings2['path1']));

        mkdir("{$temp_directory->path}/sub");
        file_put_contents("{$temp_directory->path}/sub/config.neon", "config-paths: ../config.neon");
        $settings3 = $reader->read_config("{$temp_directory->path}/sub/config.neon");
        Assert::same(realpath($config_path), realpath($settings3['path1']));
    }
}


$test = new EvilGlobalsTest();
$test->run();

$test = new EvilGlobals_SettingsReaderTest();
$test->run();
