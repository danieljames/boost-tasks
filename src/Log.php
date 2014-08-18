<?php

/*
 * Copyright 2013 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

/**
 * Logging...
 *
 * @author Daniel James <daniel@calamity.org.uk>
 */
class Log {
    /** @staticvar \Monolog\Logger */
    static $log;
    
    /** @staticvar bool Was an error encountered? */
    static $error = false;

    static public function debug($message) {
        self::$log->addDebug($message);
    }

    static public function info($message) {
        self::$log->addInfo($message);
    }

    static public function notice($message) {
        self::$log->addNotice($message);
    }

    static public function warning($message) {
        self::$log->addWarning($message);
    }

    static public function error($message) {
        self::$log->addError($message);
        self::$error = true;
    }

    static public function critical($message) {
        self::$log->addCritical($message);
        self::$error = true;
    }

    static public function alert($message) {
        self::$log->addAlert($message);
        self::$error = true;
    }

    static public function emergency($message) {
        self::$log->addEmergency($message);
        self::$error = true;
    }
}
