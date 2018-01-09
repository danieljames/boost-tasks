<?php

namespace BoostTasks;

use Nette\Object;
use BoostTasks\Settings;
use BoostTasks\Log;
use RuntimeException;
use Iterator;

/**
 * Download github api pages using etags and stuff.
 */

class GitHubCache extends Object {
    static $table_name = 'githubcache';
    var $username;
    var $password;
    var $connection;

    function __construct($username = null, $password = null) {
        $this->username = $username;
        $this->password = $password;
        $this->connection = new GitHubCache_Connection($this->username, $this->password);
    }

    function get($url) {
        $full_url = $this->resolveUrl($url);
        Log::debug("Fetch: {$full_url}");
        $db = Settings::database();
        $redirect_count = 0;

    repeat_fetch:

        if (++$redirect_count > 10) {
            throw new RuntimeException("Too many redirects");
        }

        $headers = array();
        $cached = $db->findOne(self::$table_name, 'url = ?', array($full_url));
        if ($cached && $cached->etag) {
            $headers[] = 'If-None-Match: '.$cached->etag;
        }

        $response = $this->connection->get($full_url, $headers);

        switch ($response->code) {
            case 200:
                if (!$cached) {
                    $cached = $db->dispense(self::$table_name);
                    $cached->url = $full_url;
                }

                $cached->body = $response->body;
                $cached->next_url = null;

                if (array_get($response->headers, 'link')) {
                    foreach($response->headers['link'] as $link) {
                        // TODO: What if rel has multiple values?
                        if (array_get($link, 'rel') == 'next') {
                            $cached->next_url = $link['url'];
                        }
                    }
                }

                if (array_get($response->headers, 'etag')) {
                    // TODO: Parse etag string?
                    $cached->etag = array_get($response->headers, 'etag');

                    $db->store($cached);
                }

                break;
            case 304: // Unchanged
                Log::debug("Cached: {$url}");
                assert($cached);
                break;
            case 301: // Permanent redirect.
            case 302: // Temporary redirect.
            case 307:
                if (!$response->headers || !array_key_exists('location', $response->headers)) {
                    throw new RuntimeException('Redirect with no location header');
                }
                $full_url = $response->headers['location'];
                if (!preg_match('@^https?://[^/?#]+/@', $full_url, $matches)) {
                    throw new RuntimeException('Location header isn\'t absolute http URL');
                }
                goto repeat_fetch;
            default:
                $message = $response->reason_phrase;
                if ($response->body) { $message .= "\n {$response->body}"; }
                throw new RuntimeException($message);
        }

        return $cached;
    }

    function resolveUrl($url) {
        // Regexp from https://tools.ietf.org/html/rfc3986#appendix-B
        if (!preg_match('@^(([^:/?#]+):)?(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?@', $url, $matches)) {
            throw new RuntimeException("Invalid URL: {$url}");
        }
        $full_url = $matches[1] ?: 'https:';
        $full_url .= $matches[3] ?: '//api.github.com';
        $full_url .= '/'.ltrim($matches[5], '/');
        if (!empty($matches[6])) { $full_url .= $matches[6]; }
        if (!empty($matches[8])) { $full_url .= $matches[8]; }
        return $full_url;
    }


    function getJson($url) {
        $response = $this->get($url);
        $response_body = \json_decode(trim($response->body));
        if (is_null($response_body) && strtolower(trim($response->body)) !== 'null') {
            throw new \RuntimeException("Invalid json from github");
        }
        return $response_body;
    }

    function iterate($url) {
        return new GitHubCache_Iterator($this, $url);
    }
}

class GitHubCache_Iterator extends Object implements Iterator
{
    /** @var GitHubCache */
    private $cache;
    /** @var string */
    private $next_url;
    /** @var array */
    private $lines = null;
    /** @var int */
    private $line_index = 0;

    /**
     * @param GitHubCache $cache
     * @param string $url
     */
    function __construct($cache, $url)
    {
        $this->cache = $cache;
        $this->next_url = $url;
    }

    function rewind()
    {
        $this->line_index = 0;
    }

    public function valid()
    {
        $this->fetchToLine($this->line_index);
        return array_key_exists($this->line_index, $this->lines);
    }

    public function current()
    {
        $this->fetchToLine($this->line_index);
        return $this->lines[$this->line_index];
    }

    public function key()
    {
        return $this->line_index;
    }

