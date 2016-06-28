<?php

use Tester\Assert;

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

$test = new EvilGlobalsTest();
$test->run();
