<?php

use BoostTasks\Settings;
use BoostTasks\Log;

require_once(__DIR__.'/../vendor/autoload.php');
Tester\Environment::setup();

class TestBase extends \Tester\TestCase {
    function setup() {
        Settings::init(array('testing' => true));
    }

    function tearDown() {
        Settings::$instance = null;
        Log::$log = null;
        BoostTasks\Db::$instance = null;
    }
}
