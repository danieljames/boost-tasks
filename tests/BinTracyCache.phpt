<?php

use Tester\Assert;
use BoostTasks\BinTrayCache;

require_once(__DIR__.'/bootstrap.php');

class BinTrayCacheTest extends \TestBase
{
    function testParseVersion() {
        Assert::same(array(1, 65, 1, '', ''), BinTrayCache::parseVersion("1.65.1"));
        Assert::same(array(58, 69, 0, 'beta2', ''), BinTrayCache::parseVersion("58.69.beta2"));
        Assert::same(array(1, 50, 0, 'beta1', ''), BinTrayCache::parseVersion("boost_1.50.0.beta"));
    }

    function testCommonUrlBase() {
        Assert::null(BinTrayCache::urlBaseDir(array()));
        Assert::same("/a/b/", BinTrayCache::urlBaseDir(array("/a/b/c")));
        Assert::same("/a/b/", BinTrayCache::urlBaseDir(array("/a/b/c", "/a/b/d")));
        Assert::same("/a/", BinTrayCache::urlBaseDir(array("/a/b/c", "/a/b/d", "/a/c/e/")));
        Assert::same("/a/", BinTrayCache::urlBaseDir(array("/a/b1/c", "/a/b2/c")));
    }
}

$x = new BinTrayCacheTest();
$x->run();