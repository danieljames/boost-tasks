<?php

use GetOptionKit\OptionCollection;
use GetOptionKit\OptionParser;
use GetOptionKit\OptionPrinter\ConsoleOptionPrinter;
use GetOptionKit\Exception\InvalidOptionException;
use GetOptionKit\InvalidOptionValue;
use Nette\Object;

// Very basic command line options handling thing.
class CommandLineOptions extends Object
{
    var $args;
    var $description;
    var $specs;

    function __construct($args, $description, $specs) {
        $this->args = $args;
        $this->description = $description;
        $this->specs = $specs;
    }

    // Return GetOptionKit\OptionResult, or and exit code
    static function processArgs($args, $description, $specs = null) {
        if (!$specs) { $specs = new OptionCollection(); }
        $specs->add('help', "Diplay command line usage.")
            ->defaultValue(false);
        $specs->add('cron', "Run as cron job.")
            ->defaultValue(false);
        $specs->add('verbose', "Verbose.")
            ->defaultValue(false);
        $specs->add('config-file:', "Configuration file.")
            ->isa('file');

        $x = new CommandLineOptions($args, $description, $specs);

        try {
            $parser = new OptionParser($specs);
            $result = $parser->parse($args);
        } catch (InvalidOptionException $e) {
            $x->usage($e->getMessage());
            return 1;
        } catch (InvalidOptionValue $e) {
            $x->usage($e->getMessage());
            return 1;
        }

        if ($result->get('help')) {
            $x->usage();
            return 0;
        }

        return $result;
    }

    // Returns an array of options, or an exit code.
    static function process($args, $description, $specs = null) {
        $result = self::processArgs($args, $description, $specs);
        return is_object($result) ? $result->toArray() : $result;
    }

    function usage($message = null) {
        if ($message) { echo "{$message}\n\n"; }
        else { echo "{$this->description}\n\n"; }
        echo "Usage:\n";
        $printer = new ConsoleOptionPrinter();
        echo $printer->render($this->specs);
    }
}
