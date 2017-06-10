<?php

/*
 * Copyright 2013-2015 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

use Nette\Object;

class SuperProject extends Repo {
    var $submodule_branch;

    static function updateBranches($branches = null, $all = false) {
        if (!$branches) { $branches = EvilGlobals::branchRepos(); }
        foreach ($branches as $x) {
            $super = new SuperProject($x);
            $super->checkedUpdateFromEvents($all);
        }
    }

    function __construct($settings) {
        parent::__construct(
            array_get($settings, 'module', 'boost'),
            $this->get($settings, 'superproject-branch'),
            $this->get($settings, 'path'),
            array_get($settings, 'remote_url'));
        $this->submodule_branch = $this->get($settings, 'submodule-branch');
    }

    private function get($settings, $name) {
        if (empty($settings[$name])) {
            throw new RuntimeException("Missing super project setting: {$name}");
        }
        return $settings[$name];
    }

    function checkedUpdateFromEvents($all = false) {
        $queue = new GitHubEventQueue($this->submodule_branch, 'PushEvent');
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
            $submodules = $self->getSubmodules();
            $self->updateAllSubmoduleHashes($submodules);
            // Include any events that have arrived since starting this update.
            $queue->downloadMoreEvents();
            $updated = $self->updateSubmoduleHashesFromEventQueue($queue, $submodules);
            foreach ($submodules as $submodule) {
                assert(!$submodule->updated_hash_value);
                $submodule->updated_hash_value = $submodule->pending_hash_value;
            }
            $updated |= $self->updateHashes($submodules, true);
            return $updated;
        });
    }

    private function attemptUpdateFromEventQueue($queue) {
        $self = $this; // Has to work on php 5.3
        return $this->attemptAndPush(function() use($self, $queue) {
            $submodules = $self->getSubmodules();
            return $self->updateSubmoduleHashesFromEventQueue($queue, $submodules);
        });
    }

    public function getSubmodules() {
        $submodules = array();
        $submodule_by_path = array();
        $paths = array();
        foreach (RepoBase::readSubmoduleConfig($this->path) as $name => $details) {
            $submodule = new SuperProject_Submodule($name, $details);
            if ($submodule->github_name) {
                $submodules[$submodule->github_name] = $submodule;
                $submodule_by_path[$submodule->path] = $submodule;
                $paths[] = $submodule->path;
            }
        }
        foreach ($this->currentHashes($paths) as $path => $hash) {
            $submodule_by_path[$path]->current_hash_value = $hash;
        }
        return $submodules;
    }

    // Note: Public so that it can be called in a closure in PHP 5.3
    public function updateAllSubmoduleHashes($submodules) {
        foreach($submodules as $submodule) {
            // Note: Alternative would be to use branch API to get more
            //       information.
            //       https://developer.github.com/v3/repos/branches/#get-branch
            $ref = EvilGlobals::githubCache()->getJson(
                "/repos/{$submodule->github_name}/git/refs/heads/{$this->submodule_branch}");
            if ($ref->object->sha != $submodule->current_hash_value) {
                $submodule->pending_hash_value = $ref->object->sha;
            }
        }
    }

    // Note: Public so that it can be called in a closure in PHP 5.3
    public function updateSubmoduleHashesFromEventQueue($queue, $submodules = null) {
        $updated = false;

        foreach ($queue->getEvents() as $event) {
            if ($event->branch == $this->submodule_branch) {
                if (array_key_exists($event->repo, $submodules)) {
                    $payload = json_decode($event->payload);
                    assert($payload);

                    $submodule = $submodules[$event->repo];

                    if ($submodule->current_hash_value == $payload->head) {
                        $submodule->ignored_events = array();
                        continue;
                    }

                    if ($submodule->current_hash_value != $payload->before) {
                        $submodule->ignored_events[] = $event;
                        continue;
                    }

                    $updated_hash_value = $payload->head;
                    if ($updated_hash_value == $submodule->pending_hash_value) {
                        $submodule->pending_hash_value = null;
                    }
                    if ($updated_hash_value != $submodule->current_hash_value) {
                        $submodule->updated_hash_value = $updated_hash_value;
                        if (!$this->updateHashes($submodules)) {
                            throw new RuntimeException("Error updating submodules in git repo");
                        }
                        assert(!$submodule->updated_hash_value && $submodule->current_hash_value == $updated_hash_value);
                        $updated = true;
                    }
                }
            }
        }

        foreach ($submodules as $submodule) {
            if ($submodule->ignored_events) {
                $events = count($submodule->ignored_events);
                $events .= ($events == 1) ? " PushEvent" : " PushEvents";
                Log::warning("Ignored {$events} for {$submodule->boost_name} as the hash does not the super project's current value");
            }
        }

        return $updated;
    }

    /**
     * Update the repo to use the given submodule hashes.
     *
     * @param Array $hashes
     * @param boolean $mark_mirror_dirty
     * @return boolean True if a change was committed.
     */
    function updateHashes($submodules, $mark_mirror_dirty = false) {
        $updates = array();
        $names = array();
        foreach($submodules as $submodule) {
            if (!$submodule->updated_hash_value) { continue; }

            if ($submodule->current_hash_value != $submodule->updated_hash_value) {
                $updates[$submodule->path] = $submodule->updated_hash_value;
                $names[] = preg_replace('@^(libs|tools)/@', '', $submodule->boost_name);

                $submodule->current_hash_value = $submodule->updated_hash_value;
                $submodule->updated_hash_value = null;
            }
        }

        if (!$updates) return false;

        $text_updates = '';
        $message = $this->getUpdateMessage($names);
        Log::info("Commit to {$this->branch}: ".strtok($message, "\n"));

        foreach ($updates as $path => $hash) {
            $text_updates .=
                    "160000 {$hash}\t{$path}\n";
        }

        $this->commandWithInput('update-index --index-info', $text_updates);
        $this->commandWithInput("commit -F -", $message);

        // A bit of hack, tell the mirror to fetch any updated submodules.
        // The main concern is that sometimes the event queue misses a
        // push event, and the update is caught by 'updateAllSubmoduleHashes'.
        if ($mark_mirror_dirty) {
            $mirror = new LocalMirror;
            foreach($submodules as $submodule) {
                if (array_key_exists($submodule->path, $updates)) {
                    // TODO: Github URLs aren't a good identifier, as the same repo
                    //       can have multiple URLs.
                    $url = "https://github.com/{$submodule->github_name}.git";
                    Log::info("Schedule mirror fetch for: {$url}");
                    $mirror->update($url, true);
                }
            }
        }

        return true;
    }

    function getUpdateMessage($names) {
        natcasesort($names);

        $update = 'Update ' .implode(', ', $names);
        $message = "{$update} from {$this->submodule_branch}";

        // Git recommends that the short message is 50 character or less,
        // which seems unreasonably short to me, but there you go.
        if (strlen($message) > 50) {
            $message = "Update ".count($names).
                (count($names) == 1 ? " submodule" : " submodules").
                " from {$this->submodule_branch}";
            $message .= "\n\n";
            $message .= wordwrap($update, 72);
            $message .= ".\n";
        }

        return $message;
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

    /** Hash currently in the superproject repo */
    var $current_hash_value;

    /** The hash value currently in the submodule repo. */
    var $updated_hash_value;

    /** Will be updated to this hash value eventually. */
    var $pending_hash_value;

    /** Push events that have been ignored */
    var $ignored_events = array();

    function __construct($name, $values) {
        $this->boost_name = $name;
        $this->path = $values['path'];

        $matches = null;
        // TODO: Set github name based on super project name?
        if (preg_match('@^(?:\.\.|https?://github\.com/boostorg)/(\w+)(\.git)?$@', $values['url'], $matches)) {
            $this->github_name = "boostorg/{$matches[1]}";
        }
    }
}
