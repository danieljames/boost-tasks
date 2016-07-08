<?php

use Tester\Assert;
use BoostTasks\Db;

require_once(__DIR__.'/../bootstrap.php');
require_once(__DIR__.'/../../webhook/webhook.php');

EvilGlobals::init(array('testing' => true));
EvilGlobals::$instance->database = Db::create("sqlite::memory:");
Migrations::migrate(EvilGlobals::database());

$event = new GitHubWebHookEvent();
$event->event_type = 'pull_request';
$event->payload = json_decode(file_get_contents(__DIR__.'/pull_request.json'));

$start = time();
webhook_pull_request_handler($event);
$end = time();

$events = EvilGlobals::database()->getAll('select * from `pull_request_event`');

// Check 'created_on' date, and then delete from results for safe comparison.
foreach($events as $index => $record) {
    $created_on = new DateTime($record['created_on']);
    Assert::true($created_on->getTimestamp() >= $start);
    Assert::true($created_on->getTimestamp() <= $end);
    unset($events[$index]['created_on']);
}

Assert::same(
    array(
        array(
            'id' => '1',
            'action' => 'opened',
            'repo_full_name' => 'baxterthehacker/public-repo',
            'pull_request_id' => '34778301',
            'pull_request_number' => '1',
            'pull_request_url' => 'https://github.com/baxterthehacker/public-repo/pull/1',
            'pull_request_title' => 'Update the README with new information',
            'pull_request_created_at' => '2015-05-05T23:40:27Z',
            'pull_request_updated_at' => '2015-05-05T23:40:27Z',
        ),
    ),
    $events
);
