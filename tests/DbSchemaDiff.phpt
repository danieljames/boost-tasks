<?php

use Tester\Assert;
use BoostTasks\DbSchema;
use BoostTasks\DbSchemaDiff;

require_once(__DIR__.'/bootstrap.php');

class DbSchemaDiffTest extends Tester\TestCase
{
    function setup() {
        $this->schema_empty = DbSchema::loadFromSql('');
        $this->schema_a1 = DbSchema::loadFromSql('CREATE TABLE a1(x INT, y INT);');
        $this->schema_a1_alt = DbSchema::loadFromSql('CREATE TABLE a1(y INT, x INT);');
        $this->schema_a1_cz = DbSchema::loadFromSql('CREATE TABLE a1(x INT, y INT, z INT);');
        $this->schema_a2 = DbSchema::loadFromSql('CREATE TABLE a2(x INT);');
        $this->schema_a1a2 = DbSchema::loadFromSql('
            CREATE TABLE a1(x INT, y INT);
            CREATE TABLE a2(x INT);
        ');
        $this->schema_a2a1 = DbSchema::loadFromSql('
            CREATE TABLE a2(x INT);
            CREATE TABLE a1(y INT, x INT);
        ');

    }

    function testNoRealChange() {
        Assert::false(DbSchemaDiff::compare($this->schema_empty, $this->schema_empty)->has_changes());
        Assert::false(DbSchemaDiff::compare($this->schema_a1, $this->schema_a1_alt)->has_changes());
        Assert::false(DbSchemaDiff::compare($this->schema_a1_alt, $this->schema_a1)->has_changes());
        Assert::false(DbSchemaDiff::compare($this->schema_a1a2, $this->schema_a2a1)->has_changes());
        Assert::false(DbSchemaDiff::compare($this->schema_a2a1, $this->schema_a1a2)->has_changes());
    }

    function testDeletedTable() {
        $diff = DbSchemaDiff::compare($this->schema_a1, $this->schema_empty);
        Assert::true($diff->has_changes());
        Assert::same(array('a1'), $diff->deleted_tables);
        Assert::same(array(), $diff->new_tables);
        Assert::same(array(), array_keys($diff->changed_tables));

        $diff = DbSchemaDiff::compare($this->schema_a2, $this->schema_empty);
        Assert::true($diff->has_changes());
        Assert::same(array('a2'), $diff->deleted_tables);
        Assert::same(array(), $diff->new_tables);
        Assert::same(array(), array_keys($diff->changed_tables));

        $diff = DbSchemaDiff::compare($this->schema_a1a2, $this->schema_a1);
        Assert::true($diff->has_changes());
        Assert::same(array('a2'), $diff->deleted_tables);
        Assert::same(array(), $diff->new_tables);
        Assert::same(array(), array_keys($diff->changed_tables));

        $diff = DbSchemaDiff::compare($this->schema_a2a1, $this->schema_empty);
        Assert::true($diff->has_changes());
        Assert::same(array('a2', 'a1'), $diff->deleted_tables);
        Assert::same(array(), $diff->new_tables);
        Assert::same(array(), array_keys($diff->changed_tables));

    }

    function testNewTable() {
        $diff = DbSchemaDiff::compare($this->schema_empty, $this->schema_a1);
        Assert::true($diff->has_changes());
        Assert::same(array(), $diff->deleted_tables);
        Assert::same(array('a1'), $diff->new_tables);
        Assert::same(array(), array_keys($diff->changed_tables));

        $diff = DbSchemaDiff::compare($this->schema_empty, $this->schema_a2a1);
        Assert::true($diff->has_changes());
        Assert::same(array(), $diff->deleted_tables);
        Assert::same(array('a2', 'a1'), $diff->new_tables);
        Assert::same(array(), array_keys($diff->changed_tables));


        $diff = DbSchemaDiff::compare($this->schema_a2, $this->schema_a2a1);
        Assert::true($diff->has_changes());
        Assert::same(array(), $diff->deleted_tables);
        Assert::same(array('a1'), $diff->new_tables);
        Assert::same(array(), array_keys($diff->changed_tables));
    }

    function testNewAndDelete() {
        $diff = DbSchemaDiff::compare($this->schema_a1, $this->schema_a2);
        Assert::true($diff->has_changes());
        Assert::same(array('a1'), $diff->deleted_tables);
        Assert::same(array('a2'), $diff->new_tables);
        Assert::same(array(), array_keys($diff->changed_tables));
    }


    function testNewColumn() {
        $diff = DbSchemaDiff::compare($this->schema_a1, $this->schema_a1_cz);
        Assert::true($diff->has_changes());
        Assert::same(array(), $diff->deleted_tables);
        Assert::same(array(), $diff->new_tables);
        Assert::same(array('a1'), array_keys($diff->changed_tables));
        Assert::same(array(), $diff->changed_tables['a1']->deleted_columns);
        Assert::same(array('z'), $diff->changed_tables['a1']->new_columns);
        Assert::same(array(), $diff->changed_tables['a1']->changed_columns);
    }
}

$test = new DbSchemaDiffTest();
$test->run();
