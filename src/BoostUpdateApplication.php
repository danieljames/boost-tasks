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
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\StreamHandler;

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

        // Set up the logger.

        Log::$log = new Logger('boost update log');
        Log::$log->pushHandler(
                new StreamHandler(EvilGlobals::$data_root."/log.txt", Logger::INFO));

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
        $defaultCommands[] = new CronJobCommand();
        $defaultCommands[] = new EventListCommand();
        $defaultCommands[] = new SuperProjectCommand();
        $defaultCommands[] = new MirrorCommand();
        $defaultCommands[] = new MirrorListCommand();
        $defaultCommands[] = new SuperProjectCommand();
        $defaultCommands[] = new PullRequestReportCommand();
        $defaultCommands[] = new BuildDocCommand();
        return $defaultCommands;
    }
}

class CronJobCommand extends Command {
    protected function configure() { $this->setName('cron'); }

    protected function execute(InputInterface $input, OutputInterface $output) {
        GitHubEventQueue::downloadEvents();
        //$this->callCommand($input, $output, 'mirror', array('--no-fetch'));
        $this->callCommand($input, $output, 'superproject', array('--no-fetch'));
    }

    private function callCommand($input, $output, $name, $arguments) {
        $command = $this->getApplication()->find($name);
        $input = new Symfony\Component\Console\Input\ArrayInput($arguments);
        return $command->run($input, $output);
    }
}

class EventListCommand extends Command {
    protected function configure() { $this->setName('event-list'); }

    protected function execute(InputInterface $input, OutputInterface $output) {
        GitHubEventQueue::outputEvents();
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
        foreach (EvilGlobals::$branch_repos as $x) {
            $super = new SuperProject($x);
            $super->checkedUpdateFromEvents();
        }
    }
}

class MirrorCommand extends Command {
    protected function configure() {
        $this->setName('mirror')
            ->setDescription('Creates or updates the GitHub mirror')
            ->addOption('no-fetch', null, InputOption::VALUE_NONE,
                    "Don't fetch events from GitHub")
            ->addOption('all', null, InputOption::VALUE_NONE,
                    "Update all repos in mirror");
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        if (!$input->getOption('no-fetch')) { GitHubEventQueue::downloadEvents(); }
        GitHubEventQueue::downloadEvents();
        $mirror = new LocalMirror();
        if ($input->getOption('all')) {
            $mirror->refreshAll();
        } else {
            $mirror->refresh();
        }
        $mirror->fetchDirty();
    }
}

class MirrorListCommand extends Command {
    protected function configure() { $this->setName('mirror-list'); }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $mirror = new LocalMirror();
        $mirror->outputRepos();
    }
}

class PullRequestReportCommand extends Command {
    protected function configure() { $this->setName('pull-request-report'); }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $report = new PullRequestReport();
        $report->run();
    }
}

class BuildDocCommand extends Command {
    protected function configure() {
        $this->setName('build-docs')
            ->setDescription('Build the documentation (updating the mirror first).')
            ->addArgument('branch', InputArgument::IS_ARRAY, 'Branch to build');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $branch = $input->getArgument('branch');

        GitHubEventQueue::downloadEvents();
        $mirror = new LocalMirror();
        $mirror->refresh();
        $mirror->fetchDirty();
        passthru(__DIR__.'/../doc-build/build-docs'.
            ($branch ? ' build '.implode(' ', $branch) : ''));
    }
}
