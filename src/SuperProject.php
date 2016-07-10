<?php

/*
 * Copyright 2013-2015 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

use Guzzle\Http\Url;
use Nette\Object;

class SuperProject extends Repo {
    var $submodule_branch;

    static function updateBranches($branches = null, $all = false) {
        if (!$branches) { $branches = EvilGlobals::branch_repos(); }
        foreach ($branches as $x) {
            $super = new SuperProject($x);
            $super->checkedUpdateFromEvents($all);
        }
    }

    function __construct($settings) {
        parent::__construct('boost',
            $this->get($settings, 'superproject-branch'),
            $this->get($settings, 'path'));
        $this->submodule_branch = $this->get($settings, 'submodule-branch');
    }

    private function get($settings, $name) {
        if (empty($settings[$name])) {
            throw new RuntimeException("Missing super project setting: {$name}");
        }
        return $settings[$name];
    }

    function checkedUpdateFromEvents($all = false) {
        $self = $this; // Has to work on php 5.3
        $queue = new GitHubEventQueue($self->submodule_branch);
        $result = $this->attemptAndPush(function() use($self, $queue, $all) {
            if ($all) {
                Log::info('Refresh all submodules.');
                return $self->updateFromAll($queue);
            }
            else if (!$queue->continuedFromLastRun()) {
                Log::info('Full refresh of submodules because of gap in event queue.');
                return $self->updateFromAll($queue);
            }
            else {
                Log::info('Refresh submodules from event queue.');
                return $self->updateFromEventQueue($queue);
            }
        });

        if ($result) { $queue->catchUp(); }
        return true;
    }

    // TODO: Public so that it can be called in a closure in PHP 5.3
    public function updateFromAll($queue) {
        $submodules = new SuperProject_Submodules($this->path);

        // TODO: Because this fetches all branches, it requires several fetches
        // per repo. See if there's something more efficient.
        $updates = array();
        foreach($submodules->getSubmodules() as $submodule) {
            // TODO: github_name can be null.
            foreach (EvilGlobals::github_cache()->iterate(
                    "/repos/{$submodule->github_name}/branches") as $branch) {
                if ($branch->name === $this->submodule_branch) {
                    $updates[$submodule->boost_name] = $branch->commit->sha;
                    break;
                }
            }
        }

        // TODO: Fetch the event queue again, and go back to start.
        // Since this might have picked up changes after the end
        // of the event queue.
        //
        // Or alternatively, fetch the queue and rollback any changes
        // since the catch up point.

        return $this->updateHashes($submodules, $updates);
    }

    // TODO: Public so that it can be called in a closure in PHP 5.3
    public function updateFromEventQueue($queue) {
        $submodules = new SuperProject_Submodules($this->path);

        $updates = array();

        foreach ($queue->getEvents() as $event) {
            if ($event->branch == $this->submodule_branch) {
                $submodule = $submodules->findByGitHubName($event->repo);
                if ($submodule) {
                    $updates[$submodule->boost_name]
                            = json_decode($event->payload)->head;
                }
            }
        }

        return $this->updateHashes($submodules, $updates);
    }

    /**
     * Update the repo to use the given submodule hashes.
     *
     * @param Array $hashes
     * @return boolean True if a change was committed.
     */
    function updateHashes($submodules, $hashes) {
        $paths = Array();
        foreach($hashes as $boost_name => $hash) {
            $paths[] = $submodules->findByBoostName($boost_name)->path;
        }
        $old_hashes = $this->currentHashes($paths);

        $updates = array();
        $names = array();
        foreach($hashes as $boost_name => $hash) {
            $submodule = $submodules->findByBoostName($boost_name);
            if ($old_hashes[$submodule->path] != $hash) {
                $updates[$submodule->path] = $hash;
                $names[] = preg_replace('@^(libs|tools)/@', '', $boost_name);
            }
        }

        if (!$updates) return false;

        $text_updates = '';
        $message = 'Update ' . implode(', ', $names)." from {$this->submodule_branch}.";
        Log::info("Commit to {$this->branch}: {$message}");

        foreach ($updates as $path => $hash) {
            $text_updates .=
                    "160000 {$hash}\t{$path}\n";
        }

        $this->command_with_input('update-index --index-info', $text_updates);
        $this->command("commit -m '{$message}'");

        return true;
    }
}

/**
 * The super project's submodules.
 */
class SuperProject_Submodules extends Object {
    var $path;
    var $submodules;

    function __construct($path) {
        $this->path = $path;

        if (!is_dir($this->path)) {
            throw new \RuntimeException(
                    "No directory for repo at {$this->path}");
        }

        $this->submodules = array();
        foreach (RepoBase::readSubmoduleConfig($this->path) as $name => $details) {
            $this->submodules[$name] = new SuperProject_Submodule($name, $details);
        }
    }

    /**
     * Read $this->submodules from the .gitmodules file.
     */
    function getSubmodules() {
        return $this->submodules;
    }

    public function findByBoostName($boost_name) {
        $x = $this->getSubmodules();
        return $x[$boost_name];
    }

    public function findByGitHubName($github_name) {
        foreach($this->getSubmodules() as $module) {
            if ($github_name == $module->github_name)
                return $module;
        }

        return null;
    }
}

/**
 * A submodule.
 */
class SuperProject_Submodule extends Object {
    /** The relative path of the submodule from boost root. */
    var $path;

    /** The name of the submodule in the boost repo. */
    var $boost_name;

    /** Github's name for the submodule. */
    var $github_name;

    function __construct($name, $values) {
        $this->boost_name = $name;
        $this->path = $values['path'];

        $matches = null;
        if (preg_match('@^(?:\.\.|https?://github\.com/boostorg)/(\w+)\.git$@', $values['url'], $matches)) {
            $this->github_name = "boostorg/{$matches[1]}";
        }
    }
}
