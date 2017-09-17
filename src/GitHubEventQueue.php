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
    static $status;

    function __construct($name, $type = null) {
        $db = EvilGlobals::database();
        self::loadStatusFromDb($db);
        $this->queue = $db->findOne(self::$queue_table, 'name = ?', array($name));
        $this->type = $type;
        if ($this->queue) {
            assert($this->queue->type === $type);
        }
        else {
            $this->queue = $db->dispense(self::$queue_table);
            $this->queue->name = $name;
            $this->queue->type = $type;
            $this->queue->last_github_id = 0;
            $this->queue->store();
        }
    }

    function getEvents() {
        if (!$this->type) {
            return EvilGlobals::database()->find(self::$event_table,
                    'github_id > ? AND github_id <= ? ORDER BY github_id',
                    array(
                        $this->queue->last_github_id,
                        self::$status->last_id));
        } else {
            return EvilGlobals::database()->find(self::$event_table,
                    'github_id > ? AND github_id <= ? AND type = ? ORDER BY github_id',
                    array(
                        $this->queue->last_github_id,
                        self::$status->last_id,
                        $this->type));
        }
    }

    // Downloads any events since this was created, and updates getEvents
    // to return them.
    function downloadMoreEvents() {
        $this->queue->last_github_id = max(array(
            $this->queue->last_github_id,
            self::$status->last_id,
            self::$status->start_id));
        self::downloadEvents();
    }

    function catchUp() {
        $this->queue->last_github_id = max(array(
            $this->queue->last_github_id,
            self::$status->last_id,
            self::$status->start_id));
        $this->queue->store();
    }

    function markReadUpTo($github_id) {
        $this->queue->last_github_id = max(array(
            $this->queue->last_github_id,
            $github_id));
        $this->queue->store();
    }

    function continuedFromLastRun() {
        return self::$status->start_id
            && $this->queue->last_github_id >= self::$status->start_id;
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
            $event_row = self::addGitHubEvent($event);
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

    private static function addGitHubEvent($event) {
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

        $db = EvilGlobals::database();

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
