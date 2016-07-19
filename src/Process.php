<?php

/*
 * Copyright 2013-2016 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

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
        $process->close_child_stdin();
        $process->join();
        $process->close_with_error_check();
    }

    public static function status($command, $cwd = null)
    {
        $process = new self($command, $cwd);
        $process->close_child_stdin();
        $process->join();
        $process->close();
        return $process->status;
    }

    public static function read($command, $cwd = null)
    {
        $process = new self($command, $cwd);
        $process->close_child_stdin();

        $output = '';
        do {
            $process->wait_for_read();
            $output .= fread($process->child_stdout, 2048);
        } while (!feof($process->child_stdout));
        $process->close_with_error_check();

        return $output;
    }

    public static function read_lines($command, $cwd = null)
    {
        $process = new self($command, $cwd);
        $process->close_child_stdin();
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
            throw new \RuntimeException("Error running command");
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
            $this->wait_for_read();
            fread($this->child_stdout, 2048);
        } while (!feof($this->child_stdout));
        $this->close();
    }

    function close() {
        if ($this->process) {
            $this->close_child_stdin();
            $this->close_child_stdout();
            if ($this->child_stderr) do {
                $this->wait_for_read();
                fread($this->child_stderr, 2048);
            } while (!feof($this->child_stderr));
            $this->close_child_stderr();

            $this->status = proc_close($this->process);
            $this->process = null;
        }
    }

    function close_with_error_check() {
        $this->close();
        if ($this->status != 0) {
            throw new Process_Exception(
                "Process failed({$this->status})",
                $this->stderr
            );
        }
    }

    function close_child_stdin() {
        if ($this->child_stdin) {
            fclose($this->child_stdin);
            $this->child_stdin = null;
        }
    }

    function close_child_stdout() {
        if ($this->child_stdout) {
            fclose($this->child_stdout);
            $this->child_stdout = null;
        }
    }

    function close_child_stderr() {
        if ($this->child_stderr) {
            fclose($this->child_stderr);
            $this->child_stderr = null;
        }
    }

    function wait_for_read() {
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
        $this->read_line();
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
        $this->read_line();
        ++$this->line_count;
    }

    private function read_line() {
        $this->process->wait_for_read();
        // TODO: I guess this might block if a full line isn't written out.
        //       Perhaps use a buffer?
        $line = fgets($this->process->child_stdout);
        if (is_string($line)) { $line = rtrim($line, "\r\n"); }
        $this->line = $line;
        if ($line === false) { $this->process->close_with_error_check(); }
    }
}

class Process_Exception extends \RuntimeException
{
    var $stderr = '';

    function __construct($message, $stderr) {
        parent::__construct($message);
        $this->stderr = $stderr;
    }
}

class Process_Timeout extends \RuntimeException
{
}
