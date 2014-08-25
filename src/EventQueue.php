<?php

/*
 * Copyright 2013-2014 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

class EventQueue {
    static $queue_table = 'queue';
    static $event_table = 'event';
    static $event_state_table = 'eventstate';
    static $event_queue_status;
    var $queue;

    function __construct($name) {
        $this->queue = R::findOne(self::$queue_table, 'name = ?', array($name));
        if (!$this->queue) {
            $this->queue = R::dispense(self::$queue_table);
            $this->queue->name = $name;
            $this->queue->last_github_id = 0;
            R::store($this->queue);
        }
    }

    function getEvents() {
        return R::find(self::$event_table,
                'github_id > ? ORDER BY github_id',
                array($this->queue->last_github_id));
    }

    function catchUp() {
        $status = self::getStatus();
        $this->queue->last_github_id = max(array(
            $this->queue->last_github_id,
            $status->last_id,
            $status->start_id));
        R::store($this->queue);
    }

    function continuedFromLastRun() {
        $status = self::getStatus();
        return $status->start_id
            && $this->queue->last_github_id >= $status->start_id;
    }

    static function downloadEvents() {
        $status = self::getStatus();
        $last_id = $status->last_id;
        $new_last_id = null;

        foreach(EvilGlobals::$github_cache->iterate('/orgs/boostorg/events')
                as $event) {
            if ($event->id <= $last_id) { break; }
            if (!$new_last_id) { $new_last_id = $event->id; }
            self::addGitHubEvent($event);
        }

        if ($new_last_id) {
            // If we don't have a start_id, or there's a gap in the
            // event queue, set the start_id to the start of the events
            // that were just downloaded.
            if (!$status->start_id || $event->id > $status->last_id) {
                $status->start_id = $event->id;
                $event->sequence_start = true;
                R::store($event);
            }
            $status->last_id = $new_last_id;
            R::store($status);
        }
    }

    private static function addGitHubEvent($event) {
        if ($event->type != 'PushEvent') { return; }

        if (!preg_match('@^refs/heads/(.*)$@',
                $event->payload->ref, $matches)) { return; }
        $branch = $matches[1];

        if (R::findOne(self::$event_table, 'github_id = ?', array($event->id))) {
            return;
        }

        $event_row = R::dispense(self::$event_table);
        $event_row->github_id = $event->id;
        $event_row->branch = $branch;
        $event_row->repo = $event->repo->name;
        $event_row->payload = json_encode($event->payload);
        $event_row->created = new \DateTime($event->created_at);
        R::store($event_row);
    }

    static function getStatus($force = false) {
        if (!self::$event_queue_status || $force) {
            self::$event_queue_status = R::findOne(self::$event_state_table, 'name = "github-state"');
            if (!self::$event_queue_status) {
                self::$event_queue_status = R::dispense(self::$event_state_table);
                self::$event_queue_status->start_id = 0;
                self::$event_queue_status->last_id = 0;
                self::$event_queue_status->name = 'github-state';
                R::store(self::$event_queue_status);
            }
        }

        return self::$event_queue_status;
    }
}
