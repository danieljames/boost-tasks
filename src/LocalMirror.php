<?php

/*
 * Copyright 2013-2014 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

/** Maintains a local mirror of the boostorg repos. */
class LocalMirror {
    static $mirror_table = 'mirror';
    var $mirror_root;
    var $queue;

    function __construct() {
        $this->mirror_root = EvilGlobals::$mirror_root;
        $this->queue = new EventQueue('mirror');
    }

    function refresh() {
        if (!$this->queue->continuedFromLastRun()) {
            Log::info('Full referesh of mirrors because of gap in event queue.');
            $this->refreshAll(true);
        }
        else {
            $this->refreshFromQueue();
        }
    }

    private function refreshFromQueue() {
        // Get set of updated repos.
        $repos = Array();
        foreach ($this->queue->getEvents() as $event) {
            $repos[$event->repo] = true;
        }

        // Mark them all as dirty.
        foreach (array_keys($repos) as $repo) {
            $this->update("https://github.com/{$repo}.git", true);
            Log::info("Updated repo: {$repo}");
        }

        $this->queue->catchUp();
    }

    function refreshAll($dirty = true) {
        foreach (EvilGlobals::$github_cache->iterate('/orgs/boostorg/repos') as $repo) {
            $url = $repo->clone_url;
            $this->update($repo->clone_url, $dirty);
        }

        $this->queue->catchUp();
    }

    function update($url, $dirty) {
        $path = parse_url($url, PHP_URL_PATH);
        $entry = R::findOne(self::$mirror_table, 'path = ?', array($path));
        if ($entry) {
            if (!$entry->dirty) { $entry->dirty = $dirty; }
        }
        else {
            $entry = R::dispense(self::$mirror_table);
            $entry->path = $path;
            $entry->dirty = $dirty;
        }
        $entry->url = $url;
        R::store($entry);
    }

    function fetchDirty() {
        $repos = R::find(self::$mirror_table, 'dirty = ?', Array(true));

        foreach ($repos as $repo_entry) {
            if (is_dir($this->getPath($repo_entry))) {
                Log::info("Fetch {$repo_entry->path}");
                $this->fetchMirror($repo_entry);
            }
            else {
                Log::info("Clone {$repo_entry->path}");
                $this->createMirror($repo_entry);
            }
        }
    }

    function fetchMirror($repo_entry) {
        Process::run("git fetch --quiet", $this->getPath($repo_entry));

        $repo_entry->dirty = false;
        R::store($repo_entry);
    }

    function createMirror($repo_entry) {
        Process::run(
            "git clone --mirror --quiet {$repo_entry->url} {$this->getPath($repo_entry)}",
            $this->getPath($repo_entry), null, null, 240); // 240 = timeout

        $repo_entry->dirty = false;
        R::store($repo_entry);
    }

    function outputRepos() {
        foreach(R::findAll(self::$mirror_table) as $repo) {
            echo "{$repo->url} ", $repo->dirty ? '(needs update)' : '' ,"\n";
        }
    }

    private function getPath($repo_entry) {
        return $this->mirror_root.$repo_entry->path;
    }
}
