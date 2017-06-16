<?php

namespace BoostTasks;

use EvilGlobals;
use Log;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RuntimeException;

class Documentation {
    static function install($bintray_version, $dir) {
        // Get archive path setting.
        $archives_path = EvilGlobals::settings('website-archives');
        if (!$archives_path) {
            throw new RuntimeException("website-archives not set");
        }
        if (!is_dir($archives_path)) {
            throw new RuntimeException("website-archives not a directory");
        }
        $archives_path = rtrim($archives_path, '/');

        // Check the location we'll install the documentation at.
        // TODO: Store date instead of version?
        $destination_path = "{$archives_path}/{$dir}";
        $version = is_file("{$destination_path}/.bintray-version") ?
            file_get_contents("{$destination_path}/.bintray-version") :
            '';

        $cache = new BinTrayCache;

        // Not using 7zip files because 7z isn't installed on the server.
        $extension_priorities = array_flip(array('tar.bz2', 'tar.gz', 'zip'));

        $file_list = array();
        foreach($cache->fetchDetails($bintray_version) as $x) {
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

        foreach($file_list as $file) {
            if ($version == $file->version) {
                Log::info("{$bintray_version} documentation: Already installed, version {$file->version}.");
                return $destination_path;
            }

            Log::info("{$bintray_version} documentation: Attempt to install {$file->name}, version {$file->version}.");

            // Download tarball.
            try {
                $file_path = $cache->cachedDownload($file);
            } catch (RuntimeException $e) {
                // TODO: Better error handling. This doesn't distinguish between
                //       things which should cause us to give up entirely, and
                //       things which should cause us to try the next possible download.
                Log::error("Download error: {$e->getMessage()}");
                $file_path = null;
            }

            if (!$file_path) {
                Log::error("Download failed.");
                continue;
            }

            Log::debug("{$bintray_version} documentation: Extracting to {$destination_path}.");

            // Extract into a temporary directory.
            $temp_directory = new TempDirectory("{$archives_path}/tmp");
            $extract_path = $cache->extractSingleRootArchive($file_path, $temp_directory->path);

            // Add the version details.
            file_put_contents("{$extract_path}/.bintray-version", $file->version);

            // Find and remove redirects to master.
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                "{$extract_path}/doc/html")) as $x)
            {
                $path = $x->getPathname();
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                if (filesize($path) <= 2000 && ($extension == 'htm' || $extension == 'html') &&
                    preg_match(
                        '@<meta\s+http-equiv\s*=\s*["\']?refresh["\']?\s+content\s*=\s*["\']0;\s*URL=http://www.boost.org/doc/libs/master/@i',
                        file_get_contents($path)))
                {
                    echo "Removing redirect to master from {$bintray_version} at {$path}.\n";
                    unlink($path);
                }
            }

            // Replace the old documentation.
            // Would be nice to overwrite old archive in a cleaner manner...
            if (realpath($destination_path)) { rename($destination_path, "{$temp_directory->path}/old"); }
            rename($extract_path, $destination_path);

            $cache->cleanup($file);
            Log::info("{$bintray_version} documentation: Successfully installed documentation.");
            return $destination_path;
        }

        throw new RuntimeException("Unable to download any of the files.");
    }
}
