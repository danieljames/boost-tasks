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
        'no_next_url' => Array(
            'body' => Array('one'),
        ),
    );

    function get($url) {
        if (array_key_exists($url, $this->requests)) {
            $x = $this->requests[$url];
            if (is_array($x)) {
                $x = (object) array_merge(array('next_url' => ''), $x);
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

class MockInvalidCache {
    var $requests = Array(
        'null_response' => 'null',
        'number_response' => '25',
        'string_response' => '"[1,2,3]"',
        'object_response' => '{"a":1,"b":2}',
        'invalid_json_response' => '{a:1,b:2}',
    );

    function get($url) {
        if (array_key_exists($url, $this->requests)) {
            $x = new stdClass();
            $x->body = $this->requests[$url];
            return $x;
        }
        else {
            throw new \RuntimeException("Pretend this is a 404, okay?");
        }
    }
}

// Just to trigger GitHubCache autoload, so that
// GitHubCache_Iterator is loaded.
new GitHubCache();

class GitHubCache_IteratorTest extends TestBase {
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

    function testNoNextUrl() {
        $x = new GitHubCache_Iterator(new MockCache, 'no_next_url');
        Assert::true($x->valid());
        Assert::same('one', $x->current());
        $x->next();
        Assert::false($x->valid());
    }

    function testInvalidResponses() {
        $mock_cache = new MockInvalidCache();
        foreach(array_keys($mock_cache->requests) as $request) {
            $x = new GitHubCache_Iterator($mock_cache, $request);
            Assert::exception(function() use($x) {
                $x->valid();
            }, 'RuntimeException');
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
