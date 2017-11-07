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
}

$x = new BinTrayCacheTest();
$x->run();