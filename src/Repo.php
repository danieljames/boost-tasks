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

    function setupCleanCheckout() {
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
        // Use a shallow clone so it doesn't take too long, and since this
        // will never use the history.
        Process::run(
            "git clone -q --depth 1 -b {$this->branch} ".
            "git@github.com:boostorg/{$this->module}.git {$this->path}");
        $this->configureRepo();
    }

    function updateRepo() {
        Process::run("git fetch -q", $this->path);
        Process::run("git reset -q --hard origin/{$this->branch}", $this->path);
        Process::run("git clean -d -f", $this->path);
        $this->configureRepo();
    }

    function configureRepo() {
        Process::run("git config user.email 'automated@calamity.org.uk'",
                $this->path);
        Process::run("git config user.name 'Automated Commit'",
                $this->path);
    }

    function attemptAndPush($callback) {
        try {
            // Loop to retry if update fails
            for ($try = 0; $try < 2; ++$try) {
                $this->setupCleanCheckout();
                $result = call_user_func($callback);
                // Nothing to push, so a trivial success
                if (!$result) { return true; }
                if ($this->push()) { return true; }
            }

            Log::error("Failed to update too many times.");
            return false;
        }
        catch (\RuntimeException $e) {
            Log::error($e);
            return false;
        }
    }

    function pushRepo() {
        // TODO: Maybe I should parse the output from git push to check exactly
        // what succeeded/failed.

        $process = new \Symfony\Component\Process\Process(
            'git push -q --porcelain', $this->path);
        $status = $process->run();

        if ($status > 1) {
            throw new \RuntimeException("Push failed: {$process->getErrorOutput()}");
        }

        return $status == 0;
    }
}
