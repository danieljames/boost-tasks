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
    var $submodules;
    var $enable_push;

    function __construct($settings) {
        parent::__construct('boost',
            $this->get($settings, 'superproject-branch'),
            $this->get($settings, 'path'));
        $this->submodule_branch = $this->get($settings, 'submodule-branch');
        $this->submodules = new SuperProject_Submodules($this->path);
        $this->enable_push = EvilGlobals::$settings['push-to-repo'];
    }

    private function get($settings, $name) {
        if (empty($settings[$name])) {
            throw new RuntimeException("Missing super project setting: {$name}");
        }
        return $settings[$name];
    }

    function checkedUpdateFromEvents() {
        try {
            // Loop to retry if update fails
            for ($try = 0; $try < 2; ++$try) {
                if ($this->update()) {
                    return true;
                }
            }

            Log::error("Branch {$this->branch}: ".
                "Failed to update too many times.");
            return false;
        }
        catch (\RuntimeException $e) {
            Log::error("Branch {$this->branch}: {$e}");
            return false;
        }
    }

    /**
     * Use the GitHub events to update the super-project.
     *
     * @return bool Did the update succeed?
     * @throws \RuntimeException
     */
    function update() {
        $this->fetchRepo();
        $this->submodules->readSubmodules();

        $queue = new GitHubEventQueue($this->submodule_branch);
        if (!$queue->continuedFromLastRun()) {
            Log::info('Full referesh of submodules because of gap in event queue.');
            $updates = $this->getUpdatesFromAll($queue);
        }
        else {
            Log::info('Referesh submodules from event queue.');
            $updates = $this->getUpdatedFromEventQueue($queue);
        }

        if ($this->updateHashes($updates)) {
            if (!$this->push()) {
                Log::notice("Branch {$this->branch}: Push failed.");
                return false;
            }
        }

        $queue->catchUp();
        return true;
    }

    private function getUpdatesFromAll($queue) {
        $updates = array();
        foreach($this->submodules->getSubmodules() as $submodule) {
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

    private function getUpdatedFromEventQueue($queue) {
        $updates = array();

        foreach ($queue->getEvents() as $event) {
            if ($event->branch == $this->submodule_branch) {
                $submodule = $this->findByGitHubName($event->repo);
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
    function updateHashes($hashes) {
        $old_hashes = $this->submodules->currentHashes();

        $updates = array();
        $names = array();
        foreach($hashes as $boost_name => $hash) {
            if ($old_hashes[$boost_name] != $hash) {
                $updates[$this->submodules->findByBoostName($boost_name)->path]
                        = $hash;
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

    public function findByGitHubName($github_name) {
        return $this->submodules->findByGitHubName($github_name);
    }

    /**
     * Push the repo.
     *
     * @return bool True if the push succeeded.
     */

    public function push() {
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
        }
        else {
            echo "{$this->path} processed, not configured to push to repo.\n";
            return true;
        }
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
    }

    /**
     * Read $this->submodules from the .gitmodules file.
     *
     * @throws \RuntimeException
     */
    function getSubmodules() {
        if (!is_dir($this->path)) {
            throw new \RuntimeException(
                    "No directory for repo at {$this->path}");
        }

        if (!$this->submodules) $this->readSubmodules();
        return $this->submodules;
    }

    function readSubmodules() {
        $submodule_config = array();
        foreach(Process::read_lines("git config -f .gitmodules -l", $this->path)
                as $line)
        {
            $matches = null;
            if (!preg_match(
                '@submodule\.(?<submodule>[\w/]+)\.(?<name>\w+)=(?<value>.*)@',
                $line, $matches))
            {
                throw new \RuntimeException(
                    "Unrecognised submodule setting: {$line}");
            }

            $submodule_config[$matches['submodule']][$matches['name']]
                    = $matches['value'];
        }

        $this->submodules = array();
        foreach ($submodule_config as $name => $details) {
            $this->submodules[$name] = new SuperProject_Submodule($name, $details);
        }

        return $this->submodules;
    }

    /**
     * Get the current hash values of the submodules.
     *
     * @return Array
     * @throws \RuntimeException
     */
    function currentHashes() {
        $path_map = Array();
        foreach($this->getSubmodules() as $submodule) {
            $path_map[$submodule->path] = $submodule;
        }

        $matches = null;
        $hashes = Array();
        foreach (Process::read_lines(
            'git ls-tree HEAD '. implode(' ', array_keys($path_map)),
            $this->path) as $line)
        {
            if (preg_match(
                    "@160000 commit (?<hash>[a-zA-Z0-9]{40})\t(?<path>.*)@",
                    $line, $matches))
            {
                if (!isset($path_map[$matches['path']]))
                    throw new \RuntimeException(
                    "Unexpected path: {$path_map[$matches['path']]}");

                $submodule = $path_map[$matches['path']];
                $hashes[$submodule->boost_name] = $matches['hash'];
            }
            else {
                throw new \RuntimeException(
                    "Unrecognised submodule entry:\n{$line}");
            }
        }

        return $hashes;
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
