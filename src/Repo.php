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
    var $enable_push;

    function __construct($module, $branch, $path) {
        $this->module = $module;
        $this->branch = $branch;
        $this->path = $path;
        $this->enable_push = EvilGlobals::$settings['push-to-repo'];
    }

    function getModuleBranchName() {
        return "{$this->module}, branch {$this->branch}";
    }

    function setupCleanCheckout() {
        // Create the repos or update them as required.

        if (!is_dir($this->path)) {
            Log::info("Clone {$this->getModuleBranchName()}.");
            $this->cloneRepo();
        }
        else {
            Log::info("Fetch {$this->getModuleBranchName()}.");
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

    function commitAll($message) {
        Process::run('git add -u .', $this->path);
        $process = new \Symfony\Component\Process\Process(
            'git diff-index HEAD --quiet', $this->path);
        $status = $process->run();

        if ($status == 0) {
            Log::info("No changes to {$this->getModuleBranchName()}.");
            return false;
        } else if ($status == 1) {
            Log::info("Committing changes to {$this->getModuleBranchName()}.");
            Process::run('git commit -m "'.$message.'"', $this->path);
            return true;
        } else {
            throw new RuntimeException("Unexpected status from 'git diff-index'.");
        }
    }

    function attemptAndPush($callback) {
        try {
            // Loop to retry if update fails
            for ($try = 0; $try < 2; ++$try) {
                $this->setupCleanCheckout();
                $result = call_user_func($callback);
                // Nothing to push, so a trivial success
                if (!$result) { return true; }
                if ($this->pushRepo()) { return true; }
            }

            Log::error("Failed to push to {$this->getModuleBranchName()}.");
            return false;
        }
        catch (\RuntimeException $e) {
            Log::error("{$this->getModuleBranchName()}: $e");
            return false;
        }
    }

    function pushRepo() {
        if ($this->enable_push) {
            // TODO: Maybe I should parse the output from git push to check exactly
            // what succeeded/failed.

            $process = new \Symfony\Component\Process\Process(
                'git push -q --porcelain', $this->path);
            $status = $process->run();

            if ($status > 1) {
                throw new \RuntimeException("Push failed: {$process->getErrorOutput()}");
            }

            return $status == 0;
        } else {
            echo "{$this->path} processed, not configured to push to repo.\n";
            return true;
        }
    }
}
