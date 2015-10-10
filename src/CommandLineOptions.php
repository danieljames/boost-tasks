<?php

use GetOptionKit\OptionCollection;
use GetOptionKit\OptionParser;
use GetOptionKit\OptionPrinter\ConsoleOptionPrinter;
use GetOptionKit\Exception\InvalidOptionException;

// Very basic command line options handling thing.
class CommandLineOptions
{
    var $args;
    var $description;
    var $specs;

    function __construct($args, $description, $specs) {
        $this->args = $args;
        $this->description = $description;
        $this->specs = $specs;
    }

    static function process($args, $description, $specs = null) {
        if (!$specs) { $specs = new OptionCollection(); }
        $specs->add('help', "Diplay command line usage.")
            ->defaultValue(false);

        $x = new CommandLineOptions($args, $description, $specs);

        try {
            $parser = new OptionParser($specs);
            $options = $parser->parse($args)->toArray();
        } catch (InvalidOptionException $e) {
            $x->usage($e);
            exit(1);
        }

        if ($options['help']) {
            $x->usage();
            exit(0);
        }

        return $options;
    }

    function usage($message = null) {
        if ($message) { echo "{$message}\n\n"; }
        echo "{$this->description}\n\n";
        echo "Usage:\n";
        $printer = new ConsoleOptionPrinter();
        echo $printer->render($this->specs);
    }
}
