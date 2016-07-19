<?php

require_once(__DIR__.'/../vendor/autoload.php');
Tester\Environment::setup();

class TestBase extends \Tester\TestCase {
    function setup() {
        EvilGlobals::init(array('testing' => true));
    }

    function tearDown() {
        EvilGlobals::$instance = null;
        Log::$log = null;
        BoostTasks\Db::$instance = null;
    }
}
