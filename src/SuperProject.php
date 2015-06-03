<?php

/*
 * Copyright 2013-2015 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

use Guzzle\Http\Url;

class SuperProject extends Repo {
    var $submodule_branch;
    var $enable_push;

    function __construct($settings) {
        parent::__construct('boost',
            $this->get($settings, 'superproject-branch'),
            $this->get($settings, 'path'));
        $this->submodule_branch = $this->get($settings, 'submodule-branch');
        $this->enable_push = EvilGlobals::$settings['push-to-repo'];
    }

    private function get($settings, $name) {
        if (empty($settings[$name])) {
            throw new RuntimeException("Missing super project setting: {$name}");
        }
        return $settings[$name];
    }

    function checkedUpdateFromEvents() {
        $self = $this; // Has to work on php 5.3
        $queue = new GitHubEventQueue($self->submodule_branch);
        $result = $this->attemptAndPush(function() use($self, $queue) {
            $submodules = new SuperProject_Submodules($self->path);
            $submodules->readSubmodules();

            if (!$queue->continuedFromLastRun()) {
                Log::info('Full referesh of submodules because of gap in event queue.');
                $updates = $self->getUpdatesFromAll($submodules, $queue);
            }
            else {
                Log::info('Referesh submodules from event queue.');
                $updates = $self->getUpdatedFromEventQueue($submodules, $queue);
            }

            return $self->updateHashes($submodules, $updates);
        });

        if ($result) { $queue->catchUp(); }
        return true;
    }

    private function getUpdatesFromAll($submodules, $queue) {
        $updates = array();
        foreach($submodules->getSubmodules() as $submodule) {
            foreach (EvilGlobals::$github_cache->iterate(
                    "/repos/{$submodule->github_name}/branches") as $branch) {
                if ($branch->name === $this->submodule_branch) {
                    $updates[$submodule->boost_name] = $branch->commit->sha;
                }
            }
        }

        // TODO: Fetch the event queue again, and go back to start.
        // Since this might have picked up changes after the end
        // of the event queue.
        //
        // Or alternatively, fetch the queue and rollback any changes
        // since the catch up point.

        return $updates;
    }

    private function getUpdatedFromEventQueue($submodules, $queue) {
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

        return $updates;
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
        $old_hashes = SuperProject::currentHashes($this->path, $paths);

        $updates = array();
        $names = array();
        foreach($hashes as $boost_name => $hash) {
            $submodule = $submodules->findByBoostName($boost_name);
            if ($old_hashes[$submodule->path] != $hash) {
                $updates[$submodule->path] = $hash;
                $names[] = $boost_name;
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

        Process::run('git update-index --index-info', $this->path,
                null, $text_updates);
        Process::run("git commit -m '{$message}'", $this->path);

        return true;
    }

    /**
     * Push the repo.
     *
     * @return bool True if the push succeeded.
     */

    public function push() {
        if ($this->enable_push) {
            return $this->pushRepo();
        }
        else {
            echo "{$this->path} processed, not configured to push to repo.\n";
            return true;
        }
    }

    // TODO: Awkward location for this function, but php is pretty bad at organising
    // free functions. Maybe move somewhere better?
    // TODO: Duplicates BoostSuperProject::get_modules in the website, which also
    // supports reading from a bare repo.
    static function readSubmoduleConfig($repo_path) {
        $submodule_config = array();
        foreach(Process::read_lines("git config -f .gitmodules -l", $this->path)
                as $line)
        {
            $matches = null;
            if (!preg_match(
                '@submodule\.(?<submodule>[\w/]+)\.(?<name>\w+)=(?<value>.*)@',
                $line, $matches))
            {
                throw new \LogicException(
                    "Unable to parse submodule setting: {$line}");
            }

            $submodule_config[$matches['submodule']][$matches['name']]
                    = $matches['value'];
        }

        return $submodule_config;
    }

    /**
     * Get the current hash values of the given paths.
     *
     * @return Array
     */
    static function currentHashes($repo_path, $paths) {
        $matches = null;
        $hashes = Array();
        foreach (Process::read_lines(
            'git ls-tree HEAD '. implode(' ', $paths),
            $repo_path) as $line)
        {
            if (preg_match(
                    "@160000 commit (?<hash>[a-zA-Z0-9]{40})\t(?<path>.*)@",
                    $line, $matches))
            {
                if (!in_array($matches['path'], $paths)) {
                    throw new \LogicException("Unexpected path: {$matches['path']}");
                }

                $hashes[$matches['path']] = $matches['hash'];
            }
            else {
                throw new \LogicException(
                    "Unable to parse submodule entry:\n{$line}");
            }
        }

        return $hashes;
    }
}

/**
 * The super project's submodules.
 */
class SuperProject_Submodules {
    var $path;
    var $submodules;

    function __construct($path) {
        $this->path = $path;
        $this->readSubmodules();
    }

    /**
     * Read $this->submodules from the .gitmodules file.
     */
    function getSubmodules() {
        return $this->submodules;
    }

    function readSubmodules() {
        if (!is_dir($this->path)) {
            throw new \RuntimeException(
                    "No directory for repo at {$this->path}");
        }

        $this->submodules = array();
        foreach (SuperProject::readSubmoduleConfig($this->path) as $name => $details) {
            $this->submodules[$name] = new SuperProject_Submodule($name, $details);
        }

        return $this->submodules;
    }

    public function findByBoostName($github_name) {
        $x = $this->getSubmodules();
        return $x[$github_name];
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
class SuperProject_Submodule {
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
        if (preg_match('@../(\w+)\.git@', $values['url'], $matches)) {
            $this->github_name = "boostorg/{$matches[1]}";
        }
    }
}
