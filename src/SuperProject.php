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
        $queue = new GitHubEventQueue($this->submodule_branch);
        if ($all) {
            Log::info('Refresh all submodules.');
            $result = $this->attemptUpdateFromAll($queue);
        } else if (!$queue->continuedFromLastRun()) {
            Log::info('Full refresh of submodules because of gap in event queue.');
            $result = $this->attemptUpdateFromAll($queue);
        } else {
            Log::info('Refresh submodules from event queue.');
            $result = $this->attemptUpdateFromEventQueue($queue);
        };

        if ($result) { $queue->catchUp(); }
        return true;
    }

    private function attemptUpdateFromAll($queue) {
        $self = $this; // Has to work on php 5.3
        return $this->attemptAndPush(function() use($self, $queue) {
            return $self->updateFromAll($queue);
        });
    }

    private function attemptUpdateFromEventQueue($queue) {
        $self = $this; // Has to work on php 5.3
        $result = $this->attemptAndPush(function() use($self, $queue) {
            return $self->updateFromEventQueue($queue);
        });
    }

    // Note: Public so that it can be called in a closure in PHP 5.3
    public function updateFromAll($queue) {
        $submodules = array();
        foreach (RepoBase::readSubmoduleConfig($this->path) as $name => $details) {
            $submodule = new SuperProject_Submodule($name, $details);
            if ($submodule->github_name) {
                $submodules[] = $submodule;
            }
        }

        // TODO: Because this fetches all branches, it requires several fetches
        // per repo. See if there's something more efficient.
        foreach($submodules as $submodule) {
            foreach (EvilGlobals::github_cache()->iterate(
                    "/repos/{$submodule->github_name}/branches") as $branch) {
                if ($branch->name === $this->submodule_branch) {
                    $submodule->updated_hash_value = $branch->commit->sha;
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

        return $this->updateHashes($submodules);
    }

    // Note: Public so that it can be called in a closure in PHP 5.3
    public function updateFromEventQueue($queue) {
        $submodules = array();
        foreach (RepoBase::readSubmoduleConfig($this->path) as $name => $details) {
            $submodule = new SuperProject_Submodule($name, $details);
            if ($submodule->github_name) {
                $submodules[$submodule->github_name] = $submodule;
            }
        }

        foreach ($queue->getEvents() as $event) {
            if ($event->branch == $this->submodule_branch) {
                $submodule = $submodules[$event->repo];
                if ($submodule) {
                    $submodule->updated_hash_value
                            = json_decode($event->payload)->head;
                }
            }
        }

        return $this->updateHashes($submodules);
    }

    /**
     * Update the repo to use the given submodule hashes.
     *
     * @param Array $hashes
     * @return boolean True if a change was committed.
     */
    function updateHashes($submodules) {
        $paths = Array();
        foreach($submodules as $submodule) {
            if ($submodule->updated_hash_value) {
                $paths[] = $submodule->path;
            }
        }
        $old_hashes = $this->currentHashes($paths);

        $updates = array();
        $names = array();
        foreach($submodules as $submodule) {
            if (!$submodule->updated_hash_value) { continue; }

            if ($old_hashes[$submodule->path] != $submodule->updated_hash_value) {
                $updates[$submodule->path] = $hash;
                $names[] = preg_replace('@^(libs|tools)/@', '', $submodule->boost_name);
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
 * A submodule.
 */
class SuperProject_Submodule extends Object {
    /** The relative path of the submodule from boost root. */
    var $path;

    /** The name of the submodule in the boost repo. */
    var $boost_name;

    /** Github's name for the submodule. */
    var $github_name;

    /** The hash value currently in the repo. */
    var $updated_hash_value;

    function __construct($name, $values) {
        $this->boost_name = $name;
        $this->path = $values['path'];

        $matches = null;
        if (preg_match('@^(?:\.\.|https?://github\.com/boostorg)/(\w+)\.git$@', $values['url'], $matches)) {
            $this->github_name = "boostorg/{$matches[1]}";
        }
    }
}
