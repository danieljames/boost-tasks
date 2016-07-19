<?php

use Tester\Assert;
use BoostTasks\TempDirectory;

require_once(__DIR__.'/bootstrap.php');

class EvilGlobalsTest extends TestBase {
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

    function testDataPath() {
        $temp_directory = new TempDirectory();
        file_put_contents("{$temp_directory->path}/config.neon", 'data: root');
        EvilGlobals::init(array('config-file' => "{$temp_directory->path}/config.neon"));

        $root = "{$temp_directory->path}/root";
        Assert::true(is_dir($root));
        Assert::same($root, EvilGlobals::dataPath());

        $sub1 = "{$root}/sub1";
        Assert::false(is_dir($sub1));
        Assert::same($sub1, EvilGlobals::dataPath("sub1"));
        Assert::true(is_dir($sub1));

        $sub2 = "{$root}/sub2/sub2";
        Assert::false(is_dir($sub2));
        Assert::same($sub2, EvilGlobals::dataPath("sub2/sub2"));
        Assert::true(is_dir($sub2));
    }

    function testBranches1() {
        $temp_directory = new TempDirectory();
        file_put_contents("{$temp_directory->path}/config.neon", 'data: root');
        EvilGlobals::init(array('config-file' => "{$temp_directory->path}/config.neon"));

        Assert::same(array(), EvilGlobals::branchRepos());
    }

    function testBranches2() {
        $temp_directory = new TempDirectory();
        file_put_contents("{$temp_directory->path}/config.neon", "
            data: root
            superproject-branches: {master: develop, develop: beta}
        ");
        EvilGlobals::init(array('config-file' => "{$temp_directory->path}/config.neon"));

        $branches = EvilGlobals::branchRepos();
        $super_base = "{$temp_directory->path}/root/super";
        Assert::true(is_dir($super_base));

        Assert::same(array(0,1), array_keys($branches));

        Assert::same("{$super_base}/master", $branches[0]['path']);
        Assert::same("master", $branches[0]['superproject-branch']);
        Assert::same("develop", $branches[0]['submodule-branch']);

        Assert::same("{$super_base}/develop", $branches[1]['path']);
        Assert::same("develop", $branches[1]['superproject-branch']);
        Assert::same("beta", $branches[1]['submodule-branch']);
    }

    function testDatabase() {
        $temp_directory = new TempDirectory();
        file_put_contents("{$temp_directory->path}/config.neon", 'data: root');
        EvilGlobals::init(array('config-file' => "{$temp_directory->path}/config.neon"));

        $db = EvilGlobals::database();
        Assert::true(is_file("{$temp_directory->path}/root/cache.db"));
    }

    function testSafeSettings() {
        EvilGlobals::init(array('config-file' => __DIR__.'/test-config1.neon'));

        $safe_settings = EvilGlobals::safeSettings();
        Assert::same('name', $safe_settings['username']);
        Assert::same('********', $safe_settings['password']);
        Assert::false(strpos(print_r($safe_settings, true), 'private'));
    }

    function testGithubCache() {
        EvilGlobals::init(array('config-file' => __DIR__.'/test-config1.neon'));

        $github_cache = EvilGlobals::githubCache();
        Assert::same('name', $github_cache->username);
        Assert::same('private', $github_cache->password);
    }

    function testUnknownSetting() {
        EvilGlobals::init(array('config-file' => __DIR__.'/test-config1.neon'));
        Assert::exception(function() { EvilGlobals::settings('non-existant'); },
            'LogicException');
    }
}

class EvilGlobals_SettingsReaderTest extends TestBase {
    function testErrors() {
        $reader = new EvilGlobals_SettingsReader(array(), __DIR__);
        $temp_directory = new TempDirectory();
        Assert::exception(function() use($temp_directory, $reader) {
            $reader->readConfig("{$temp_directory->path}/config.neon");
        }, 'RuntimeException');
    }

