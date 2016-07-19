<?php

use Tester\Assert;
use BoostTasks\Db;

require_once(__DIR__.'/bootstrap.php');

class GitHubCacheTest extends TestBase {
    function setup() {
        parent::setup();
        EvilGlobals::$instance->database = Db::create("sqlite::memory:");
        Migrations::migrate(EvilGlobals::$instance->database);
    }

    function testGetFile() {
        $cache = new GitHubCache();
        $file_url = 'file://'.__FILE__;
        $file = $cache->get($file_url);
        Assert::same($file_url, $file->url);
        Assert::null($file->next_url);
        Assert::null($file->etag);
        Assert::same(file_get_contents(__FILE__), $file->body);
    }

    function testGet() {
        $cache = new GitHubCache();
        $cache->connection = new MockConnection();
        $one = $cache->get('one.html');
        Assert::same('https://api.github.com/one.html', $one->url);
        Assert::null($one->next_url);
        Assert::same("One\n", $one->body);

        $two = $cache->get('two.html');
        Assert::same('https://api.github.com/two.html', $two->url);
        // TODO: Should resolve the link header.
        Assert::same('two2.html', $two->next_url);
        Assert::same("[1]", $two->body);
    }

    function testGetWithEtag() {
        $cache = new GitHubCache();
        $mock_connection = new MockConnection();
        $cache->connection = $mock_connection;
        $one = $cache->get('one.html');
        Assert::same(array(), $mock_connection->last_headers);
        Assert::same("One\n", $one->body);
        $one = $cache->get('one.html');
        Assert::same(array('If-None-Match: abcdefg'), $mock_connection->last_headers);
        Assert::same("One\n", $one->body);
    }

    function testGetWithRedirect() {
        $cache = new GitHubCache();
        $mock_connection = new MockConnection();
        $cache->connection = $mock_connection;
        $one = $cache->get('one_redirect.html');
        Assert::same('https://api.github.com/one.html', $one->url);
        Assert::same("One\n", $one->body);

        Assert::exception(function() use($cache) {
            $cache->get('redirect_loop1');
        }, 'RuntimeException', '#redirect#');

        Assert::exception(function() use($cache) {
            $cache->get('redirect_loop2');
        }, 'RuntimeException', '#redirect#');

        Assert::exception(function() use($cache) {
            $cache->get('redirect_loop3');
        }, 'RuntimeException', '#redirect#');
    }

    function testGet404() {
        $cache = new GitHubCache();
        $cache->connection = new MockConnection();
        Assert::Exception(function() use($cache) {
            $cache->get('non-existant.html');
        }, 'RuntimeException');
    }

    function testGetJson() {
        $cache = new GitHubCache();
        $cache->connection = new MockConnection();
        Assert::equal((object) array('thing' => 10), $cache->getJson('json'));
        Assert::null($cache->getJson('json_null'));
        Assert::exception(function() use($cache) {
            $cache->getJson('one.html');
        }, 'RuntimeException');
    }

    function testIterator() {
        $cache = new GitHubCache();
        $cache->connection = new MockConnection();
        $values = iterator_to_array($cache->iterate('two.html'));
        Assert::same(array(1,2), $values);
    }

    function testResolveUrl() {
        $cache = new GitHubCache();
        Assert::same('http://www.example.com/', $cache->resolve_url('http://www.example.com/'));
        Assert::same('https://www.example.com/', $cache->resolve_url('//www.example.com/'));
        Assert::same('https://www.example.com/foobar.html', $cache->resolve_url('//www.example.com/foobar.html'));
        Assert::same('https://www.example.com/foobar.html?a=b', $cache->resolve_url('//www.example.com/foobar.html?a=b'));
        Assert::same('http://api.github.com/thing.html', $cache->resolve_url('http:thing.html'));
        Assert::same('http://api.github.com/thing.html', $cache->resolve_url('http:/thing.html'));
        Assert::same('https://api.github.com/foobar.html', $cache->resolve_url('/foobar.html'));
        Assert::same('https://api.github.com/foo/bar.html', $cache->resolve_url('foo/bar.html'));
        Assert::same('https://api.github.com/foobar.html?a=b', $cache->resolve_url('foobar.html?a=b'));
        Assert::same('https://api.github.com/.', $cache->resolve_url('.'));
    }
}

