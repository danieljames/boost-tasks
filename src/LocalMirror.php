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
        $this->mirror_root = EvilGlobals::data_path('mirror');
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
        foreach (EvilGlobals::github_cache()->iterate('/orgs/boostorg/repos') as $repo) {
            $url = $repo->clone_url;
            $this->update($repo->clone_url, $dirty);
        }

        $this->queue->catchUp();
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
        $repos = $db->find(self::$mirror_table, 'dirty = ?', Array(true));

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
        Process::run("git fetch -p --quiet", $this->getPath($repo_entry));

        $repo_entry->dirty = false;
        $repo_entry->store();
    }

    function createMirror($repo_entry) {
        Process::run(
            "git clone --mirror --quiet {$repo_entry->url} {$this->getPath($repo_entry)}",
            $this->getPath($repo_entry), null, null, 240); // 240 = timeout

        $repo_entry->dirty = false;
        $repo_entry->store();
    }

    function outputRepos() {
        foreach(EvilGlobals::database()->findAll(self::$mirror_table) as $repo) {
            echo "{$repo->url} ", $repo->dirty ? '(needs update)' : '' ,"\n";
        }
    }

    private function getPath($repo_entry) {
        return $this->mirror_root.$repo_entry->path;
    }

    function exportRecursive($repo, $branch, $dst_dir) {
        $repo = '/'.trim($repo, '/');
        $dst_dir = ltrim($dst_dir, '/');

        if (!@mkdir($dst_dir)) {
            throw \RuntimeException("Unable to create export destination: '{$dst_dir}'.");
        }

        $this->exportRecursiveImpl($repo, $branch, $dst_dir);
    }

    private function exportRecursiveImpl($repo, $ref, $dst_dir) {
        $git_dir = "{$this->mirror_root}{$repo}";
        Process::run("git --git-dir='{$git_dir}' archive {$ref} | tar -x -C '${dst_dir}'");

        $child_repos = array();
        foreach(SuperProject::readSubmoduleConfig($dst_dir) as $name => $values) {
            if (empty($values['path'])) { throw \RuntimeException("Missing path."); }
            if (empty($values['url'])) { throw \RuntimeException("Missing URL."); }
            $child_repos[$values['path']] = self::resolveGithubUrl($values['url'], $repo);
        }

        foreach(SuperProject::currentHashes($git_dir, array_keys($child_repos)) as $path => $hash) {
            $this->exportRecursiveImpl($child_repos[$path], $hash, $path);
        }
    }

    // Unfortunately git URLs aren't actually URLs, so can't just use
    // a URL library.
    //
    // A close enough emulation of what git-submodule does.
    private static function resolveGithubUrl($url, $base) {
        if (strpos(':', $url) !== FALSE) {
            throw \RuntimeException("Remote URLs aren't supported.");
        } else if ($url[0] == '/') {
            // What git-submodule treats as an absolute path
            return '/'.trim($url, '/');
        } else {
            $result = $base;

            while (true) {
                if (substr($url, 0, 3) == '../') {
                    if ($result == '/') {
                        throw \RuntimeException("Unable to resolve relative URL.");
                    }
                    $result = dirname($result);
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
