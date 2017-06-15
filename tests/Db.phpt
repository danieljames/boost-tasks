<?php

use Tester\Assert;
use BoostTasks\Db;
use BoostTasks\TempDirectory;

require_once(__DIR__.'/bootstrap.php');

class DbTest extends TestBase
{
    function testException() {
        $db = Db::createSqlite(':memory:');
        Assert::exception(function() use($db) {
            $db->exec('SELECT * FROM non_existant_table');
        }, 'PDOException', '#non_existant_table#');
        Assert::exception(function() use($db) {
            $db->find('non_existant_table');
        }, 'RuntimeException', '#non_existant_table#');
        Assert::exception(function() use($db) {
            $db->dispense('non_existant_table');
        }, 'RuntimeException', '#non_existant_table#');
        Assert::true($db->exec("CREATE TABLE test(value TEXT)"));
        Assert::exception(function() use($db) {
            $db->exec("CREATE TABLE test(value TEXT)");
        }, 'PDOException', '#\btable\b#');
        Assert::exception(function() use($db) {
            $db->exec("INSERT INTO test(values) SELECT 1");
        }, 'PDOException');
    }

    function testTurnOffExceptions() {
        $db = Db::createSqlite(':memory:');
        $db->pdo_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        Assert::false($db->exec('SELECT * FROM non_existant_table'));
        Assert::false($db->find('non_existant_table'));
        Assert::false($db->dispense('non_existant_table'));
        Assert::true($db->exec("CREATE TABLE test(value TEXT)"));
        Assert::false($db->exec("CREATE TABLE test(value TEXT)"));
        Assert::false($db->exec("INSERT INTO test(values) SELECT 1"));
    }

    function testCreateSqlite() {
        $temp = new TempDirectory;
        $path = "{$temp->path}/simple.db";
        $db = Db::createSqlite($path);
        Assert::true(is_file($path));

        $db->exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value TEXT DEFAULT 'something'
            );");

        $x1 = $db->dispense('test');
        $x1->value = 'foobar';
        $x1->store();

        $db2 = Db::createSqlite($path);
        $x2 = $db2->load('test', 1);
        Assert::same('foobar', $x2->value);
        $x2->value = 'foo';
        $x2->store();

