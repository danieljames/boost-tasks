<?php

namespace BoostTasks;

use EvilGlobals;
use RuntimeException;
use Log;

class BinTrayCache {
    var $path;

    function __construct() {
        $this->path = EvilGlobals::dataPath('bintray');
    }

    function fetchDetails($branch) {
        // Not using 7zip files because 7z doesn't seem to be installed on the server.
        $extension_priorities = array_flip(array('tar.bz2', 'tar.gz', 'zip'));
        $low_priority = 100;

        // Download the file list from bintray.
        $files = file_get_contents(
            "https://api.bintray.com/packages/boostorg/{$branch}/snapshot/files");
        if (!$files) {
            throw new RuntimeException("Error downloading file details from bintray.");
        }

        $files = json_decode($files);
        if (!$files) {
            throw new RuntimeException("Error parsing latest details.");
        }

        $file_list = array();
        foreach($files as $x) {
            list($x_base_name, $x_extension) = explode('.', $x->name, 2);
            if (array_key_exists($x_extension, $extension_priorities)) {
                $x->priority = $extension_priorities[$x_extension];
                $file_list[] = $x;
            }
        }
        if (!$file_list) {
            throw new RuntimeException("Unable to find file to download.");
        }

        // If two files have different versions, use most recent.
        // Otherwise sort by priority.
        usort($file_list, function($x, $y) {
            return
                -($x->version != $y->version
                    ? strtotime($x->created) - strtotime($y->created) : 0) ?:
                ($x->priority - $y->priority);
        });

        return $file_list;
    }

    // Return path the file was downloaded to, null if the file isn't available.
    // Throws an exception if something goes wrong while downloading, or the
    // hash of the downloaded file doesn't match.
    function cachedDownload($file) {
        $date = date('Y-m-d\TH:i', strtotime($file->created));
        // 'repo' is actually the branch, that's just the way bintray is organised.
        $download_dir = "{$this->path}/{$file->repo}/{$date}/{$file->sha1}";
        $download_path = "{$download_dir}/{$file->name}";

        if (!is_file($download_path)) {
            if (!is_dir($download_dir)) {
                mkdir($download_dir, 0777, true);
            }

            if (!$this->downloadFile(
                "http://dl.bintray.com/boostorg/{$file->repo}/{$file->name}",
                $download_path))
            {
                return null;
            }
        }

        if (hash_file('sha256', $download_path) != $file->sha256) {
            throw new RuntimeException("File signature doesn't match.");
        }

        return $download_path;
    }

    // TODO: Download to temporary file and move into position.
    // TODO: Better error handling, what to do if there's a failure during download?
    function downloadFile($url, $dst_path) {
        $download_fh = fopen($url, 'rb');
        if (!$download_fh) { return false; }

        $save_fh = fopen($dst_path, "wb");
        if (!$save_fh) {
            throw new RuntimeException("Problem opening local file to write to.");
        }

        while (!feof($download_fh)) {
            $chunk = fread($download_fh, 8192);
            if ($chunk === false) {
                throw new RuntimeException("Problem reading chunk.");
            }
            if (fwrite($save_fh, $chunk) === false) {
                throw new RuntimeException("Problem writing chunk.");
            }
        }

        return true;
    }

    function cleanup($branch = null) {
        $dirs = array();
        if (is_null($branch)) {
            foreach(scandir($this->path) as $dir) {
                if ($dir[0] === '.') { continue; }
                $dirs[] = realpath("{$this->path}/{$dir}");
            }
        }
        else {
            // Error if branch doesn't exist?
            $path = realpath("{$this->path}/{$branch}");
            if ($path) { $dirs[] = $path; }
        }

        foreach($dirs as $dir) {
            $children = array();
            foreach(scandir($dir) as $child) {
                if ($child[0] === '.') { continue; }
                // Q: Accept any time? Or just the ones that are generated.
                $timestamp = strtotime($child);
                if ($timestamp === FALSE) {
                    Log::warning("Invalid cache dir: {$dir}/{$child}");
                }
                else {
                    $children[$child] = $timestamp;
                }
            }

            $delete_before = max($children) - 5*60*60;
            foreach($children as $child_dir => $timestamp) {
                if ($timestamp < $delete_before) {
                    TempDirectory::recursiveRemove("{$dir}/{$child_dir}");
                }
            }
        }
    }
}
