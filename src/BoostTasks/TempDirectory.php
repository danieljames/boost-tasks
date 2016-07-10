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

        if (!is_writable($tmp_root)) {
            throw new RuntimeException("Temporary directory isn't writable: {$tmp_root}");
        }

        // Create temporary directory.
        // Race condition here, but seems unlikely to be a real problem.
        $temp_name = tempnam($tmp_root, "download");
        if (!$temp_name) { return false; }
        if (strpos($temp_name, "{$tmp_root}/") !== 0) {
            throw new RuntimeException("Incorrect location for temporary directory.");
        }
        unlink($temp_name);
        mkdir($temp_name);
        $temp_name = realpath($temp_name);
        if (!$temp_name || !is_dir($temp_name) || strpos($temp_name, "{$tmp_root}/") !== 0) {
            throw new RuntimeException("Something went wrong creating temporary directory.");
        }

        $this->path = $temp_name;
    }

    function __destruct() {
        if ($this->path) { self::recursive_remove($this->path); }
    }

    function getPath() {
        return $this->path;
    }

    // TODO: Better error handling.
    static function recursive_remove($path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
        else if (is_dir($path)) {
            foreach(scandir($path) as $child) {
                if ($child == '.' || $child == '..') { continue; }
                $child_path = "{$path}/{$child}";
                self::recursive_remove($child_path);
            }
            rmdir($path);
        }
    }
}
