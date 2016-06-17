<?php

use Tester\Assert;

require_once(__DIR__.'/bootstrap.php');

class EvilGlobalsTest extends Tester\TestCase {
    function testSettings() {
        EvilGlobals::init(array('path' => __DIR__.'/test-config1.neon'));
        Assert::same('name', EvilGlobals::settings('username'));
        Assert::null(EvilGlobals::settings('website-data'));
        Assert::same(realpath(__DIR__.'/data'), realpath(EvilGlobals::settings('data')));
        Assert::null(EvilGlobals::settings('non-existant'));
        Assert::same('default', EvilGlobals::settings('non-existant', 'default'));
    }

    function testSafeSettings() {
        EvilGlobals::init(array('path' => __DIR__.'/test-config1.neon'));

        $safe_settings = EvilGlobals::safe_settings();
        Assert::same('name', $safe_settings['username']);
        Assert::same('********', $safe_settings['password']);
        Assert::false(strpos(print_r($safe_settings, true), 'private'));
    }

    function testGithubCache() {
        EvilGlobals::init(array('path' => __DIR__.'/test-config1.neon'));

        $github_cache = EvilGlobals::github_cache();
        Assert::same('name', $github_cache->username);
        Assert::same('private', $github_cache->password);
    }
}

$test = new EvilGlobalsTest();
$test->run();
