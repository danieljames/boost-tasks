<?php

use Tester\Assert;
use BoostTasks\TempDirectory;

require_once(__DIR__.'/bootstrap.php');

class TempDirectoryTest extends \TestBase {
    function testUseSysRootByDefault() {
        $temp_directory = new TempDirectory();
        $path = $temp_directory->path;

        $this->basicTests($temp_directory);
        Assert::false(is_dir($path));
    }

    function testExplicitPath() {
        $parent_path = __DIR__.'/output';
        if (!is_dir($parent_path)) { mkdir($parent_path); }
        Assert::true(is_dir($parent_path));

        $temp_directory = new TempDirectory($parent_path);
        $path = $temp_directory->path;
        Assert::true(is_dir($path));
        Assert::same(realpath($parent_path), realpath(dirname($path)));

        $this->basicTests($temp_directory);
        Assert::false(is_dir($path));
    }

    function basicTests(&$temp_directory) {
        $path = $temp_directory->path;
        Assert::true(is_dir($path));
        // Check that group + all have no permissions.
        Assert::same(0, fileperms($path) & 0077);

        $tmp_file = "{$temp_directory->path}/tmp.txt";
        Assert::false(realpath($tmp_file));
        file_put_contents($tmp_file, "Hello");
        Assert::true(is_file($tmp_file));

        $tmp_dir = "{$temp_directory->path}/tmp";
        Assert::false(realpath($tmp_dir));
        mkdir($tmp_dir);
        Assert::true(is_dir($tmp_dir));

        $temp_directory = null;
        Assert::false(is_file($tmp_file));
        Assert::false(is_dir($tmp_dir));
        Assert::false(is_dir($path));
    }

    function testErrorIfDirectoryDoesntExist() {
        Assert::exception(function() {
            new TempDirectory(__DIR__.'/non-existant');
        }, 'RuntimeException');
    }

    function testErrorIfDirectoryNotWritable() {
        $temp_directory = new TempDirectory();
        $dir = "{$temp_directory->path}/x";
        mkdir($dir);

        // Try with not writable
        chmod($dir, 0555);
        Assert::exception(function() use($dir) {
            new TempDirectory($dir);
        }, 'RuntimeException');

        // Try with not executable
        chmod($dir, 0666);
        Assert::exception(function() use($dir) {
            new TempDirectory($dir);
        }, 'RuntimeException');
    }
}

$x = new TempDirectoryTest();
$x->run();
