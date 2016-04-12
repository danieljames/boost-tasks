<?php

class Migrations {
    static $versions = array(
    );

    static function migrate() {
        $version = R::findOne('version');
        if (!$version) {
            $version = R::dispense('version');
            $version->version = 0;
            R::store($version);
        }

        $num_versions = count(self::$versions);
        if ($version->version < $num_versions) {
            call_user_func(self::$versions[$version->version]);
            ++$version->version;
            R::store($version);
        }
    }
}
