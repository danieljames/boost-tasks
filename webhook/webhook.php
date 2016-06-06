<?php

require_once(__DIR__.'/../vendor/autoload.php');

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

function webhook() {
    header("Content-Type: text/plain");
    EvilGlobals::init();
    Log::$log->pushHandler(
        new StreamHandler(EvilGlobals::$data_root."/log.txt", Logger::INFO));
    $event = get_webhook_event();

    switch($event->event_type) {
    case 'push':
        webhook_push_handler($event);
        break;
    default:
        die("Unrecognized event: {$event->event_type}.\n");
    }
}

function webhook_push_handler($event) {
    // TODO: Move to configuration?
    $repos = array(
        'boost' => array(
            'refs/heads/master' => '/home/www/shared/repos/boost-master',
            'refs/heads/develop' => '/home/www/shared/repos/boost-develop',
        ),
        'website' => array(
            'refs/heads/master' => '/home/www/live.boost.org',
            'refs/heads/beta' => '/home/www/beta.boost.org',
        ),
        'build' => array(
           'refs/heads/website' => '/home/www/shared/build-site',
        ),
    );

    $payload = $event->payload;

    $repo_path = null;
    if (array_key_exists($payload->repository->name, $repos)) {
        $branches = $repos[$payload->repository->name];
    }
    else {
        return false;
    }

    if (array_key_exists($payload->ref, $branches)) {
        $repo_path = $branches[$payload->ref];
    }
    else {
        echo "Ignoring ref: ".htmlentities($payload->ref)."\n";
        mail('dnljms@gmail.com', "Not updateding ref: {$payload->ref}): ".date('j M Y'), print_r($payload, true));
        return false;
    }

    echo "Pulling...\n";

    $result = '';
    $result .= "Pull\n";
    $result .= "====\n";
    $result .= "\n";
    $result .= syscall('git stash', $repo_path);
    $result .= syscall('git pull -q', $repo_path);
    $result .= syscall('git stash pop', $repo_path);
    $result .= "\n";

    echo "Done, emailing results.\n";

    // Add the commit details.
    $result .= "Commits\n";
    $result .= "=======\n";
    $result .= "Branch: {$payload->ref}\n";
    $result .= $payload->forced ? "Force pushed " : "Pushed ";
    $result .= "by: {$payload->pusher->name} <{$payload->pusher->email}>\n";

    foreach ($payload->commits as $commit) {
        $result .= "\n";
        $result .= "{$commit->id}\n";
        $result .= "{$commit->author->name} <{$commit->author->email}>\n";
        $result .= "{$commit->message}\n";
    }

    // Email the result
    mail('dnljms@gmail.com', 'Boost website update: '.date('j M Y'), $result);
}

class GitHubWebHookEvent {
    var $event_type;
    var $payload;
}

function get_webhook_event() {
    if (!array_key_exists('github-webhook-secret', EvilGlobals::$settings)) {
        die("github-webhook-secret not set.");
    }
    $secret_key = EvilGlobals::$settings['github-webhook-secret'];
    $post_body = file_get_contents('php://input');

    // Check the signature

    $signature = array_key_exists('HTTP_X_HUB_SIGNATURE', $_SERVER) ?
        $_SERVER['HTTP_X_HUB_SIGNATURE'] : false;

    if (!preg_match('@^(\w+)=(\w+)$@', $signature, $match)) {
        die("Unable to parse signature.\n");
    }

    $check_signature = hash_hmac($match[1], $post_body, $secret_key);
    if ($check_signature != $match[2]) {
        die("Signature doesn't match.\n");
    }

    // Get the payload

    switch($_SERVER['CONTENT_TYPE']) {
    case 'application/json':
        $payload_text = $post_body;
        break;
    case 'application/x-www-form-urlencoded':
        if (!array_key_exists('payload', $_POST)) {
            die("Unable to find payload.\n");
        }
        $payload_text = $_POST['payload'];
        break;
    default:
        die("Unexpected content_type: ".$_SERVER['CONTENT_TYPE']);
    }

    $payload = json_decode($payload_text);
    if (!$payload) {
        die("Error decoding payload.");
    }

    // Get the event type
 
    if (!array_key_exists('HTTP_X_GITHUB_EVENT', $_SERVER)) {
        die("No event found.");
    }
    $event_type = $_SERVER['HTTP_X_GITHUB_EVENT'];

    $x = new GitHubWebHookEvent;
    $x->event_type = $event_type;
    $x->payload = $payload;
    return $x;
}

function syscall($cmd, $cwd) {
    $descriptorspec = array(
        1 => array('pipe', 'w') // stdout is a pipe that the child will write to
    );
    $resource = proc_open($cmd, $descriptorspec, $pipes, $cwd);
    if (is_resource($resource)) {
        // TODO: Error pipe
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($resource);
        return $output;
    }
}

