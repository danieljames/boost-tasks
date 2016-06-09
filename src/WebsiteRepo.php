<?php

/*
 * Copyright 2015 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

class WebsiteRepo extends Repo {
    function __construct() {
        parent::__construct('website', 'master',
            EvilGlobals::repos_root().'/website');
    }

    function updateDocumentationList($mirror, $version) {
        $website_repo = $this; // NOTE: Need to work on PHP 5.3.
        return $this->attemptAndPush(function() use($website_repo, $mirror, $version) {
            // Create a configuration file, so that we can run the scripts.
            $local_config_file = $website_repo->path.'/common/code/boost_config_local.php';
            if (!is_file($local_config_file)) {
                file_put_contents($local_config_file, '');
            }

            // Update the documentation list
            passthru('php '.
                $website_repo->path.'/site-tools/update-doc-list.php '.
                '--quiet '.
                $mirror->mirror_root.'/boostorg/boost.git '.
                $version);

            if ($version) {
                $message = "Update documentation list for ".$version;
            } else {
                $message = "Update documentation list";
            }

            return $website_repo->commitAll($message);
        });
    }

    function updateSuperProject($super) {
        $website_repo = $this; // NOTE: Need to work on PHP 5.3.
        Log::info("Update maintainer list for {$super->branch}.");
        return $super->attemptAndPush(function() use ($super, $website_repo) {
            // Update the maintainer list.
            passthru('php '.
                $website_repo->path.'/site-tools/update-repo.php '.
                $super->path.' '.
                $super->branch);

            $message = "Update maintainer list.";
            return $super->commitAll($message);
        });
    }
}
