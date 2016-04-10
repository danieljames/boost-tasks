<?php

use Tester\Assert;

require_once(__DIR__.'/bootstrap.php');

class SuperProjectTest extends Tester\TestCase {
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
        Assert::false($x->enable_push);
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
}

$test = new SuperProjectTest();
$test->run();
