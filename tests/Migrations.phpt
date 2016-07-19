<?php

use Tester\Assert;
use BoostTasks\Db;

require_once(__DIR__.'/bootstrap.php');

class MigrationsTest extends TestBase
{
    function testSqlite() {
        $db = Db::create("sqlite::memory:");
        Migrations::migrate($db);

        // I don't currently have a decent way to check the schema
        // so just try entering some data, and seeing if it works
        // okay.

        $db->exec('
            INSERT INTO event(github_id, branch, repo, payload, sequence_start, type)
            VALUES(9437, "branch", "repo", "payload", 0, "type")');

        $x = $db->load('event', 1);
        Assert::same('1', $x->id);
        Assert::same('9437', $x->github_id);
        Assert::same('branch', $x->branch);
        Assert::same('repo', $x->repo);
        Assert::same('payload', $x->payload);
        Assert::same('0', $x->sequence_start);
        Assert::same('type', $x->type);
    }
}

$test = new MigrationsTest();
$test->run();
