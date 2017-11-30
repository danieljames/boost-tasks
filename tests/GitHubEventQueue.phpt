<?php

use Tester\Assert;
use BoostTasks\GitHubEvents;
use BoostTasks\GitHubEventQueue;

require_once(__DIR__.'/bootstrap.php');

class GitHubEventQueueTest extends TestBase
{
    function testConstruct() {
        $queue1 = new GitHubEventQueue('test');
        $queue2 = new GitHubEventQueue('test');
        Assert::equal($queue1->queue->id, $queue2->queue->id);

        $queue3 = new GitHubEventQueue('test2');
        Assert::notEqual($queue1->queue->id, $queue3->queue->id);
    }

    function testGetEvents() {
        $events = array();
        array_unshift($events, new MockEvent('PushEvent', 'refs/heads/master', 'example/foo'));
        // PullRequestEvent isn't recordered, so shouldn't turn up in the test results.
        array_unshift($events, new MockEvent('PullRequestEvent', 'refs/heads/develop', 'example/foo'));
        array_unshift($events, new MockEvent('CreateEvent', 'boost-1.61.0', 'example/bar'));
        GitHubEvents::downloadEventsImpl($events);

        $queue1 = new GitHubEventQueue('test1', 'PushEvent');
        $all1 = new GitHubEventQueue('all1');

        // Shouldn't be included in $queue1 yet.
        array_unshift($events, new MockEvent('PushEvent', 'refs/heads/develop', 'example/bar'));
        GitHubEvents::downloadEventsImpl($events);

        Assert::false($queue1->continuedFromLastRun());
        $events1 = $queue1->getEvents();
        Assert::same(1, count($events1));
        Assert::same('1', $events1[0]->github_id);
        Assert::same('master', $events1[0]->branch);
        Assert::same('example/foo', $events1[0]->repo);
        Assert::same('PushEvent', $events1[0]->type);
        $queue1->markAllRead();

        Assert::false($all1->continuedFromLastRun());
        $events1 = $all1->getEvents();
        Assert::same(2, count($events1));
        Assert::same('1', $events1[0]->github_id);
        Assert::same('master', $events1[0]->branch);
        Assert::same('example/foo', $events1[0]->repo);
        Assert::same('PushEvent', $events1[0]->type);
        Assert::same('3', $events1[1]->github_id);
        Assert::same(null, $events1[1]->branch);
        Assert::same('example/bar', $events1[1]->repo);
        Assert::same('CreateEvent', $events1[1]->type);
        $all1->markAllRead();

        $queue1a = new GitHubEventQueue('test1', 'PushEvent');
        Assert::true($queue1a->continuedFromLastRun());
        $events2 = $queue1a->getEvents();
        Assert::same(1, count($events2));
        Assert::same('4', $events2[0]->github_id);
        Assert::same('develop', $events2[0]->branch);
        Assert::same('example/bar', $events2[0]->repo);

        $all1a = new GitHubEventQueue('all1');
        Assert::true($all1a->continuedFromLastRun());
        $events2 = $all1a->getEvents();
        Assert::same(1, count($events2));
        Assert::same('4', $events2[0]->github_id);
        Assert::same('develop', $events2[0]->branch);
        Assert::same('example/bar', $events2[0]->repo);

        $queue2 = new GitHubEventQueue('test2', 'PushEvent');
        Assert::false($queue2->continuedFromLastRun());
        $events2 = $queue2->getEvents();
        Assert::same(2, count($events2));
        Assert::same('1', $events2[0]->github_id);
        Assert::same('4', $events2[1]->github_id);

        $all2 = new GitHubEventQueue('all2');
        Assert::false($all2->continuedFromLastRun());
        $events2 = $all2->getEvents();
        Assert::same(3, count($events2));
        Assert::same('1', $events2[0]->github_id);
        Assert::same('3', $events2[1]->github_id);
        Assert::same('4', $events2[2]->github_id);

        $queue3 = new GitHubEventQueue('test3', 'CreateEvent');
        Assert::false($queue3->continuedFromLastRun());
        $events3 = $queue3->getEvents();
        Assert::same(1, count($events3));
        Assert::same('3', $events3[0]->github_id);
    }
}

class MockEvent {
    var $id;
    var $type;
    var $payload;       // ->ref
    var $repo;          // ->name
    var $created_at;

    static $generate_id = 0;
    static $created = null;

    function __construct($type, $ref, $repo_name) {
        if (is_null(self::$created)) { self::$created = strtotime('1 Jul 2016'); }

        $this->id = ++self::$generate_id;
        $this->type = $type;
        $this->payload = new StdClass;
        $this->payload->ref = $ref;
        $this->repo = new StdClass;
        $this->repo->name = $repo_name;
        $this->created_at = date(DATE_W3C, self::$created += 100);
    }
}

$test = new GitHubEventQueueTest();
$test->run();