class MockConnection {
    var $responses = array(
        'https://api.github.com/one_redirect.html' => array(
            'code' => 301,
            'reason_phrase' => 'Moved',
            'headers' => array('location' => 'https://api.github.com/one.html'),
            'body' => "",
        ),
        'https://api.github.com/redirect_loop1' => array(
            'code' => 301,
            'headers' => array('location' => 'https://api.github.com/redirect_loop1'),
        ),
        'https://api.github.com/redirect_loop2' => array(
            'code' => 301,
            'headers' => array('location' => 'https://api.github.com/redirect_loop1'),
        ),
        'https://api.github.com/redirect_loop3' => array(
            'code' => 301,
            'headers' => array('location' => 'https://api.github.com/redirect_loop3a'),
        ),
        'https://api.github.com/redirect_loop3a' => array(
            'code' => 301,
            'headers' => array('location' => 'https://api.github.com/redirect_loop3'),
        ),
        'https://api.github.com/one.html' => array(
            'code' => 200,
            'reason_phrase' => 'blah blah blah',
            'headers' => array('etag' => 'abcdefg'),
            'body' => "One\n",
        ),
        'https://api.github.com/two.html' => array(
            'code' => 200,
            'reason_phrase' => 'blah blah blah',
            'headers' => array('link' => array(array('url' => 'two2.html', 'rel' => 'next'))),
            'body' => "[1]",
        ),
        'https://api.github.com/two2.html' => array(
            'code' => 200,
            'reason_phrase' => 'blah blah blah',
            'headers' => array(),
            'body' => "[2]",
        ),
        'https://api.github.com/json' => array(
            'code' => '200',
            'reason_phrase' => 'Success',
            'headers' => array(),
            'body' => '{"thing":10}',
        ),
        'https://api.github.com/json_null' => array(
            'code' => '200',
            'reason_phrase' => 'Success',
            'headers' => array(),
            'body' => 'null',
        ),
        404 => array(
            'code' => 404,
            'reason_phrase' => 'not found',
            'headers' => array(),
            'body' => 'not found',
        ),
    );
    var $last_headers;

    function get($url, $headers = array()) {
        $this->last_headers = $headers;

        $response_values = array_get($this->responses, $url) ?: $this->responses[404];

        if (!empty($response_values['headers']['etag'])) {
            $etag = $response_values['headers']['etag'];
            foreach($headers as $header) {
                if (preg_match('@^If-None-Match:\s*(.*)$@', $header, $match)) {
                    if ($match[1] === $etag) {
                        $response = new GitHubCache_Response();
                        $response->code = 304;
                        return $response;
                    }
                }
            }
        }

        $response = new GitHubCache_Response();
        foreach($response_values as $key => $value) {
            $response->{$key} = $value;
        }
        return $response;
    }
}

class GitHubCache_ConnectionTest extends TestBase {
    function testGet() {
        $connection = new GitHubCache_Connection();
        $response = $connection->get('file://'.__FILE__);
        Assert::same('200', $response->code);
        Assert::same(file_get_contents(__FILE__), $response->body);
    }

    function testParseResponse() {
        $response = GitHubCache_Connection::parse_response(
"HTTP/1.1 200 OK\r
Date: Tue, 19 Jul 2016 12:00:00 GMT\r
Server: Apache/2.2.15 (Red Hat)\r
Accept-Ranges: bytes\r
Connection: close\r
Content-Type: text/html",
"Line 1\r\nLine 2"
        );
        Assert::same('200', $response->code);
        Assert::same('OK', $response->reason_phrase);
        Assert::same('text/html', $response->headers['content-type']);
        Assert::same("Line 1\nLine 2", $response->body);
    }

    function testParseInvalidResponse() {
        Assert::exception(function() {
            GitHubCache_Connection::parse_response("Blah blah", "Blah blah");
        }, 'RuntimeException');
    }

