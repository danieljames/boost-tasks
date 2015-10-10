<?php

/*
 * Copyright 2013-2014 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;
use Monolog\Logger;

/**
 * Description of BoostUpdateApplication
 *
 * @author Daniel James <daniel@calamity.org.uk>
 */
class BoostUpdateApplication extends Application
{
    protected function configureIO(InputInterface $input = null,
            OutputInterface $output = null)
    {
        parent::configureIO($input, $output);

        // What if $output if falsey?
        if ($output) {
            $verbosity = $output->getVerbosity();
            if ($verbosity > OutputInterface::VERBOSITY_QUIET) {
                Log::$log->pushHandler(new ConsoleHandler($output,
                    $verbosity >= OutputInterface::VERBOSITY_DEBUG ? Logger::DEBUG : (
                    $verbosity >= OutputInterface::VERBOSITY_VERY_VERBOSE ? Logger::INFO : (
                    $verbosity >= OutputInterface::VERBOSITY_VERBOSE ? Logger::INFO : (
                    Logger::ERROR)))));
            }
        }

        try {
            if ($input) { $input->bind($this->getDefinition()); }
        }
        catch (\Exception $e) {
            // If validation fails here, do nothing, the
            // input will be revalidated by the command.
        }
    }

    /**
     * Gets the name of the command based on input.
     *
     * @param InputInterface $input The input interface
     * @return string The command name
     */
    protected function getCommandName(InputInterface $input)
    {
        return $input->getArgument('command');
    }

    /**
     * Gets the default commands that should always be available.
     *
     * @return array An array of default Command instances
     */
    protected function getDefaultCommands()
    {
        $defaultCommands = parent::getDefaultCommands();
        $defaultCommands[] = new SuperProjectCommand();
        return $defaultCommands;
    }
}

class SuperProjectCommand extends Command {
    protected function configure() {
        $this->setName('superproject')
            ->setDescription('Update the super projects')
            ->addOption('no-fetch', null, InputOption::VALUE_NONE,
                    "Don't fetch events from GitHub");
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        if (!$input->getOption('no-fetch')) { GitHubEventQueue::downloadEvents(); }
        SuperProject::updateBranches();
    }
}
