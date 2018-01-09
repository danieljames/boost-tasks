<?php

use Tester\Assert;
use BoostTasks\Process;
use BoostTasks\Process_Timeout;

require_once(__DIR__.'/bootstrap.php');

class ProcessTest extends TestBase {
    function testStatus() {
        Assert::same(0, Process::status("true"));
        Assert::same(1, Process::status("false"));

        // grep: 0 = match found, 1 = no match, 2 = error.
        Assert::same(0, Process::status("echo hello | grep hello"));
        Assert::same(1, Process::status("echo goodbye | grep hello"));
        Assert::same(0, Process::status("grep boost composer.json",
            __DIR__.'/..'));
        Assert::same(1, Process::status("grep no-match composer.json",
            __DIR__.'/..'));
        Assert::same(2, Process::status("grep boost non-existant.json",
            __DIR__.'/..'));
    }

    function testEmptyStdout() {
        Assert::same("", Process::read("true"));
        Assert::same(array(), iterator_to_array(Process::readLines("true")));
        Assert::same(array(), iterator_to_array(Process::readLines("printf ''")));
        Assert::same(array(''), iterator_to_array(Process::readLines("echo")));
    }

    function testStdout() {
        Assert::same("Hello\n", Process::read("echo Hello"));
        Process::run("echo Hello");
    }

    function testStderr() {
        $e = Assert::exception(function () {
            Process::run("echo Hello 1>&2 && false");
        }, 'BoostTasks\Process_FailedExitCode');
        Assert::same("Hello\n", $e->stderr);
    }

    function testMixed() {
        Assert::same("Hello\n", Process::read("
            echo Error 1>&2
            echo Hello
            echo Error 1>&2"));

        $e = Assert::exception(function () {
            Process::read("
                echo Error 1>&2
                echo Hello
                echo Error 1>&2
                false");
        }, 'BoostTasks\Process_FailedExitCode');
        Assert::same("Error\nError\n", $e->stderr);
    }

    function testNoTrailingNewline() {
        Assert::same("Hello", Process::read("printf Hello"));
        Assert::same("One\nTwo", Process::read("echo One; printf Two"));
        Assert::same("One\nTwo", Process::read(
            "echo One; printf error 1>&2; printf Two"));

        Assert::same(array("Hello"),
            iterator_to_array(Process::readLines("printf Hello")));
        Assert::same(array("One", "Two"),
            iterator_to_array(Process::readLines("echo One;printf Two")));
        Assert::same(array("One", "Two"),
            iterator_to_array(Process::readLines(
                "echo One;printf error 1>&2;printf Two")));

        $e = Assert::exception(function() {
            Process::run("printf error 1>&2; false");
        }, 'BoostTasks\Process_FailedExitCode');
        Assert::same("error", $e->stderr);

        $e = Assert::exception(function() {
            Process::run("printf One; printf error 1>&2; echo Two; false");
        }, 'BoostTasks\Process_FailedExitCode');
        Assert::same("error", $e->stderr);
    }

    function testTimeout() {
        // I'm not too bothered if the timeout isn't great or even a tad buggy,
        // just want to make sure it vaguely works.

        $sleep_command = 'sleep 2';
        $timeout = 0.1;
        if (function_exists('xdebug_code_coverage_started') && xdebug_code_coverage_started()) {
            $error_at = $timeout + 0.3;
        }
        else {
            $error_at = $timeout + 0.2;
        }

        Assert::true($this->runProcessWithTimeout($sleep_command, $timeout) <= $error_at);
        Assert::true(
            $this->runProcessWithTimeout("printf 'hello'; {$sleep_command}", $timeout)
            <= $error_at);
        Assert::true(
            $this->runProcessWithTimeout("printf 'hello' 1>&2; {$sleep_command}", $timeout)
            <= $error_at);
        Assert::true(
            $this->runProcessWithTimeout("printf 'hello'; printf 'hello' 1>&2; {$sleep_command}", $timeout)
            <= $error_at);
    }

    function runProcessWithTimeout($command, $timeout) {
        $start = microtime(true);
        try {
            Process::run($command, null, null, null, $timeout);
            Assert::false(true);
        }
        catch (Process_Timeout $e) {}
        $time = microtime(true) - $start;
        echo $time,"\n";
        return $time;
    }
}

$test = new ProcessTest();
$test->run();