    function testMessageHeaders() {
        Assert::same(
            array(
                'date' => 'Tue, 19 Jul 2016 08:58:40 GMT',
                'server' => 'Apache/2.2.15 (Red Hat)',
                'accept-ranges' => 'bytes',
                'connection' => 'close',
                'content-type' => 'text/html',
                'link' => array(array('url' => 'www.example.com', 'rel' => 'something')),
            ),
            GitHubCache_Connection::parse_message_headers(
'Date: Tue, 19 Jul 2016 08:58:40 GMT
Server: Apache/2.2.15 (Red Hat)
Accept-Ranges: bytes
Connection: close
Content-Type: text/html
Link: <www.example.com>;rel=something'
            )
        );
    }

    function testDuplicateMessageHeaders() {
        Log::$log = new \Monolog\Logger('test logger');
        $handler = new \Monolog\Handler\TestHandler;
        Log::$log->setHandlers(array($handler));

        Assert::same(
            array(
                'val' => '1',
            ),
            GitHubCache_Connection::parse_message_headers(
'Val: 1
Error thing'
            )
        );

        Assert::true($handler->hasRecordThatContains(
            'Error parsing http header', \Monolog\Logger::ERROR
        ));
    }

    function testParseLinkHeader() {
        Assert::same(
            array(array(
                'url' => 'http://www.cern.ch/TheBook/chapter2',
                'rel' => 'Previous',
            )),
            GitHubCache_Connection::parse_link_header(
                '<http://www.cern.ch/TheBook/chapter2>; rel="Previous"'
            )
        );

        Assert::same(
            array(array(
                'url' => 'mailto:timbl@w3.org',
                'rev' => 'Made',
                'title' => 'Tim Berners-Lee',
            )),
            GitHubCache_Connection::parse_link_header(
                '<mailto:timbl@w3.org>; rev="Made"; title="Tim Berners-Lee"'
            )
        );

        Assert::same(
            array(
                array(
                    'url' => '../media/contrast.css',
                    'rel' => 'stylesheet alternate',
                    'title' => 'High Contrast Styles',
                    'type' => 'text/css',
                    'media' => 'screen',
                ),
                array(
                    'url' => '../media/print.css',
                    'rel' => 'stylesheet',
                    'type' => 'text/css',
                    'media' => 'print',
                ),
            ),
            GitHubCache_Connection::parse_link_header('
                 <../media/contrast.css>; rel="stylesheet alternate";
                 title="High Contrast Styles"; type="text/css"; media="screen",
                 <../media/print.css>; rel="stylesheet"; type="text/css";
                 media="print"
                 ')
        );

        Assert::same(
            array(
                array(
                    'url' => 'sec-12-glossary.xml',
                    'rel' => 'glossary',
                    'anchor' => '#sec12',
                ),
            ),
            GitHubCache_Connection::parse_link_header(
                '<sec-12-glossary.xml>; rel="glossary"; anchor="#sec12"'
            )
        );

        Assert::same(
            array(
                array(
                    'url' => 'link.html',
                ),
            ),
            GitHubCache_Connection::parse_link_header(
                '<link.html>'
            )
        );

        Assert::same(
            array(
                array(
                    'url' => 'link1.html',
                ),
                array(
                    'url' => 'link2.html',
                ),
            ),
            GitHubCache_Connection::parse_link_header(
                '<link1.html>,<link2.html>'
            )
        );

        Assert::same(
            array(
                array(
                    'url' => 'link.html',
                    'thing' => '";"',
                    'thing2' => '',
                    'thing3' => '',
                ),
            ),
            GitHubCache_Connection::parse_link_header(
                '<link.html> ; thing="\";\"" ; thing2="" ; thing3='
            )
        );
    }

    function testParseInvalidLinkHeader() {
        Assert::exception(function() {
            GitHubCache_Connection::parse_link_header('http://example.com/');
        }, 'RuntimeException', '#Error parsing link#');
    }

    function testParseLinkHeaderDuplicateKey() {
        Log::$log = new \Monolog\Logger('test logger');
        $handler = new \Monolog\Handler\TestHandler;
        Log::$log->setHandlers(array($handler));

        Assert::same(
            array(array(
                'url' => 'http://example.com/',
                'rel' => '1',
            )),
            GitHubCache_Connection::parse_link_header('<http://example.com/>;rel=1;rel=2')
        );

        Assert::true($handler->hasRecordThatContains(
            'Duplicate link key', \Monolog\Logger::ERROR
        ));
    }
}

$test = new GitHubCacheTest();
$test->run();
$test = new GitHubCache_ConnectionTest();
$test->run();
