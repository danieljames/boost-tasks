<?php

/* 
 * Copyright 2013 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

require_once(__DIR__.'/vendor/autoload.php');

try {
    $dst = EvilGlobals::$data_root.'/upload';

    if (is_dir($dst)) {
        Process::run("rm -r {$dst}");
    }

    mkdir($dst);

    Process::run("git archive master | tar -x -C {$dst}", __DIR__);
    Process::run("rm -r {$dst}/nbproject");
    Process::run("rsync -az {$dst}/ dnljms@boost.org:boost-update/");
    echo "rsync -az {$dst}/ dnljms@boost.org:boost-update/";
}
catch (\RuntimeException $e) {
    Log::error("Runtime exception: {$e}");
    exit(1);
}

// Return an error code is an error was logged.
if (Log::$error) {
    exit(1);
}
