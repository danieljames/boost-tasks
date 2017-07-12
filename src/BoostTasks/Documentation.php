<?php

namespace BoostTasks;

use EvilGlobals;
use Log;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RuntimeException;

class Documentation {
    static function install($cache, $file_details, $dir) {
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
            trim(file_get_contents("{$destination_path}/.bintray-version")) :
            null;
        if (is_string($version) && $version[0] === '{') {
            $version = json_decode($version, true);
        } else {
            $version = array(
                'hash' => $version,
                'created' => null
            );
        }

        $file_list = self::getPrioritizedDownloads($file_details->files);
        foreach($file_list as $file) {
            if ($version['hash'] == $file->version) {
                Log::info("{$file_details->bintray_version} documentation: Already installed, version {$file->version}.");
                return $destination_path;
            }

            if ($version['created'] && $version['created'] > $file->created) {
                Log::info("{$file_details->bintray_version} documentation: Newer version already installed, version {$file->version}.");
                return $destination_path;
            }

            Log::info("{$file_details->bintray_version} documentation: Attempt to install {$file->name}, version {$file->version}.");
            if (self::downloadAndInstall($file_details, $file, $destination_path)) {
                $cache->cleanup($file);
                Log::info("{$file_details->bintray_version} documentation: Successfully installed documentation.");
                return $destination_path;
            }
        }

        throw new RuntimeException("Unable to download any of the files.");
    }

    static function getPrioritizedDownloads($files) {
        // Not using 7zip files because 7z isn't installed on the server.
        $extension_priorities = array_flip(array('tar.bz2', 'tar.gz', 'zip'));

        // Store a date for each version that can be used in the sort.
        // These dates aren't very accurate, but will usually give the correct order
        // on the versions. Could get a more accurate order from the git history.
        $version_dates = array();

        $version_sort = array();
        $file_list = array();
        $priority_sort = array();
        foreach($files as $x) {
            list($x_base_name, $x_extension) = explode('.', $x->name, 2);
            if (array_key_exists($x_extension, $extension_priorities)) {
                $file_list[] = $x;
                $priority_sort[] = $extension_priorities[$x_extension];
                if (!array_key_exists($x->version, $version_dates) || $x->created < $version_dates[$x->version]) {
                    $version_dates[$x->version] = $x->created;
                }
            }
        }
        if (!$file_list) {
            throw new RuntimeException("Unable to find file to download.");
        }
        foreach($file_list as $x) {
            $version_sort[] = $version_dates[$x->version];
        }

        // Sort by version date first, priority second.
        array_multisort(
            $version_sort, SORT_DESC,
            $priority_sort,
            $file_list);

        return $file_list;
    }

    static function downloadAndInstall($file_details, $file, $destination_path) {
        // Download tarball.
        try {
            $file_path = $file_details->cachedDownload($file);
        } catch (RuntimeException $e) {
            // TODO: Better error handling. This doesn't distinguish between
            //       things which should cause us to give up entirely, and
            //       things which should cause us to try the next possible download.
            Log::error("Download error: {$e->getMessage()}");
            return false;
        }

        Log::debug("{$file_details->bintray_version} documentation: Extracting to {$destination_path}.");

        // Extract into a temporary directory.
        $archives_path = EvilGlobals::settings('website-archives');
        $temp_directory = new TempDirectory("{$archives_path}/tmp");
        $extract_path = BinTrayCache::extractSingleRootArchive($file_path, $temp_directory->path);

        // Add the version details.
        file_put_contents("{$extract_path}/.bintray-version", json_encode(array(
            'hash' => $file->version,
            'created' => $file->created,
        )));

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
                echo "Removing redirect to master from {$file_details->bintray_version} at {$path}.\n";
                unlink($path);
            }
        }

        // Replace the old documentation.
        // Would be nice to overwrite old archive in a cleaner manner...
        if (realpath($destination_path)) { rename($destination_path, "{$temp_directory->path}/old"); }
        rename($extract_path, $destination_path);

        return true;
    }
}
