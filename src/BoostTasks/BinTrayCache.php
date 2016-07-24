<?php

namespace BoostTasks;

use EvilGlobals;
use RuntimeException;
use Log;
use Process;

class BinTrayCache {
    var $path;

    function __construct() {
        $this->path = EvilGlobals::dataPath('bintray');
    }

    function fetchDetails($branch) {
        $files = file_get_contents(
            "https://api.bintray.com/packages/boostorg/{$branch}/snapshot/files");
        if (!$files) {
            throw new RuntimeException("Error downloading file details from bintray.");
        }
        return self::getFileDetails($files);
    }

    static function getFileDetails($files) {
        // Not using 7zip files because 7z doesn't seem to be installed on the server.
        $extension_priorities = array_flip(array('tar.bz2', 'tar.gz', 'zip'));
        $low_priority = 100;

        $files = json_decode($files);
        if (!is_array($files)) {
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
        $branches = array();
        if (is_null($branch)) {
            foreach(scandir($this->path) as $dir) {
                if ($dir[0] === '.') { continue; }
                $branches[] = $dir;
            }
        }
        else {
            $branches = array($branch);
        }

        foreach($branches as $x) {
            $children = $this->scanBranch($x);
            $delete_before = max($children) - 5*60*60;
            foreach($children as $child_dir => $timestamp) {
                if ($timestamp < $delete_before) {
                    TempDirectory::recursiveRemove("{$child_dir}");
                }
            }
        }
    }

    private function scanBranch($branch) {
        $children = array();

        $dir = realpath("{$this->path}/{$branch}");
        if (!$dir) { return $children; }

        foreach(scandir($dir) as $child) {
            if ($child[0] === '.') { continue; }
            // Q: Accept any time? Or just the ones that are generated.
            $timestamp = strtotime($child);
            if ($timestamp === FALSE) {
                Log::warning("Invalid cache dir: {$dir}/{$child}");
            }
            else {
                $children["{$dir}/{$child}"] = $timestamp;
            }
        }

        return $children;
    }

    function extractSingleRootArchive($file_path, $tmpdir) {
        $subdir = "{$tmpdir}/new";
        mkdir($subdir);

        list($base_name, $extension) = explode('.', basename($file_path), 2);

        switch($extension) {
        case 'tar.bz2':
            Process::run("tar -xjf '{$file_path}'", $subdir, null, null, 60*10);
            break;
        case 'tar.gz':
            Process::run("tar -xzf '{$file_path}'", $subdir, null, null, 60*10);
            break;
        case '7z':
            Process::run("7z x '{$file_path}'", $subdir, null, null, 60*10);
            break;
        case 'zip':
            Process::run("unzip '{$file_path}'", $subdir, null, null, 60*10);
            break;
        default:
            assert(false);
        }

        // Find the extracted tarball in the temporary directory.
        $new_directories = array_filter(scandir($subdir),
            function($x) { return $x[0] != '.'; });
        if (count($new_directories) == 0) {
            throw new RuntimeException("Error extracting archive");
        }
        else if (count($new_directories) != 1) {
            throw new RuntimeException("Multiple roots in archive");
        }
        return "{$subdir}/".reset($new_directories);
    }
}
