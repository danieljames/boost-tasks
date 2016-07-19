<?php

use Tester\Assert;
use BoostTasks\TempDirectory;

require_once(__DIR__.'/bootstrap.php');

class SuperProjectTest extends TestBase {
    function testCreateSuperProject() {
        $x = new SuperProject(array(
            'path' => __DIR__,
            'superproject-branch' => 'super-branch',
            'submodule-branch' => 'sub-branch',
        ));

        Assert::same(__DIR__, $x->path);
        Assert::same('boost', $x->module);
        Assert::same('super-branch', $x->branch);
        Assert::same('sub-branch', $x->submodule_branch);
        // TODO: This fails on the server:
        //Assert::false($x->enable_push);
    }

    function testIncompleteSuperProject() {
        Assert::exception(function() {
            $x = new SuperProject(array());
        }, 'RuntimeException');

        Assert::exception(function() {
            $x = new SuperProject(array(
                'superproject-branch' => 'super-branch',
                'submodule-branch' => 'sub-branch',
            ));
        }, 'RuntimeException');

        Assert::exception(function() {
            $x = new SuperProject(array(
                'path' => __DIR__,
                'submodule-branch' => 'sub-branch',
            ));
        }, 'RuntimeException');

        Assert::exception(function() {
            $x = new SuperProject(array(
                'path' => __DIR__,
                'superproject-branch' => 'super-branch',
            ));
        }, 'RuntimeException');
    }

    function testSubmoduleWithRelativeUrl() {
        $x = new SuperProject_Submodule('vmd', array(
            'path' => 'libs/vmd',
            'url' => '../vmd.git',
            'fetchRecurseSubmodules' => 'on-demand',
        ));

        Assert::same('libs/vmd', $x->path);
        Assert::same('vmd', $x->boost_name);
        Assert::same('boostorg/vmd', $x->github_name);
    }

    function testSubmoduleWithBoostorgUrl() {
        $x = new SuperProject_Submodule('flip/flop', array(
            'path' => 'libs/flop',
            'url' => 'https://github.com/boostorg/flip.git',
        ));

        Assert::same('libs/flop', $x->path);
        Assert::same('flip/flop', $x->boost_name);
        Assert::same('boostorg/flip', $x->github_name);
    }

    function testSubmoduleWithRejectedUrl() {
        $x = new SuperProject_Submodule('flip/flop', array(
            'path' => 'libs/flop',
            'url' => 'https://github.com/danieljames/flip.git',
        ));

        Assert::same('libs/flop', $x->path);
        Assert::same('flip/flop', $x->boost_name);
        Assert::null($x->github_name);
    }

    function testUpdateHashes() {
        $temp_directory = new TempDirectory();
        $repo_paths = Array(
            'super' => "{$temp_directory->path}/super",
            'sub1' => "{$temp_directory->path}/sub1",
            'sub2' => "{$temp_directory->path}/sub2",
        );

        foreach($repo_paths as $module_path) {
            mkdir($module_path);
            file_put_contents("{$module_path}/empty-file.txt", "{$module_path}\n");
            $repo = new RepoBase($module_path);
            $repo->command("init");
            $repo->command("config user.email testing@example.com");
            $repo->command("config user.name Testing");
            $repo->command("add .");
            $repo->command("commit -m 'Initial commit'");
        }

        $repo = new RepoBase($repo_paths['super']);
        $repo->command("submodule add '../sub1' libs/sub1");
        $repo->command("submodule add --name 'arbitrary_name' '../sub2' libs/sub2");
        $repo->command("commit -m 'add submodules'");

        $hashes = array();
        foreach($repo_paths as $name => $module_path) {
            $repo = new RepoBase($module_path);
            $hashes[$name] = $repo->read_lines('rev-parse master')->current();
        }

        $super_project = new SuperProject(array(
            'superproject-branch' => 'master',
            'path' => "{$temp_directory->path}/working-repo",
            'submodule-branch' => 'master',
            'remote_url' => $repo_paths['super'],
        ));

        // Normally called by attemptAndPush.
        $super_project->setupCleanCheckout();

        $submodules = $super_project->getSubmodules();
        $submodules['boostorg/sub1']->updated_hash_value = $hashes['sub1'];
        $super_project->updateHashes($submodules);
        Assert::same(
            $hashes['super'],
            $super_project->read_lines('rev-parse master')->current()
        );

        $submodules = $super_project->getSubmodules();
        $submodules['boostorg/sub1']->updated_hash_value = $hashes['sub2'];
        $super_project->updateHashes($submodules);
        $hash2 = $super_project->read_lines('rev-parse master')->current();
        Assert::notSame(
            $hashes['super'],
            $hash2
        );

        $submodules = $super_project->getSubmodules();
        $submodules['boostorg/sub1']->updated_hash_value = $hashes['sub2'];
        $submodules['boostorg/sub2']->updated_hash_value = $hashes['sub2'];
        $super_project->updateHashes($submodules);
        Assert::same(
            $hash2,
            $super_project->read_lines('rev-parse master')->current()
        );

        $submodules = $super_project->getSubmodules();
        $submodules['boostorg/sub1']->updated_hash_value = $hashes['sub2'];
        $submodules['boostorg/sub2']->updated_hash_value = $hashes['sub1'];
        $super_project->updateHashes($submodules);
        $hash3 = $super_project->read_lines('rev-parse master')->current();
        Assert::notSame(
            $hash2,
            $hash3
        );
    }
}

$test = new SuperProjectTest();
$test->run();
