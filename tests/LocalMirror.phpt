<?php

use Tester\Assert;
use BoostTasks\LocalMirror;

require_once(__DIR__.'/bootstrap.php');

class LocalMirrorTest extends TestBase {
    function testResolveGitUrl() {
        // Not how normal URLs behave, but this is how git URLs behave.
        Assert::same(
            'http://example.com/a.git',
            LocalMirror::resolveGitUrl('../a.git', 'http://example.com/b.git'));
        Assert::same(
            'http://example.com/b.git/a.git',
            LocalMirror::resolveGitUrl('./a.git', 'http://example.com/b.git'));
        Assert::same(
            'http://example.com/example/a.git',
            LocalMirror::resolveGitUrl('../a.git', 'http://example.com/example/b.git'));
        Assert::same(
            'http://example.com/boost/a.git',
            LocalMirror::resolveGitUrl('../../boost/a.git', 'http://example.com/example/b.git'));
        // TODO: Not supported
        //Assert::same(
        //    'git@github.com:boostorg/boost.git',
        //    LocalMirror::resolveGitUrl('../../boostorg/boost.git', 'git@github.com:example/example.git'));
        Assert::same(
            '/a/b/d/e.git',
            LocalMirror::resolveGitUrl('../d/e.git', '/a/b/c.git'));
        Assert::same(
            '/d/e.git',
            LocalMirror::resolveGitUrl('../../../d/e.git', '/a/b/c.git'));
        Assert::same(
            '/d/e.git',
            LocalMirror::resolveGitUrl('/d/e.git', ''));

        Assert::exception(function() {
            LocalMirror::resolveGitUrl('../a.git', '');
        }, 'RuntimeException');
        Assert::exception(function() {
            LocalMirror::resolveGitUrl('../a.git', '/');
        }, 'RuntimeException');
        Assert::exception(function() {
            LocalMirror::resolveGitUrl('../../a.git', '/b');
        }, 'RuntimeException');

        Assert::exception(function() {
            LocalMirror::resolveGitUrl('http://example.com/a.git', 'git@github.com:something/x.git');
        }, 'RuntimeException');
    }
}

$test = new LocalMirrorTest();
$test->run();
