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
        $defaultCommands[] = new MirrorCommand();
        $defaultCommands[] = new BuildDocCommand();
        $defaultCommands[] = new UpdateDocumentListCommand();
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

class UpdateDocumentListCommand extends Command {
    protected function configure() {
        $this->setName('update-doc-list')
            ->setDescription('Update the documentation list.')
            ->addArgument('version', InputArgument::OPTIONAL,
                'Version to update (e.g. develop, 1.57.0)');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        // TODO: For some reason 'hasArgument' is always true, even when
        // there isn't a version. Am I misunderstanding it? Doesn't really
        // matter as it ends up falsey.
        $version = $input->hasArgument('version')
            ? $input->getArgument('version') : null;

        // Update the mirror
        GitHubEventQueue::downloadEvents();
        $mirror = new LocalMirror();
        $mirror->refresh();
        $mirror->fetchDirty();

        // Update the website repo.
        $website_repo = new WebsiteRepo();
        $result = $website_repo->updateDocumentationList($mirror, $version);
        if (!$result) {
            // Want a hard failure here, so that we're not updating the
            // super projects from data that isn't checked in.
            throw new RuntimeException("Failed to update documentation list on website.");
        }

        // Update maintainer lists.
        foreach (EvilGlobals::$branch_repos as $x) {
            $website_repo->updateSuperProject(new SuperProject($x));
        }
    }
}
