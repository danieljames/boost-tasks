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
        }, 'PDOException', '#non_existant_table#');
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
        // Q: Should dispense throw an exception here, or return false?
        Assert::exception(function() use($db) {
            $db->dispense('non_existant_table');
        }, 'RuntimeException', '#non_existant_table#');
        Assert::true($db->exec("CREATE TABLE test(value TEXT)"));
        Assert::false($db->exec("CREATE TABLE test(value TEXT)"));
        Assert::false($db->exec("INSERT INTO test(values) SELECT 1"));
    }

    function testInitSqlite() {
        Assert::null(Db::$instance);
        Db::initSqlite(':memory:');

        Db::exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value TEXT DEFAULT 'something'
            );");

        $x1 = Db::dispense('test');

        Assert::truthy(Db::$instance);
        Db::$instance = null;
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
        Assert::true(Db::setup("sqlite::memory:"));

        Db::exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value TEXT DEFAULT 'something'
            );");

        Assert::same("hello", db::transaction(function() {
            Db::exec("INSERT INTO test(value) VALUES('value1')");
            return "hello";
        }));

        Assert::same('1', Db::getCell("SELECT COUNT(*) FROM test"));
        Assert::exception(function() {
            db::transaction(function() {
                Db::exec("INSERT INTO test(value) VALUES('value2')");
                Assert::same('2', Db::getCell("SELECT COUNT(*) FROM test"));
                throw new RuntimeException();
            });
        }, 'RuntimeException');

        Assert::same('1', Db::getCell("SELECT COUNT(*) FROM test"));

        Db::begin();
        Db::exec("INSERT INTO test(value) VALUES('value3')");
        Assert::same('2', Db::getCell("SELECT COUNT(*) FROM test"));
        Db::commit();
        Assert::same('2', Db::getCell("SELECT COUNT(*) FROM test"));

        Db::begin();
        Db::exec("INSERT INTO test(value) VALUES('value4')");
        Assert::same('3', Db::getCell("SELECT COUNT(*) FROM test"));
        Db::rollback();
        Assert::same('2', Db::getCell("SELECT COUNT(*) FROM test"));

        Assert::same(
            array(
                array('id' => '1', 'value' => 'value1'),
                array('id' => '2', 'value' => 'value3'),
            ),
            Db::getAll('SELECT * FROM test')
        );
    }

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
        Assert::true($x1->store());
        Assert::same('1', $x1->id);

        $x1->value = 'else';
        Assert::true(Db::store($x1));
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

        Assert::true(Db::trash($rows3[0]));
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
        $x1->id = 100;
        $x1->store();
        Assert::same('100', $x1->id);

        $x2 = Db::load('test', 100);
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

        $date1 = new DateTime('10 Jun 2005');
        $x = Db::dispense('test');
        $x->t = $date1;
        $x->store();

        $y = Db::load('test', 2);
        Assert::equal($date1->getTimestamp(), strtotime($y->t));

        $date2 = new DateTime('30 May 2001 15:36 +0300');
        $x = Db::dispense('test');
        $x->t = $date2;
        $x->store();

        $y = Db::load('test', 3);
        Assert::equal($date2->getTimestamp(), strtotime($y->t));
    }

    function testGet() {
        Db::setup("sqlite::memory:");
        Assert::true(Db::exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value INTEGER
            );"));
        Assert::truthy($x = Db::dispense('test'));
        $x->value = 10;
        Assert::true($x->store());

        Assert::equal('10', Db::getCell('SELECT value FROM test'));
        Assert::equal('10', Db::getCell('SELECT value FROM test WHERE id=?', array('1')));
        Assert::null(Db::getCell('SELECT value FROM test WHERE id=?', array('2')));

        Assert::equal(array('value' => '10'), Db::getRow('SELECT value FROM test WHERE id=?', array('1')));
        Assert::null(Db::getRow('SELECT value FROM test WHERE id=?', array('2')));

        Assert::equal(array(array('value' => '10')), Db::getAll('SELECT value FROM test WHERE id=?', array('1')));
        Assert::equal(array(), Db::getAll('SELECT value FROM test WHERE id=?', array('2')));
    }

    function testFind() {
        Db::setup("sqlite::memory:");
        Assert::true(Db::exec("
            CREATE TABLE test(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                value INTEGER
            );"));
        Assert::truthy($x = Db::dispense('test'));
        $x->value = 10;
        Assert::true($x->store());

        $entities = Db::find('test');
        Assert::equal(array(0), array_keys($entities));
        Assert::equal('10', $entities[0]->value);

        $entities = Db::find('test', 'id=?', array('1'));
        Assert::equal(array(0), array_keys($entities));
        Assert::equal('10', $entities[0]->value);

        $entities = Db::find('test', 'id=?', array('2'));
        Assert::equal(array(), $entities);

        Assert::truthy($entity = Db::findOne('test'));
        Assert::equal('10', $entity->value);

        Assert::truthy($entity = Db::findOne('test', 'id=?', array('1')));
        Assert::equal('10', $entity->value);

        Assert::null(Db::findOne('test', 'id=?', array('2')));
    }
}

$test = new DbTest();
$test->run();