        $x3 = $db->load('test', 1);
        Assert::same('foo', $x3->value);
    }

    function testTransaction() {
        $db = Db::create("sqlite::memory:");
        Assert::truthy($db);

        $db->exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value TEXT DEFAULT 'something'
            );");

        Assert::same("hello", $db->transaction(function() use($db) {
            $db->exec("INSERT INTO test(value) VALUES('value1')");
            return "hello";
        }));

        Assert::same('1', $db->getCell("SELECT COUNT(*) FROM test"));
        Assert::exception(function() use($db) {
            $db->transaction(function() use($db) {
                $db->exec("INSERT INTO test(value) VALUES('value2')");
                Assert::same('2', $db->getCell("SELECT COUNT(*) FROM test"));
                throw new RuntimeException();
            });
        }, 'RuntimeException');

        Assert::same('1', $db->getCell("SELECT COUNT(*) FROM test"));

        $db->begin();
        $db->exec("INSERT INTO test(value) VALUES('value3')");
        Assert::same('2', $db->getCell("SELECT COUNT(*) FROM test"));
        $db->commit();
        Assert::same('2', $db->getCell("SELECT COUNT(*) FROM test"));

        $db->begin();
        $db->exec("INSERT INTO test(value) VALUES('value4')");
        Assert::same('3', $db->getCell("SELECT COUNT(*) FROM test"));
        $db->rollback();
        Assert::same('2', $db->getCell("SELECT COUNT(*) FROM test"));

        Assert::same(
            array(
                array('id' => '1', 'value' => 'value1'),
                array('id' => '2', 'value' => 'value3'),
            ),
            $db->getAll('SELECT * FROM test')
        );
    }

    function testSqlite() {
        $db = Db::create("sqlite::memory:");

        $db->exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value TEXT DEFAULT 'something'
            );");

        $x1 = $db->dispense('test');

        // TODO: Maybe filter out names with a certain convention?
        //       Or make __meta private.
        $properties = array_keys(get_object_vars($x1));
        sort($properties);
        Assert::same(array('__meta', 'id', 'value'), $properties);

        Assert::same('something', $x1->value);
        Assert::true($x1->store());
        Assert::same('1', $x1->id);

        $x1->value = 'else';
        Assert::true($db->store($x1));
        Assert::same('1', $x1->id);

        $x1_ = $db->load('test', 1);
        Assert::same('1', $x1_->id);
        Assert::same('else', $x1_->value);
        $x1_->store();
        Assert::same('1', $x1_->id);

        $properties = array_keys(get_object_vars($x1_));
        sort($properties);
        Assert::same(array('__meta', 'id', 'value'), $properties);

        $x2 = $db->dispense('test');
        Assert::same('something', $x2->value);
        $x2->value = 'again';
        $x2->store();
        Assert::same('2', $x2->id);

        $x2_ = $db->findOne('test', 'value = ?', array('again'));
        Assert::same('2', $x2_->id);
        $x2_->value = '0';
        $x2_->store();

        $rows = $db->find('test');
        Assert::same('1', $rows[0]->id);
        Assert::same('else', $rows[0]->value);
        Assert::same('2', $rows[1]->id);
        Assert::same('0', $rows[1]->value);
        $rows[0]->value = '1';
        $rows[1]->value = '2';
        $rows[0]->store();
        $rows[1]->store();

        $rows2 = $db->getAll('select * from test');
        Assert::same('1', $rows2[0]['id']);
        Assert::same('1', $rows2[0]['value']);
        Assert::same('2', $rows2[1]['id']);
        Assert::same('2', $rows2[1]['value']);

        $rows3 = $db->convertToBeans('test', $rows2);
        $rows3[0]->value = 'one';
        $rows3[1]->value = 'two';
        $rows3[0]->store();
        $rows3[1]->store();

        $row1 = $db->getRow('select * from test');
        Assert::same('1', $row1['id']);
        Assert::same('one', $row1['value']);

        $value2 = $db->getCell('select value from test where id = ?', array(2));
        Assert::same('two', $value2);

        Assert::true($db->trash($rows3[0]));
        $row2 = $db->getRow('select * from test');
        Assert::same('2', $row2['id']);
        Assert::same('two', $row2['value']);
        Assert::same(1, count($db->find('test')));
    }

    function testSqliteDefaults() {
        $db = Db::create("sqlite::memory:");

        $db->exec("
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

        $x1 = $db->dispense('test');
        Assert::same('something', $x1->value1);
        Assert::same('something', $x1->value2);
        Assert::same('something', $x1->value3);
        Assert::same('0', $x1->value4);
        Assert::same('100', $x1->value5);
        Assert::same('-100', $x1->value6);
        Assert::same('null', $x1->value7);
        Assert::same(null, $x1->value8);
        $x1->id = 100;
        $x1->store();
        Assert::same('100', (string) $x1->id);

        $x2 = $db->load('test', 100);
        Assert::same('100', $x2->id);
        Assert::same('something', $x2->value1);
        Assert::same('something', $x2->value2);
        Assert::same('something', $x2->value3);
        Assert::same('0', $x2->value4);
        Assert::same('100', $x2->value5);
        Assert::same('-100', $x2->value6);
        Assert::same('null', $x2->value7);
        Assert::same(null, $x2->value8);
    }

    function testGeneratedValue() {
        $db = Db::create("sqlite::memory:");

        $db->exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                t TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );");
        $x = $db->dispense('test');
        $x->store();

        $y = $db->load('test', 1);
        Assert::same($y->t, $x->t);

        $date1 = new DateTime('10 Jun 2005');
        $x = $db->dispense('test');
        $x->t = $date1;
        $x->store();

        $y = $db->load('test', 2);
        Assert::equal($date1->getTimestamp(), strtotime($y->t));

        $date2 = new DateTime('30 May 2001 15:36 +0300');
        $x = $db->dispense('test');
        $x->t = $date2;
        $x->store();

        $y = $db->load('test', 3);
        Assert::equal($date2->getTimestamp(), strtotime($y->t));
    }

    function testGet() {
        $db = Db::create("sqlite::memory:");
        Assert::true($db->exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value INTEGER
            );"));
        Assert::truthy($x = $db->dispense('test'));
        $x->value = 10;
        Assert::true($x->store());

        Assert::equal('10', $db->getCell('SELECT value FROM test'));
        Assert::equal('10', $db->getCell('SELECT value FROM test WHERE id=?', array('1')));
        Assert::null($db->getCell('SELECT value FROM test WHERE id=?', array('2')));

        Assert::equal(array('value' => '10'), $db->getRow('SELECT value FROM test WHERE id=?', array('1')));
        Assert::null($db->getRow('SELECT value FROM test WHERE id=?', array('2')));

        Assert::equal(array(array('value' => '10')), $db->getAll('SELECT value FROM test WHERE id=?', array('1')));
        Assert::equal(array(), $db->getAll('SELECT value FROM test WHERE id=?', array('2')));

        // Error checking

        Assert::exception(function() use($db) {
            $db->getCell('SELECT value FROM non_existant');
        }, 'RuntimeException');
        Assert::exception(function() use($db) {
            $db->getRow('SELECT * FROM non_existant');
        }, 'RuntimeException');
        Assert::exception(function() use($db) {
            $db->getAll('SELECT * FROM non_existant');
        }, 'RuntimeException');

        $db->pdo_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        Assert::false($db->getCell('SELECT value FROM non_existant'));
        Assert::false($db->getRow('SELECT * FROM non_existant'));
        Assert::false($db->getAll('SELECT * FROM non_existant'));
    }

    function testFind() {
        $db = Db::create("sqlite::memory:");
        Assert::true($db->exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value INTEGER
            );"));
        Assert::truthy($x = $db->dispense('test'));
        $x->value = 10;
        Assert::true($x->store());

        $entities = $db->find('test');
        Assert::equal(array(0), array_keys($entities));
        Assert::equal('10', $entities[0]->value);

        $entities = $db->find('test', 'id=?', array('1'));
        Assert::equal(array(0), array_keys($entities));
        Assert::equal('10', $entities[0]->value);

        $entities = $db->find('test', 'id=?', array('2'));
        Assert::equal(array(), $entities);

        Assert::truthy($entity = $db->findOne('test'));
        Assert::equal('10', $entity->value);

        Assert::truthy($entity = $db->findOne('test', 'id=?', array('1')));
        Assert::equal('10', $entity->value);

        Assert::null($db->findOne('test', 'id=?', array('2')));

        // Error checking

        Assert::exception(function() use($db) {
            $db->find('non_existant');
        }, 'RuntimeException');
        Assert::exception(function() use($db) {
            $db->findOne('non_existant');
        }, 'RuntimeException');

        $db->pdo_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        Assert::false($db->find('non_existant'));
        Assert::false($db->findOne('non_existant'));
    }
}

$test = new DbTest();
$test->run();
