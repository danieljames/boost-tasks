<?php

/*
 * Copyright 2013-2016 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

namespace BoostTasks;

use RuntimeException;
use Iterator;

class Process {
    var $process;
    var $child_stdin;
    var $child_stdout;
    var $child_stderr;
    var $stderr;
    var $status = null;
    var $timeout_at = null;

    public static function run($command, $cwd = null, array $env = null,
            $input = null, $timeout = 60, array $options = array())
    {
        $process = new self($command, $cwd, $env, $timeout, $options);
        // TODO: Timeout here.
        if ($input) { fwrite($process->child_stdin, $input); }
        $process->closeChildStdin();
        $process->join();
        $process->closeWithErrorCheck();
    }

    public static function status($command, $cwd = null, array $env = null,
            $input = null)
    {
        $process = new self($command, $cwd);
        if ($input) { fwrite($process->child_stdin, $input); }
        $process->closeChildStdin();
        $process->join();
        $process->close();
        return $process->status;
    }

    public static function read($command, $cwd = null)
    {
        $process = new self($command, $cwd);
        $process->closeChildStdin();

        $output = '';
        do {
            $process->waitForRead();
            $output .= fread($process->child_stdout, 2048);
        } while (!feof($process->child_stdout));
        $process->closeWithErrorCheck();

        return $output;
    }

    public static function readLines($command, $cwd = null)
    {
        $process = new self($command, $cwd);
        $process->closeChildStdin();
        return new Process_LineProcess($process);
    }

    // Note: Will probably completely change this constructor in the future,
    //       so it really should be private.
    private function __construct($command, $cwd = null, $env = null, $timeout = 60, $options = array()) {
        $descriptorspec = array(
           0 => array("pipe", "r"),
           1 => array("pipe", "w"),
           2 => array("pipe", "w"),
        );

        $this->process = proc_open($command, $descriptorspec,
            $pipes, $cwd, $env, $options);

        if ($this->process === false) {
            throw new Process_Exception("Error running command");
        }

        $this->child_stdin = $pipes[0];
        $this->child_stdout = $pipes[1];
        $this->child_stderr = $pipes[2];

        // TODO: Probably should set streams to nonblocking. But
        //       need to be more careful about how I use them. For
        //       example, calls to 'fgets' and 'fwrite' elsewhere
        //       in this file.
        // stream_set_blocking($this->child_stdin, false);
        // stream_set_blocking($this->child_stdout, false);
        // stream_set_blocking($this->child_stderr, false);

        if (!is_null($timeout)) {
            $this->timeout_at = microtime(true) + $timeout;
        }
    }

    function join() {
        if ($this->process && $this->child_stdout) do {
            $this->waitForRead();
            fread($this->child_stdout, 2048);
        } while (!feof($this->child_stdout));
        $this->close();
    }

    function close() {
        if ($this->process) {
            $this->closeChildStdin();
            $this->closeChildStdout();
            if ($this->child_stderr) do {
                $this->waitForRead();
                fread($this->child_stderr, 2048);
            } while (!feof($this->child_stderr));
            $this->closeChildStderr();

            $this->status = proc_close($this->process);
            $this->process = null;
        }
    }

    function closeWithErrorCheck() {
        $this->close();
        if ($this->status != 0) {
            throw new Process_FailedExitCode(
                "Process failed({$this->status})",
                $this->stderr
            );
        }
    }

    function closeChildStdin() {
        if ($this->child_stdin) {
            fclose($this->child_stdin);
            $this->child_stdin = null;
        }
    }

    function closeChildStdout() {
        if ($this->child_stdout) {
            fclose($this->child_stdout);
            $this->child_stdout = null;
        }
    }

    function closeChildStderr() {
        if ($this->child_stderr) {
            fclose($this->child_stderr);
            $this->child_stderr = null;
        }
    }

    function waitForRead() {
        while($this->child_stdout) {
            $read = array($this->child_stdout, $this->child_stderr);
            $write = array();
            $except = array();
            if (is_null($this->timeout_at)) {
                $count = stream_select($read, $write, $except, null);
            }
            else {
                $remain = $this->timeout_at - microtime(true);
                if ($remain <= 0) { $remain = 0; }
                $remain_int = floor($remain);
                $count = stream_select($read, $write, $except,
                    $remain_int, floor(($remain - $remain_int) * 1000000));
                if ($this->timeout_at <= microtime(true)) {
                    proc_terminate($this->process);
                    throw new Process_Timeout("Timeout in process");
                }
            }
            if (in_array($this->child_stderr, $read)) {
                $output = fread($this->child_stderr, 2048);
                $this->stderr .= $output;
            }
            if (in_array($this->child_stdout, $read)) {
                break;
            }
        }
    }
}

class Process_LineProcess implements Iterator
{
    var $process;
    var $line_count = 0;
    var $line = null;

    function __construct($process) {
        $this->process = $process;
        $this->readLine();
    }

    function rewind() {}

    function valid() {
        return ($this->line !== false);
    }

    function current() {
        return $this->line;
    }

    function key() {
        return $this->line_count;
    }

    function next() {
        $this->readLine();
        ++$this->line_count;
    }

    private function readLine() {
        $this->process->waitForRead();
        // TODO: I guess this might block if a full line isn't written out.
        //       Perhaps use a buffer?
        $line = fgets($this->process->child_stdout);
        if (is_string($line)) { $line = rtrim($line, "\r\n"); }
        $this->line = $line;
        if ($line === false) { $this->process->closeWithErrorCheck(); }
    }
}

class Process_Exception extends RuntimeException {}
class Process_Timeout extends Process_Exception {}

class Process_FailedExitCode extends Process_Exception
{
    var $stderr = '';

    function __construct($message, $stderr) {
        if ($stderr) { $message .= "\n\n{$stderr}"; }
        $this->stderr = $stderr;
        parent::__construct($message);
    }
}
