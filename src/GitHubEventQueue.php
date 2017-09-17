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

    // Contains events pulled from GitHub.
    static $event_table = 'event';

    // Contains the overall state of the GitHub event queue.
    static $event_state_table = 'eventstate';

    var $queue;
    var $type;
    var $queue_pos;
    var $queue_end;
    static $status;

    function __construct($name, $type = null) {
        $db = EvilGlobals::database();
        self::loadStatusFromDb($db);
        $this->queue = $db->findOne(self::$queue_table, 'name = ?', array($name));
        $this->type = $type;
        $this->queue_end = self::$status->last_id;
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
        if (!$this->type) {
            return EvilGlobals::database()->find(self::$event_table,
                    'github_id > ? AND github_id <= ? ORDER BY github_id',
                    array(
                        $this->queue_pos,
                        $this->queue_end));
        } else {
            return EvilGlobals::database()->find(self::$event_table,
                    'github_id > ? AND github_id <= ? AND type = ? ORDER BY github_id',
                    array(
                        $this->queue_pos,
                        $this->queue_end,
                        $this->type));
        }
    }

    // Downloads any events since this was created, and updates getEvents
    // to return them.
    function downloadMoreEvents() {
        $this->queue_pos = max(array(
            $this->queue_pos,
            $this->queue_end,
            self::$status->start_id));
        self::downloadEvents();
        $this->queue_end = self::$status->last_id;
    }

    function markAllRead() {
        $this->queue_pos = max(array(
            $this->queue_pos,
            $this->queue_end,
            self::$status->start_id));
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
        return self::$status->start_id
            && $this->queue_pos >= self::$status->start_id;
    }

    static function outputEvents() {
        foreach(EvilGlobals::database()->findAll(self::$event_table) as $event) {
            echo "GitHub id: {$event->github_id}\n";
            echo "Type: {$event->type}\n";
            echo "Branch: {$event->branch}\n";
            echo "Repo: {$event->repo}\n";
            echo "Created: {$event->created}\n";
            echo "Payload: ";
            print_r(json_decode($event->payload));
            echo "\n";
        }
    }

    static function downloadEvents() {
        self::downloadEventsImpl(EvilGlobals::githubCache()->iterate('/orgs/boostorg/events'));
    }

    static function downloadEventsImpl($events) {
        $db = EvilGlobals::database();
        $db->begin();

        self::loadStatusFromDb($db);
        $last_id = self::$status->last_id;
        $new_last_id = null;
        $event_row = null;

        foreach($events as $event) {
            if ($event->id <= $last_id) { break; }
            if (!$new_last_id) { $new_last_id = $event->id; }
            $event_row = self::addGitHubEvent($db, $event);
        }

        if ($new_last_id) {
            // If we don't have a start_id, or there's a gap in the
            // event queue, set the start_id to the start of the events
            // that were just downloaded.
            if (!self::$status->start_id || $event->id > self::$status->last_id) {
                self::$status->start_id = $event->id;
                if ($event_row) {
                    $event_row->sequence_start = true;
                    $event_row->store();
                }
            }
            self::$status->last_id = $new_last_id;
            self::$status->store();
        }

        $db->commit();
    }

    private static function addGitHubEvent($db, $event) {
        switch ($event->type) {
        case 'PushEvent':
            if (!preg_match('@^refs/heads/(.*)$@',
                    $event->payload->ref, $matches)) { return; }
            $branch = $matches[1];
            break;
        case 'CreateEvent':
            // Tags don't have a branch...
            $branch = null;
            break;
        default:
            return;
        }

        if ($db->findOne(self::$event_table, 'github_id = ?', array($event->id))) {
            return;
        }

        $event_row = $db->dispense(self::$event_table);
        $event_row->github_id = $event->id;
        $event_row->type = $event->type;
        $event_row->branch = $branch;
        $event_row->repo = $event->repo->name;
        $event_row->payload = json_encode($event->payload);
        $event_row->created = new \DateTime($event->created_at);
        $event_row->store();
        return $event_row;
    }

    private static function loadStatusFromDb($db) {
        if (!self::$status) {
            $status = $db->findOne(self::$event_state_table, 'name = "github-state"');
            if (!$status) {
                $status = $db->dispense(self::$event_state_table);
                $status->start_id = 0;
                $status->last_id = 0;
                $status->name = 'github-state';
                $status->store();
            }
            self::$status = $status;
        }
    }
}
