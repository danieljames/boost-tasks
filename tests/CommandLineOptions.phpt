<?php

use Tester\Assert;
use GetOptionKit\OptionCollection;

require_once(__DIR__.'/bootstrap.php');

class CommandLineOptionsTest extends \Tester\TestCase
{
    function testBasic() {
        $options = CommandLineOptions::process(array('command'), 'Simple test');
        Assert::false($options['cron']);
        Assert::false($options['verbose']);

        $options = CommandLineOptions::process(array('command', '--cron'), 'Simple test');
        Assert::true($options['cron']);
        Assert::false($options['verbose']);

        $options = CommandLineOptions::process(array('command', '--cron', '--verbose'), 'Simple test');
        Assert::true($options['cron']);
        Assert::true($options['verbose']);
    }

    function testSpecs() {
        $specs = new OptionCollection;
        $specs->add('flag', "Test flag");
        $specs->add('flag2', "Test flag with default")->defaultValue(false);
        $specs->add('string:', "Test flag with default")->isa('String');

        $options = CommandLineOptions::process(array('command'), 'Simple test', $specs);
        Assert::false($options['cron']);
        Assert::false(array_key_exists('flag', $options));
        Assert::false($options['flag2']);
        Assert::false(array_key_exists('string', $options));

        $options = CommandLineOptions::process(array('command', '--cron', '--flag2'), 'Simple test', $specs);
        Assert::true($options['cron']);
        Assert::false(array_key_exists('flag', $options));
        Assert::true($options['flag2']);
        Assert::false(array_key_exists('string', $options));

        $options = CommandLineOptions::process(array('command', '--flag', '--string', 'value'), 'Simple test', $specs);
        Assert::false($options['cron']);
        Assert::true($options['flag']);
        Assert::false($options['flag2']);
        Assert::same('value', $options['string']);
    }

    function testHelp() {
        ob_start();
        $options = CommandLineOptions::process(array('command', '--help'), 'Simple test');
        $output = ob_get_clean();
        Assert::same(0, $options);
        Assert::true(strpos($output, 'Simple test') !== false);
        Assert::true(strpos($output, 'Usage:') !== false);
        Assert::true(strpos($output, '--cron') !== false);
    }

    function testInvalidOption() {
        ob_start();
        $options = CommandLineOptions::process(array('command', '--fart-gun'), 'Simple test');
        $output = ob_get_clean();
        Assert::same(1, $options);
        Assert::true(strpos($output, '--fart-gun') !== false);
        Assert::true(strpos($output, 'Usage:') !== false);
    }

    function testInvalidOptionValue() {
        $specs = new OptionCollection;
        $specs->add('number:', 'Some number')->isa('Number');

        $options = CommandLineOptions::process(array('command', '--number', 10), 'Simple test', $specs);
        Assert::same(10, $options['number']);

        ob_start();
        $options = CommandLineOptions::process(array('command', '--number', 'hello'), 'Simple test', $specs);
        $output = ob_get_clean();
        Assert::same(1, $options);
        Assert::true(strpos($output, 'Invalid value') !== false);
        Assert::true(strpos($output, 'Usage:') !== false);
    }
}

$x = new CommandLineOptionsTest;
$x->run();
