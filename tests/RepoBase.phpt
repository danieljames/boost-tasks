<?php

use Tester\Assert;
use BoostTasks\TempDirectory;
use BoostTasks\RepoBase;
use BoostTasks\Process;

require_once(__DIR__.'/bootstrap.php');

class RepoBaseTest extends \TestBase
{
    // Tests a bug in old versions of git.
    //
    // For details see:
    //
    // https://stackoverflow.com/a/21072934/2434
    // https://github.com/git/git/commit/10a6cc8890ec1e5459c05ddeb28a671acdc37d60
    function testFetchWithBranchFileReplacedByDirectory() {
        $temp_directory = new TempDirectory();

        $base_path = "{$temp_directory->path}/base";
        $mirror_path = "{$temp_directory->path}/mirror";

        mkdir($base_path);
        file_put_contents("{$base_path}/Hello.txt", "Hello!\n");

        $base_repo = new RepoBase($base_path);
        $base_repo->command("init -q");
        $base_repo->command("config user.email testing@example.com");
        $base_repo->command("config user.name Testing");
        $base_repo->command("add .");
        $base_repo->command("commit -q -m 'Initial commit'");
        $base_repo->command("branch test");

        Process::run("git clone -q --mirror base mirror", $temp_directory->path);
        $mirror_repo = new RepoBase($mirror_path);

        $branches = iterator_to_array($mirror_repo->readLines('branch'));
        Assert::same(array('* master', '  test'),$branches);

        $base_repo->command('branch -d test');
        $base_repo->command('branch test/subbranch');

        $mirror_repo->fetchWithPrune();

        $branches = iterator_to_array($mirror_repo->readLines('branch'));
        Assert::same(array('* master', '  test/subbranch'),$branches);
    }
}

$test = new RepoBaseTest();
$test->run();
