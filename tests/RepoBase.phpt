<?php

use Tester\Assert;
use BoostTasks\TempDirectory;

require_once(__DIR__.'/bootstrap.php');

class RepoBaseTest extends \Tester\TestCase
{
    // Tests a bug in old versions of git.
    function testFetchWithBranchFileReplacedByDirectory() {
        $temp_directory = new TempDirectory();

        $base_path = "{$temp_directory->path}/base";
        $mirror_path = "{$temp_directory->path}/mirror";

        mkdir($base_path);
        file_put_contents("{$base_path}/Hello.txt", "Hello!\n");

        $base_repo = new RepoBase($base_path);
        $base_repo->command("init");
        $base_repo->command("config --local user.name Testing");
        $base_repo->command("config --local user.email 'testing@example.com'");
        $base_repo->command("add .");
        $base_repo->command("commit -m 'Initial commit'");
        $base_repo->command("branch test");

        Process::run("git clone --mirror base mirror", $temp_directory->path);
        $mirror_repo = new RepoBase($mirror_path);

        $base_repo->command('branch -d test');
        $base_repo->command('branch test/subbranch');

        $mirror_repo->command('fetch -p');

        Assert::true(true);
    }
}

$test = new RepoBaseTest();
$test->run();
