<?php

use Guzzle\Http\Client;
use Nette\Object;

/**
 * Download github api pages using etags and stuff.
 */

class GitHubCache extends Object {
    static $table_name = 'githubcache';
    var $client;
    var $username;
    var $password;

    function __construct($username = null, $password = null) {
        $this->username = $username;
        $this->password = $password;
        $this->client = new Client('https://api.github.com');
        $this->client->setDefaultOption('headers/Accept', 'application/vnd.github.v3');
    }

    function get($url) {
        $request = $this->client->get($url);
        if ($this->username) {
            $request->setAuth($this->username, $this->password);
        }
        $full_url = $request->getUrl();

        $cached = R::findOne(self::$table_name, 'url = ?', array($full_url));
        if ($cached && $cached->etag) {
            $request->addHeader('If-None-Match', $cached->etag);
        }

        $response = $request->send();
        switch ($response->getStatusCode()) {
            case 200:
                \Log::debug("Fetched: {$url}");
                if (!$cached) {
                    $cached = R::dispense(self::$table_name);
                    $cached->url = $full_url;
                }

                $cached->next_url = null;
                if ($response->getHeader('link')) {
                    $next_link = $response->getHeader('link')->getLink('next');
                    if ($next_link) $cached->next_url = $next_link['url'];
                }

                if ($response->hasHeader('ETag')) {
                    $cached->etag = $response->getHeader('ETag')->__toString();
                    $cached->body = $response->getBody()->__toString();

                    R::store($cached);
                }

                break;
            case 304: // Unchanged
                \Log::debug("Cached: {$url}");
                assert($cached);
                break;
            case 301: // Permanent redirect.
            case 302: // Temporary redirect.
            case 307:
                // Hopefully guzzle will deal with redirects.
                assert(false);
            default:
                // TODO: Seems that guzzle throws errors for 4xx codes.
                if ($response->getBody()) {
                    throw new \RuntimeException(
                        json_decode($response->getBody()));
                } else {
                    throw new \RuntimeException(
                        $response->getReasonPhrase());
                }
        }

        return $cached;
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
        $this->fetch_to_line($this->line_index);
        return array_key_exists($this->line_index, $this->lines);
    }

    public function current()
    {
        $this->fetch_to_line($this->line_index);
        return $this->lines[$this->line_index];
    }

    public function key()
    {
        return $this->line_index;
    }

    public function next()
    {
        $this->fetch_to_line($this->line_index + 1);
        $this->line_index = $this->line_index + 1;
    }

    private function fetch_to_line($line_index) {
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
            $this->next_url = property_exists($response, 'next_url') ?
                $response->next_url : null;
        }
    }
}
