<?php

namespace BoostTasks;

use EvilGlobals;
use Log;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

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
        foreach($cache->fetchDetails($bintray_version) as $file) {
            if ($version == $file->version) {
                Log::info("{$bintray_version} documentation: Already installed: {$file->name}, version {$file->version}.");
                return;
            }

            Log::info("{$bintray_version} documentation: Attempt to install {$file->name}, version {$file->version}.");

            // Download tarball.
            $file_path = $cache->cachedDownload($file);
            if (!$file_path) {
                Log::info("Download failed.");
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
                    echo "Removing redirect to master at {$path}.\n";
                    unlink($path);
                }
            }

            // Replace the old documentation.
            // Would be nice to overwrite old archive in a cleaner manner...
            if (realpath($destination_path)) { rename($destination_path, "{$temp_directory->path}/old"); }
            rename($extract_path, $destination_path);

            $cache->cleanup($file);
            Log::info("{$bintray_version} documentation: Successfully installed documentation.");
            return;
        }

        throw new RuntimeException("Unable to download any of the files.");
    }
}
