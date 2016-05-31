<?php

use Tester\Assert;

require_once(__DIR__.'/bootstrap.php');

class MockCache {
    var $requests = Array(
        'empty' => Array(
            'body' => Array(),
            'next_url' => null,
        ),
        'one' => Array(
            'body' => Array('hello'),
            'next_url' => null,
        ),
        'two' => Array(
            'body' => Array('one', 'two'),
            'next_url' => null,
        ),
        'two_pages' => Array(
            'body' => Array('one', 'two'),
            'next_url' => 'two_pages2',
        ),
        'two_pages2' => Array(
            'body' => Array('three', 'four'),
            'next_url' => null,
        ),
        'error' => "error message",
        'error_on_page2' => Array(
            'body' => Array('one', 'two'),
            'next_url' => 'error_on_page2_2',
        ),
        'error_on_page2_2' => "error message",
    );

    function get($url) {
        if (array_key_exists($url, $this->requests)) {
            $x = $this->requests[$url];
            if (is_array($x)) {
                $x = (object) $x;
                $x->body = json_encode($x->body);
                return $x;
            }
            else {
                throw new \RuntimeException($x);
            }
        }
        else {
            throw new \RuntimeException("Pretend this is a 404, okay?");
        }
    }
}

class GitHubCache_IteratorTest extends Tester\TestCase {
    function testEmpty() {
        $x = new GitHubCache_Iterator(new MockCache, 'empty');
        Assert::false($x->valid());
        $x->rewind();
        Assert::false($x->valid());

        $this->compare(array(), $x);
    }

    function testOneEntry() {
        $x = new GitHubCache_Iterator(new MockCache, 'one');
        Assert::true($x->valid());
        Assert::same('hello', $x->current());
        $x->next();
        Assert::false($x->valid());
        $x->rewind();
        Assert::true($x->valid());
        Assert::same('hello', $x->current());

        $this->compare(array('hello'), $x);
    }

    function testTwoEntries() {
        $x = new GitHubCache_Iterator(new MockCache, 'two');
        $this->compare(array('one', 'two'), $x);
    }

    function testTwoPages() {
        $x = new GitHubCache_Iterator(new MockCache, 'two_pages');
        $this->compare(array('one', 'two', 'three', 'four'), $x);
    }

    function testError() {
        $x = new GitHubCache_Iterator(new MockCache, 'error');
        Assert::exception(function() use($x) {
            $x->valid();
        }, 'RuntimeException');
    }

    function testErrorOnPageTwo() {
        $x = new GitHubCache_Iterator(new MockCache, 'error_on_page2');

        for ($i = 0; $i < 2; ++$i) {
            Assert::true($x->valid());
            Assert::same(0, $x->key());
            Assert::same('one', $x->current());
            $x->next();
            Assert::true($x->valid());
            Assert::same(1, $x->key());
            Assert::same('two', $x->current());
            Assert::exception(function() use($x) {
                $x->next();
            }, 'RuntimeException');
            Assert::true($x->valid());
            Assert::same(1, $x->key());
            Assert::same('two', $x->current());
            $x->rewind();
        }
    }

    function testVariousArrays() {
        for($i = 0; $i < 20; ++$i) {
            $this->checkArray(range(0, $i), 5);
        }

        for($i = 1; $i < 1000; $i *= 2) {
            $this->checkArray(range(0, $i), 20);
        }
    }

    function checkArray($array, $page_size) {
        $x = new GitHubCache_Iterator($this->mockArray('test', $array, $page_size), 'test');
        $this->compare($array, $x);
        $this->compare($array, $x);
    }

    function mockArray($url, $array, $page_size = 4) {
        $x = new MockCache();

        $pages = array($url => Array('body' => null, 'next_url' => null));
        $last_page = null;
        foreach(array_chunk($array, $page_size) as $index => $chunk) {
            $current_page = $url.($index ? "_{$index}" : '');
            $pages[$current_page] = array(
                'body' => $chunk,
                'next_url' => null,
            );
            if ($last_page) { $pages[$last_page]['next_url'] = $current_page; }
            $last_page = $current_page;
        }
        $x->requests = $pages;

        return $x;
    }

    function compare($array, $x) {
        $result = array();

        foreach($x as $key => $value) {
            Assert::false(array_key_exists($key, $result));
            $result[$key] = $value;
        }

        Assert::same($array, $result);
    }
}

$test = new GitHubCache_IteratorTest();
$test->run();
