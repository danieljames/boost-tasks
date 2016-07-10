<?php

use Tester\Assert;
use BoostTasks\DbSchema;

require_once(__DIR__.'/bootstrap.php');

class DbSchemaTest extends Tester\TestCase
{
    function testLoadFromSql() {
        $schema = DbSchema::loadFromSql('
            CREATE TABLE a1(
                x INT, -- );
                y INT
            );
            /* CREATE TABLE commented_out( x INT ) */;
            CREATE TABLE a2(
                /* x INT,
                y INT, */
                z INT PRIMARY KEY
            );
            CREATE UNIQUE INDEX a1_x ON a1(x);
            CREATE INDEX a1_yx ON a1(y, x);
            /* Just some junk in an unclosed comment.
        ');

        Assert::same(array('a1','a2'), array_keys($schema->tables));

        Assert::same(2, count($schema->tables['a1']->columns));
        Assert::same('x', $schema->tables['a1']->columns[0]->name);
        Assert::same('y', $schema->tables['a1']->columns[1]->name);
        Assert::same(2, count($schema->tables['a1']->indexes));

        Assert::same('a1_x', $schema->tables['a1']->indexes[0]->name);
        Assert::true($schema->tables['a1']->indexes[0]->unique);
        Assert::same(1, count($schema->tables['a1']->indexes[0]->columns));
        Assert::same('x', $schema->tables['a1']->indexes[0]->columns[0]->name);

        Assert::same('a1_yx', $schema->tables['a1']->indexes[1]->name);
        Assert::false($schema->tables['a1']->indexes[1]->unique);
        Assert::same(2, count($schema->tables['a1']->indexes[1]->columns));
        Assert::same('y', $schema->tables['a1']->indexes[1]->columns[0]->name);
        Assert::same('x', $schema->tables['a1']->indexes[1]->columns[1]->name);

        Assert::same(1, count($schema->tables['a2']->columns));
        Assert::same('z', $schema->tables['a2']->columns[0]->name);
        Assert::false($schema->tables['a2']->columns[0]->notnull);
        // In sqlite integer primary keys are automatically auto_increment.
        Assert::true($schema->tables['a2']->columns[0]->auto_increment);
        Assert::null($schema->tables['a2']->columns[0]->default);
        Assert::same(1, count($schema->tables['a2']->indexes));

        Assert::true($schema->tables['a2']->indexes[0]->unique);
        Assert::same(1, count($schema->tables['a2']->indexes[0]->columns));
        Assert::same('z', $schema->tables['a2']->indexes[0]->columns[0]->name);
    }

    function testLoadDefaultsFromSql() {
        $schema = DbSchema::loadFromSql("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                null1 TEXT DEFAULT NULL,
                null2 INT DEFAULT NULL,
                value1 TEXT DEFAULT 'something',
                value2 TEXT DEFAULT \"something\",
                value3 TEXT DEFAULT `something`,
                value4 INT DEFAULT 0,
                value5 INT DEFAULT +100,
                value6 INT DEFAULT -100,
                value7 TEXT DEFAULT 'null',
                value8 TEXT DEFAULT null,
                date1 DATETIME DEFAULT CURRENT_TIMESTAMP,
                date2 DATETIME DEFAULT '10 Jul 2016'
            )
        ");

        $columns = array();
        foreach($schema->tables['test']->columns as $column) {
            $columns[$column->name] = $column;
        }

        Assert::null($columns['id']->default);
        Assert::null($columns['null1']->default);
        Assert::null($columns['null2']->default);
        Assert::same("'something'", $columns['value1']->default);
        Assert::same('"something"', $columns['value2']->default);
        Assert::same('`something`', $columns['value3']->default);
        Assert::same('0', $columns['value4']->default);
        Assert::same('100', $columns['value5']->default);
        Assert::same('-100', $columns['value6']->default);
        Assert::same("'null'", $columns['value7']->default);
        Assert::null($columns['value8']->default);
        Assert::same("CURRENT_TIMESTAMP", $columns['date1']->default);
        Assert::same("'10 Jul 2016'", $columns['date2']->default);
    }
}

$test = new DbSchemaTest();
$test->run();
