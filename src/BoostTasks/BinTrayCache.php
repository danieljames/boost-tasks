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

    function fetchDetails($bintray_version) {
        if ($bintray_version == 'master' || $bintray_version == 'develop') {
            $url = "https://api.bintray.com/packages/boostorg/{$bintray_version}/snapshot/files";
            $path_prefix = '';
        } else if (preg_match('@.*(beta|rc)\.?\d*$@', $bintray_version)) {
            $url = "https://api.bintray.com/packages/boostorg/beta/boost/files";
            $path_prefix = "{$bintray_version}/source/";
        } else {
            $url = "https://api.bintray.com/packages/boostorg/release/boost/files";
            $path_prefix = "{$bintray_version}/source/";
        }

        $files = file_get_contents($url);
        if (!$files) {
            throw new RuntimeException("Error downloading file details from bintray.");
        }

        $files = json_decode($files);
        if (!is_array($files)) {
            throw new RuntimeException("Error parsing latest details.");
        }

        $file_list = array();
        foreach($files as $x) {
            if (substr($x->path, 0, strlen($path_prefix)) == $path_prefix) {
                $file_list[] = $x;
            }
        }

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
        $meta_path = "{$download_path}.meta";

        if (!is_file($meta_path)) {
            if (!is_dir($download_dir)) {
                mkdir($download_dir, 0777, true);
            }

            if (is_file($download_path) && hash_file('sha256', $download_path) != $file->sha256) {
                unlink($download_path);
            }

            if (!is_file($download_path)) {
                if (!$this->downloadFile(
                    "http://dl.bintray.com/boostorg/{$file->repo}/{$file->path}",
                    $download_path))
                {
                    return null;
                }
            }

            file_put_contents($meta_path, json_encode($file));
        }

        if (hash_file('sha256', $download_path) != $file->sha256) {
            unlink($download_path);
            throw new RuntimeException("File signature doesn't match: {$url}");
        }

        return $download_path;
    }

    // TODO: Download to temporary file and move into position.
    // TODO: Better error handling, what to do if there's a failure during download?
    function downloadFile($url, $dst_path) {
        $download_fh = fopen($url, 'rb');
        if (!$download_fh) { return false; }
        if (feof($download_fh)) {
            throw new RuntimeException("Empty download: {$url}");
        }

        $save_fh = fopen($dst_path, "wb");
        if (!$save_fh) {
            throw new RuntimeException("Problem opening local file at {$dst_path}");
        }

        do {
            $chunk = fread($download_fh, 8192);
            if ($chunk === false) {
                throw new RuntimeException("Problem reading chunk: {$url}");
            }
            if (fwrite($save_fh, $chunk) === false) {
                throw new RuntimeException("Problem writing chunk: {$url}");
            }
        } while (!feof($download_fh));

        return true;
    }

    // TODO: Would be nice if the returned data was in the same format as
    //       fetchDetails/getFileDetails.
    function latestDownload($branch) {
        $children = $this->scanBranch($branch);
        arsort($children);
        foreach ($children as $child_dir => $timestamp) {
            foreach(glob("{$child_dir}/*/*.meta") as $meta_path) {
                if (preg_match('@^(.*[.](?:tar.bz2|tar.gz|zip))[.]meta$@', $meta_path, $matches)) {
                    $file_path = $matches[1];

                    $meta = json_decode(file_get_contents($meta_path), true);
                    if (!$meta) {
                        Log::warning("Unable to decode meta file at {$meta_path}");
                        continue;
                    }

                    if (hash_file('sha256', $file_path) != $meta['sha256']) {
                        Log::warning("Hash doesn't match meta file at {$file_path}");
                        continue;
                    }

                    $meta['path'] = $file_path;
                    return $meta;
                }
            }
        }
    }

    function cleanup($file = null) {
        $branches = array();
        if (is_null($file)) {
            foreach(scandir($this->path) as $dir) {
                if ($dir[0] === '.') { continue; }
                $branches[] = $dir;
            }
        }
        else {
            $branches = array($file->repo);
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

    // Returns array of path => timestamp.
    // Q: Is this silly? Sorting the path name lexicographically
    //    should work fine. I suppose the date format might change
    //    one day.
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
