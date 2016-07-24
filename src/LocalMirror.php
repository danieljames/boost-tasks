<?php

/*
 * Copyright 2013-2014 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

use Nette\Object;

/** Maintains a local mirror of the boostorg repos. */
class LocalMirror extends Object {
    static $mirror_table = 'mirror';
    var $mirror_root;
    var $queue;

    function __construct() {
        $this->mirror_root = EvilGlobals::dataPath('mirror');
        $this->queue = new GitHubEventQueue('mirror');
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
        $start_id = $this->queue->lastId();
        $current_id = $this->queue->currentId();

        // Get set of updated repos.
        $repos = Array();
        foreach ($this->queue->getEvents($start_id, $current_id) as $event) {
            $repos[$event->repo] = true;
        }

        // Mark them all as dirty.
        foreach (array_keys($repos) as $repo) {
            $this->update("https://github.com/{$repo}.git", true);
            Log::info("Updated repo: {$repo}");
        }

        $this->queue->catchUp($current_id);
    }

    function refreshAll($dirty = true) {
        $current_id = $this->queue->currentId();

        foreach (EvilGlobals::githubCache()->iterate('/orgs/boostorg/repos') as $repo) {
            $url = $repo->clone_url;
            $this->update($repo->clone_url, $dirty);
        }

        $this->queue->catchUp($current_id);
    }

    function update($url, $dirty) {
        $db = EvilGlobals::database();
        $path = parse_url($url, PHP_URL_PATH);
        $entry = $db->findOne(self::$mirror_table, 'path = ?', array($path));
        if ($entry) {
            if (!$entry->dirty) { $entry->dirty = $dirty; }
        }
        else {
            $entry = $db->dispense(self::$mirror_table);
            $entry->path = $path;
            $entry->dirty = $dirty;
        }
        $entry->url = $url;
        $db->store($entry);
    }

    function fetchDirty() {
        $db = EvilGlobals::database();
        $repos = $db->getAll('SELECT id FROM `'.self::$mirror_table.'` WHERE dirty = ?', Array(true));

        foreach ($repos as $row) {
            $self = $this;
            $mirror_table = self::$mirror_table;
            $db->transaction(function() use($self, $mirror_table, $db, $row) {
                // Q: Error checks?
                $repo_entry = $db->load($mirror_table, $row['id']);
                $self->updateMirror($repo_entry->path, $repo_entry->url);
                $repo_entry->dirty = false;
                $repo_entry->store();
            });
        }
    }

    function updateMirror($path, $url) {
        $full_path = $this->mirror_root.$path;
        if (is_dir($full_path)) {
            Log::info("Fetch {$path}");
            $repo = new RepoBase($full_path);
            $repo->fetchWithPrune();
        }
        else {
            Log::info("Clone {$path}");
            Process::run(
                "git clone --mirror --quiet {$url} {$full_path}",
                $this->mirror_root, null, null, 240); // 240 = timeout
        }
    }

    function outputRepos() {
        foreach(EvilGlobals::database()->findAll(self::$mirror_table) as $repo) {
            echo "{$repo->url} ", $repo->dirty ? '(needs update)' : '' ,"\n";
        }
    }

    function exportRecursive($branch, $dst_dir) {
        $dst_dir = rtrim($dst_dir, '/');

        if (!@mkdir($dst_dir)) {
            throw \RuntimeException("Unable to create export destination: '{$dst_dir}'.");
        }

        $this->exportRecursiveImpl('boostorg/boost.git', $branch, $dst_dir);
    }

    private function exportRecursiveImpl($repo_path, $ref, $dst_dir) {
        $repo = new RepoBase("{$this->mirror_root}/{$repo_path}");
        $repo->command("archive {$ref} | tar -x -C '${dst_dir}'");

        if (is_file("{$dst_dir}/.gitmodules")) {
            $child_repos = array();
            foreach(RepoBase::readSubmoduleConfig($dst_dir) as $name => $values) {
                if (empty($values['path'])) { throw \RuntimeException("Missing path."); }
                if (empty($values['url'])) { throw \RuntimeException("Missing URL."); }
                $child_repos[$values['path']] = self::resolveGitUrl($values['url'], $repo_path);
            }

            foreach($repo->currentHashes(array_keys($child_repos)) as $path => $hash) {
                $this->exportRecursiveImpl($child_repos[$path], $hash, "{$dst_dir}/{$path}");
            }
        }
    }

    // Unfortunately git URLs aren't actually URLs, so can't just use
    // a URL library.
    //
    // A close enough emulation of what git-submodule does.
    static function resolveGitUrl($url, $base) {
        if (strpos($url, ':') !== FALSE) {
            throw new \RuntimeException("Remote URLs aren't supported.");
        } else if ($url[0] == '/') {
            // What git-submodule treats as an absolute path
            return '/'.trim($url, '/');
        } else {
            $result = rtrim($base, '/');

            while (true) {
                if (substr($url, 0, 3) == '../') {
                    if (!$result) {
                        throw new \RuntimeException("Unable to resolve relative URL.");
                    }
                    $result = dirname($result);
                    if ($result == '/' || $result == '.') { $result = ''; }
                    $url = substr($url, 3);
                } else if (substr($url, 0, 2) == './') {
                    $url = substr($url, 2);
                } else {
                    break;
                }
            }

            return "{$result}/{$url}";
        }
    }
}
