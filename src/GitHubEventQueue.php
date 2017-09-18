<?php

/*
 * Copyright 2013-2014 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

use Nette\Object;

class GitHubEventQueue extends Object {
    // Contains details and status of individual queues.
    static $queue_table = 'queue';

    var $queue;
    var $type;
    var $queue_pos;
    var $queue_end;

    function __construct($name, $type = null) {
        $db = EvilGlobals::database();
        $this->queue = $db->findOne(self::$queue_table, 'name = ?', array($name));
        $this->type = $type;
        $this->queue_end = GitHubEvents::$status->last_id;
        if ($this->queue) {
            assert($this->queue->type === $type);
            $this->queue_pos = $this->queue->last_github_id;
        }
        else {
            $this->queue = $db->dispense(self::$queue_table);
            $this->queue->name = $name;
            $this->queue->type = $type;
            $this->queue->last_github_id = 0;
            $this->queue->store();
            $this->queue_pos = 0;
        }
    }

    function getEvents() {
        return GitHubEvents::getEvents($this->queue_pos, $this->queue_end, $this->type);
    }

    // Download and adds new events
    function downloadMoreEvents() {
        GitHubEvents::downloadEvents();
        $this->queue_end = GitHubEvents::$status->last_id;
    }

    function markAllRead() {
        $this->queue_pos = max(array(
            $this->queue_pos,
            $this->queue_end,
            GitHubEvents::$status->start_id));
        $this->queue->last_github_id = $this->queue_pos;
        $this->queue->store();
    }

    function markReadUpTo($github_id) {
        if ($github_id >= $this->queue_pos) {
            $this->queue_pos = $github_id;
        }
        $this->queue->last_github_id = $this->queue_pos;
        $this->queue->store();
    }

    function continuedFromLastRun() {
        return GitHubEvents::$status->start_id
            && $this->queue_pos >= GitHubEvents::$status->start_id;
    }
}
