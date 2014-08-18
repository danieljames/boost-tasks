<?php

require 'src/_init.php';

$console = new BoostUpdateApplication();
$r = $console->run();

if ($r) {
    exit($r);
}
else if (Log::$error) {
    exit(1);
}