    function testSimpleSettings() {
        $reader = new EvilGlobals_SettingsReader(array(
            'string1' => array('type' => 'string', 'default' => 'hello'),
            'string2' => array('type' => 'string'),
            'boolean1' => array('type' => 'boolean', 'default' => false),
            'boolean2' => array('type' => 'boolean', 'default' => true),
            'boolean3' => array('type' => 'boolean'),
        ), __DIR__);

        $settings1 = $reader->initialSettings();
        Assert::same('hello', $settings1['string1']);
        Assert::null($settings1['string2']);
        Assert::same(false, $settings1['boolean1']);
        Assert::same(true, $settings1['boolean2']);
        Assert::null($settings1['boolean3']);

        $temp_directory = new TempDirectory();

        file_put_contents("{$temp_directory->path}/config1.neon", "
            string1: 10
            string2: \"quoted\"
            boolean1: true
            boolean3: false
        ");
        $settings2 = $reader->readConfig("{$temp_directory->path}/config1.neon");
        Assert::same('10', $settings2['string1']);
        Assert::same('quoted', $settings2['string2']);
        Assert::same(true, $settings2['boolean1']);
        Assert::same(true, $settings2['boolean2']);
        Assert::same(false, $settings2['boolean3']);

        file_put_contents("{$temp_directory->path}/config2.neon", "
            string1:
                map1: x
                map2: y
        ");

        Assert::exception(function() use($reader, $temp_directory) {
            $reader->readConfig("{$temp_directory->path}/config2.neon");
        }, 'RuntimeException');

        file_put_contents("{$temp_directory->path}/config3.neon", "
            boolean1: 0
        ");

        Assert::exception(function() use($reader, $temp_directory) {
            $reader->readConfig("{$temp_directory->path}/config3.neon");
        }, 'RuntimeException');
    }

    function testPathSetting() {
        $reader = new EvilGlobals_SettingsReader(array(
            'path1' => array('type' => 'path', 'default' => '.'),
            'path2' => array('type' => 'path', 'default' => '..'),
            'path3' => array('type' => 'path', 'default' => basename(__FILE__)),
            'path4' => array('type' => 'path', 'default' => null),
        ), __DIR__);

        $settings1 = $reader->initialSettings();
        Assert::same(array('path1','path2','path3','path4'), array_keys($settings1));
        Assert::same(realpath(__DIR__), realpath($settings1['path1']));
        Assert::same(realpath(dirname(__DIR__)), realpath($settings1['path2']));
        Assert::same(realpath(__FILE__), realpath($settings1['path3']));
        Assert::null($settings1['path4']);

        $temp_directory = new TempDirectory();

        $config_path = "{$temp_directory->path}/config.neon";
        file_put_contents($config_path, "path1: config.neon\n");
        $settings2 = $reader->readConfig($config_path);
        Assert::same(array('path1','path2','path3','path4'), array_keys($settings2));
        Assert::same(realpath($config_path), realpath($settings2['path1']));
        Assert::null($settings2['path4']);

        mkdir("{$temp_directory->path}/sub");
        file_put_contents("{$temp_directory->path}/sub/config.neon", "config-paths: ../config.neon");
        $settings3 = $reader->readConfig("{$temp_directory->path}/sub/config.neon");
        Assert::same(array('path1','path2','path3','path4'), array_keys($settings3));
        Assert::same(realpath($config_path), realpath($settings3['path1']));

        file_put_contents("{$temp_directory->path}/invalid.neon", "path1: ['.', '..']\n");
        Assert::exception(function() use($reader, $temp_directory) {
            $reader->readConfig("{$temp_directory->path}/invalid.neon");
        }, 'RuntimeException');
    }

    function testPrivateSetting() {
        $reader = new EvilGlobals_SettingsReader(array(
            'value' => array('type' => 'string'),
            'private' => array('type' => 'private', 'default' => 'default')
        ), __DIR__);

        $settings = $reader->initialSettings();
        Assert::same(array('value', 'private'), array_keys($settings));
        Assert::null($settings['value']);
        Assert::same('default', $settings['private']);

        $temp_directory = new TempDirectory();

        $config_path = "{$temp_directory->path}/config.neon";
        file_put_contents($config_path, "value: check\n");
        $settings2 = $reader->readConfig($config_path);
        Assert::same(array('value', 'private'), array_keys($settings2));
        Assert::same('check', $settings2['value']);
        Assert::same('default', $settings2['private']);

        $config_path2 = "{$temp_directory->path}/config2.neon";
        file_put_contents($config_path2, "private: check\n");
        Assert::exception(function() use($reader, $config_path2) {
            $reader->readConfig($config_path2);
        }, 'RuntimeException', '#private#');
    }
}


$test = new EvilGlobalsTest();
$test->run();

$test = new EvilGlobals_SettingsReaderTest();
$test->run();
