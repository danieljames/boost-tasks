<?php

namespace BoostTasks;

use RuntimeException;

class TempDirectory {
    var $path;

    function __construct($tmp_root = null) {
        if (is_null($tmp_root)) { $tmp_root = sys_get_temp_dir(); }

        if (!is_dir($tmp_root)) {
            throw new RuntimeException("Temporary directory doesn't exist: {$tmp_root}");
        }

        if (!is_writable($tmp_root) || !is_executable($tmp_root)) {
            throw new RuntimeException("Temporary directory isn't writable: {$tmp_root}");
        }

        $path_base = realpath($tmp_root)."/";

        // Create temporary directory.

        $temp_name = tempnam($tmp_root, "download");
        if (!$temp_name) { return false; }
        $temp_name = realpath($temp_name);
        if (strpos($temp_name, $path_base) !== 0) {
            throw new RuntimeException("Incorrect location for temporary directory.");
        }
        // Race condition here, but seems unlikely to be a real problem.
        unlink($temp_name);
        mkdir($temp_name, 0700);
        if (!$temp_name || !is_dir($temp_name) || strpos($temp_name, $path_base) !== 0) {
            throw new RuntimeException("Something went wrong creating temporary directory.");
        }

        $this->path = $temp_name;
    }

    function __destruct() {
        if ($this->path) { self::recursiveRemove($this->path); }
    }

    // TODO: Better error handling.
    static function recursiveRemove($path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
        else if (is_dir($path)) {
            foreach(scandir($path) as $child) {
                if ($child == '.' || $child == '..') { continue; }
                $child_path = "{$path}/{$child}";
                self::recursiveRemove($child_path);
            }
            rmdir($path);
        }
    }
}
