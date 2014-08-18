<?php

/*
 * Copyright 2013 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Some simple static methods for running processes.
 *
 * @author Daniel James <daniel@calamity.org.uk>
 */
class Process {
    public static function run($command, $cwd = null, array $env = null,
            $stdin = null, $timeout = 60, array $options = array())
    {
        $process = new SymfonyProcess($command, $cwd, $env, $stdin,
                $timeout, $options);
        
        $status = $process->run();

        if ($status != 0) {
            throw new \RuntimeException($process->getErrorOutput());
        }

        return $process;
    }

    public static function read_lines($command, $cwd = null, array $env = null,
            $stdin = null, $timeout = 60, array $options = array())
    {
        $process = new Process_LineProcess($command, $cwd, $env, $stdin,
                $timeout, $options);

        $status = $process->run();

        if ($status != 0) {
            throw new \RuntimeException($process->getErrorOutput());
        }
        
        return $process;
    }
}

class Process_LineProcess extends Symfony\Component\Process\Process
    implements IteratorAggregate
{
    /**
     * Runs process and iterates over output line by line.
     *
     * My original idea was to iterate concurrently with the process, but
     * Symfony doesn't make that easy. Retrospectively adding that might
     * be tricky.
     */

    function getIterator() {
        if (!$this->isStarted()) $this->start();
        $this->wait();

        $lines = array_map('rtrim', explode("\n", rtrim($this->getOutput())));
        return new ArrayIterator($lines);
    }
}
