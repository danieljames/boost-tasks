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
}

$test = new DbSchemaTest();
$test->run();
