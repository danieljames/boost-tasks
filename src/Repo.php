<?php

/*
 * Copyright 2013-2015 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

class Repo {
    var $module;
    var $branch;
    var $path;

    function __construct($module, $branch, $path) {
        $this->module = $module;
        $this->branch = $branch;
        $this->path = $path;
    }

    function fetchRepo() {
        // Create the repos or update them as required.

        if (!is_dir($this->path)) {
            Log::info("Clone {$this->branch}");
            $this->cloneRepo();
        }
        else {
            Log::info("Update {$this->branch}");
            $this->updateRepo();
        }
    }

    function cloneRepo() {
        // TODO: Clean up if this fails.

        // Use a shallow clone so it doesn't take too long, and since this
        // will never use the history.
        Process::run(
            "git clone -q --depth 1 -b {$this->branch} ".
            "git@github.com:boostorg/{$this->module}.git {$this->path}");
        Process::run("git config user.email 'automated@calamity.org.uk'",
                $this->path);
        Process::run("git config user.name 'Automated Commit'",
                $this->path);
    }

    function updateRepo() {
        Process::run("git fetch -q", $this->path);
        Process::run("git reset -q --hard origin/{$this->branch}", $this->path);
        Process::run("git clean -d -f", $this->path);
    }
}
