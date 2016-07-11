<?php

use Nette\Object;

/*
 * Copyright 2016 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

class RepoBase extends Object {
    var $path;

    function __construct($path) {
        assert(is_dir($path));

        $this->path = $path;
    }

    function process($command) {
        return new \Symfony\Component\Process\Process("git {$command}", $this->path);
    }

    function command($command) {
        return Process::run("git {$command}", $this->path);
    }

    function command_with_input($command, $input) {
        return Process::run("git {$command}", $this->path, null, $input);
    }

    function read_lines($command) {
        return Process::read_lines("git {$command}", $this->path);
    }

    function fetchWithPrune($remote = 'origin') {
        try {
            $this->command("fetch -p --quiet {$remote}");
        }
        catch (\RuntimeException $e) {
            // Workaround for a bug in old versions of git.
            //
            // For details see:
            //
            // https://stackoverflow.com/a/21072934/2434
            // https://github.com/git/git/commit/10a6cc8890ec1e5459c05ddeb28a671acdc37d60
            if (preg_match(
                '@some local refs could not be updated.*git remote prune@is',
                $e->getMessage()))
            {
                Log::warning("git fetch failed, trying to fix.");
                // TODO: Log the output from this?
                $this->command("remote prune {$remote}");
                $this->command("fetch -p --quiet {$remote}");
            }
            else {
                throw($e);
            }
        }
    }

    // TODO: Duplicates BoostSuperProject::get_modules in the website, which also
    // supports reading from a bare repo.
    static function readSubmoduleConfig($repo_path) {
        $submodule_config = array();
        // Note: This isn't always an actual repo, just a path containing
        //       a .gitmodules file.
        $repo = new RepoBase($repo_path);
        foreach($repo->read_lines("config -f .gitmodules -l") as $line)
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
    function currentHashes($paths, $ref = 'HEAD') {
        $hashes = Array();
        if (!$paths) { return $hashes; }

        $matches = null;
        foreach ($this->read_lines("ls-tree {$ref} ". implode(' ', $paths))
            as $line)
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