    public function next()
    {
        $this->fetchToLine($this->line_index + 1);
        $this->line_index = $this->line_index + 1;
    }

    private function fetchToLine($line_index) {
        while ($line_index >= count($this->lines) && $this->next_url) {
            $response = $this->cache->get($this->next_url);
            $response_body = \json_decode(trim($response->body));
            if (!is_array($response_body)) {
                throw new \RuntimeException("Invalid github response");
            }
            $this->lines = array_merge($this->lines ?: array(),
                $response_body);
            // Assuming that if there's no 'next_url' it's the end,
            // but maybe it should be an exception?
            $this->next_url = $response->next_url;
        }
    }
}

class GitHubCache_Connection {
    var $ch;
    var $default_headers;

    function __construct($username = null, $password = null) {
        $this->ch = curl_init();
        $this->default_headers = array(
            'User-Agent: Boost Commitbot',
            'Accept: application/vnd.github.v3',
        );
        curl_setopt($this->ch, CURLOPT_HEADER, true);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        if ($username) {
            curl_setopt($this->ch, CURLOPT_USERPWD, "{$username}:{$password}");
        }
    }

    function __destruct() {
        if ($this->ch) { curl_close($this->ch); }
        $this->ch = null;
    }

    function get($url, $headers = array()) {
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER,
            array_merge($this->default_headers, $headers));
        $response = curl_exec($this->ch);
        if ($response === false) {
            throw new RuntimeException("Curl error: ".curl_error($this->ch));
        }
        $header_size = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
        return self::parseResponse(
            substr($response, 0, $header_size),
            substr($response, $header_size));
    }

    static function parseResponse($header, $body) {
        $r = new GitHubCache_Response();
        $header = str_replace("\r\n", "\n", $header);
        $r->body = str_replace("\r\n", "\n", $body);

        if ($header) {
            if (!preg_match('@^HTTP/\d\.\d +(\d\d\d) +([^\r\n]*)\n(.*)$@si', $header, $matches)) {
                throw new RuntimeException("Error parsing HTTP response header");
            }
            $r->code = $matches[1];
            $r->reason_phrase = $matches[2];
            $r->headers = self::parseMessageHeaders($matches[3]);
        }
        else {
            $r->code = '200';
            $r->reason_phrase = '';
            $r->headers = array();
        }

        return $r;
    }

    static function parseMessageHeaders($header) {
        preg_match_all('@^([-_a-zA-Z0-9]+):(.*(?:[\n\r][ \t].*)*)$|([^\s].+)@m',
            $header, $matches, PREG_SET_ORDER);
        $headers = array();
        foreach($matches as $match) {
            if (!empty($match[3])) {
                Log::error("Error parsing http header: {$match[3]}");
            }
            else {
                // TODO: Clean up multi-line values.
                $key = strtolower($match[1]);
                $value = trim($match[2]);

                switch($key) {
                case 'link':
                    $headers[$key] = self::parseLinkHeader($value);
                    break;
                default:
                    $headers[$key] = $value;
                }
            }
        }
        return $headers;
    }

    static function parseLinkHeader($value) {
        $link_value = '"(?:\\\\.|[^"])*"|[^;"]*';
        $links = array();
        foreach(explode(',', $value) as $link_text) {
            if (!preg_match("@^\s*<([^>]*)>\s*((?:;\s*\w+\s*=\s*(?:{$link_value})\s*)*)\s*$@", $link_text, $matches)) {
                throw new RuntimeException("Error parsing link: {$link_text}");
            }
            $link = array('url' => $matches[1]);
            preg_match_all("@(\w+)\s*=\s*({$link_value})\s*(?:;|$)@", $matches[2], $matches, PREG_SET_ORDER);
            foreach($matches as $match) {
                if (array_key_exists($match[1], $link)) {
                    Log::error("Duplicate link key '{$match[1]}'");
                }
                else if (preg_match('@^"(.*)"$@', $match[2], $value)) {
                    $value = $value[1];
                    $value = preg_replace('@\\\\(.)@', '$1', $value);
                    $link[$match[1]] = $value;
                }
                else {
                    $link[$match[1]] = $match[2];
                }
            }
            $links[] = $link;
        }

        return $links;
    }
}

class GitHubCache_Response {
    var $code;
    var $reason_phrase;
    var $headers;
    var $body;
}
