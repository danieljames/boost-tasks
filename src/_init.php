<?php

// Set up autoloading.

require __DIR__.'/../vendor/autoload.php';

// Set timezone to UTC, php sometimes complains if timezone isn't set, and
// it saves me from having to think about the server's timezone.

date_default_timezone_set('UTC');

// Initialise global state.

EvilGlobals::init();

// Die on all errors.

function myErrorHandler($errno, $errstr, $errfile, $errline)
{
    Log::error("{$errfile}:{$errline}: {$errstr}");
    exit(1);
}

set_error_handler('myErrorHandler');