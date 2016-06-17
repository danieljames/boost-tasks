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

    static function process($args, $description, $specs = null) {
        if (!$specs) { $specs = new OptionCollection(); }
        $specs->add('help', "Diplay command line usage.")
            ->defaultValue(false);
        $specs->add('cron', "Run as cron job.")
            ->defaultValue(false);
        $specs->add('config-file:', "Configuration file.")
            ->isa('file');

        $x = new CommandLineOptions($args, $description, $specs);

        try {
            $parser = new OptionParser($specs);
            $options = $parser->parse($args)->toArray();
        } catch (InvalidOptionException $e) {
            $x->usage($e->getMessage());
            exit(1);
        } catch (InvalidOptionValue $e) {
            $x->usage($e->getMessage());
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
        else { echo "{$this->description}\n\n"; }
        echo "Usage:\n";
        $printer = new ConsoleOptionPrinter();
        echo $printer->render($this->specs);
    }
}
