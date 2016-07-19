<?php

use Tester\Assert;

require_once(__DIR__.'/bootstrap.php');

class ProcessTest extends Tester\TestCase {
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
        Assert::same(array(), iterator_to_array(Process::read_lines("true")));
        Assert::same(array(), iterator_to_array(Process::read_lines("printf ''")));
        Assert::same(array(''), iterator_to_array(Process::read_lines("echo")));
    }

    function testStdout() {
        Assert::same("Hello\n", Process::read("echo Hello"));
        Process::run("echo Hello");
    }

    function testStderr() {
        $e = Assert::exception(function () {
            Process::run("echo Hello 1>&2 && false");
        }, 'Process_Exception');
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
        }, 'Process_Exception');
        Assert::same("Error\nError\n", $e->stderr);
    }

    function testNoTrailingNewline() {
        Assert::same("Hello", Process::read("printf Hello"));
        Assert::same("One\nTwo", Process::read("echo One; printf Two"));
        Assert::same("One\nTwo", Process::read(
            "echo One; printf error 1>&2; printf Two"));

        Assert::same(array("Hello"),
            iterator_to_array(Process::read_lines("printf Hello")));
        Assert::same(array("One", "Two"),
            iterator_to_array(Process::read_lines("echo One;printf Two")));
        Assert::same(array("One", "Two"),
            iterator_to_array(Process::read_lines(
                "echo One;printf error 1>&2;printf Two")));

        $e = Assert::exception(function() {
            Process::run("printf error 1>&2; false");
        }, 'Process_Exception');
        Assert::same("error", $e->stderr);

        $e = Assert::exception(function() {
            Process::run("printf One; printf error 1>&2; echo Two; false");
        }, 'Process_Exception');
        Assert::same("error", $e->stderr);
    }
}

$test = new ProcessTest();
$test->run();
