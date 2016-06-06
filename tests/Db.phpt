<?php

use Tester\Assert;
use BoostTasks\Db;

require_once(__DIR__.'/bootstrap.php');

class DbTest extends Tester\TestCase
{
    function testSqlite() {
        Db::setup("sqlite::memory:");

        Db::exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value TEXT DEFAULT 'something'
            );");

        $x1 = Db::dispense('test');

        // TODO: Maybe filter out names with a certain convention?
        //       Or make __meta private.
        $properties = array_keys(get_object_vars($x1));
        sort($properties);
        Assert::same(array('__meta', 'id', 'value'), $properties);

        Assert::same('something', $x1->value);
        $x1->store();
        Assert::same('1', $x1->id);

        $x1->value = 'else';
        $x1->store();
        Assert::same('1', $x1->id);

        $x1_ = Db::load('test', 1);
        Assert::same('1', $x1_->id);
        Assert::same('else', $x1_->value);
        $x1_->store();
        Assert::same('1', $x1_->id);

        $properties = array_keys(get_object_vars($x1_));
        sort($properties);
        Assert::same(array('__meta', 'id', 'value'), $properties);

        $x2 = Db::dispense('test');
        Assert::same('something', $x2->value);
        $x2->value = 'again';
        $x2->store();
        Assert::same('2', $x2->id);

        $x2_ = Db::findOne('test', 'value = ?', array('again'));
        Assert::same('2', $x2_->id);
        $x2_->value = '0';
        $x2_->store();

        $rows = Db::find('test');
        Assert::same('1', $rows[0]->id);
        Assert::same('else', $rows[0]->value);
        Assert::same('2', $rows[1]->id);
        Assert::same('0', $rows[1]->value);
        $rows[0]->value = '1';
        $rows[1]->value = '2';
        $rows[0]->store();
        $rows[1]->store();

        $rows2 = Db::getAll('select * from test');
        Assert::same('1', $rows2[0]['id']);
        Assert::same('1', $rows2[0]['value']);
        Assert::same('2', $rows2[1]['id']);
        Assert::same('2', $rows2[1]['value']);

        $rows3 = Db::convertToBeans('test', $rows2);
        $rows3[0]->value = 'one';
        $rows3[1]->value = 'two';
        $rows3[0]->store();
        $rows3[1]->store();

        $row1 = Db::getRow('select * from test');
        Assert::same('1', $row1['id']);
        Assert::same('one', $row1['value']);

        $value2 = Db::getCell('select value from test where id = ?', array(2));
        Assert::same('two', $value2);

        $rows3[0]->trash();
        $row2 = Db::getRow('select * from test');
        Assert::same('2', $row2['id']);
        Assert::same('two', $row2['value']);
        Assert::same(1, count(Db::find('test')));
    }

    function testSqliteDefaults() {
        Db::setup("sqlite::memory:");

        Db::exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value1 TEXT DEFAULT 'something',
                value2 TEXT DEFAULT \"something\",
                value3 TEXT DEFAULT `something`,
                value4 INT DEFAULT 0,
                value5 INT DEFAULT +100,
                value6 INT DEFAULT -100,
                value7 TEXT DEFAULT 'null',
                value8 TEXT DEFAULT null
            );");

        $x1 = Db::dispense('test');
        Assert::same('something', $x1->value1);
        Assert::same('something', $x1->value2);
        Assert::same('something', $x1->value3);
        Assert::same('0', $x1->value4);
        Assert::same('100', $x1->value5);
        Assert::same('-100', $x1->value6);
        Assert::same('null', $x1->value7);
        Assert::same(null, $x1->value8);
    }

    function testGeneratedValue() {
        Db::setup("sqlite::memory:");

        Db::exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                t TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );");
        $x = Db::dispense('test');
        $x->store();

        $y = Db::load('test', 1);
        Assert::same($y->t, $x->t);
    }

    function testDispenseError() {
        Db::setup("sqlite::memory:");
        Assert::exception(function() { Db::dispense('test'); }, 'RuntimeException');
    }
}

$test = new DbTest();
$test->run();
