<?php

namespace BoostTasks;

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

    function processArgs() {
        try {
            $parser = new OptionParser($this->specs);
            $result = $parser->parse($this->args);
        } catch (InvalidOptionException $e) {
            $this->usage($e->getMessage());
            return 1;
        } catch (InvalidOptionValue $e) {
            $this->usage($e->getMessage());
            return 1;
        }

        if ($result->get('help')) {
            $this->usage();
            return 0;
        }

        return $result;
    }

    static function create($args, $description, $specs = null) {
        if (!$specs) { $specs = new OptionCollection(); }
        $specs->add('help', "Diplay command line usage.")
            ->defaultValue(false);
        $specs->add('cron', "Run as cron job.")
            ->defaultValue(false);
        $specs->add('verbose', "Verbose.")
            ->defaultValue(false);
        $specs->add('config-file:', "Configuration file.")
            ->isa('file');

        return new CommandLineOptions($args, $description, $specs);
    }

    // Returns an array of options, or an exit code.
    static function process($args, $description, $specs = null) {
        $x = self::create($args, $description, $specs);
        $result = $x->processArgs();
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
