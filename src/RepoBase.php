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
}
