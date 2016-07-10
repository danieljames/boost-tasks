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

    function init() {
        $this->command('init');

    }

    function command($command) {
        Process::run("git {$command}", $this->path);
    }

}
