<?php

require_once(__DIR__.'/../vendor/autoload.php');

use BoostTasks\Settings;
use BoostTasks\RepoBase;
use BoostTasks\Log;
// use RuntimeException;

function webhook() {
    header("Content-Type: text/plain");
    Settings::init(array('config-file' => __DIR__.'/../var/webhook.neon', 'webhook' => true));
    $event = get_webhook_event();

    Log::info("Handling event {$event->event_type}");

    switch($event->event_type) {
    case 'push':
        webhook_push_handler($event);
        break;
    case 'pull_request':
        webhook_pull_request_handler($event);
        break;
    default:
        throw new RuntimeException("Unrecognized event: {$event->event_type}");
    }
}

function webhook_push_handler($event) {
    // TODO: Move to configuration?
    $repos = array(
        'boost' => array(
            'master' => array('path' => '/home/www/shared/repos/boost-master', 'method' => 'reset'),
            'develop' => array('path' => '/home/www/shared/repos/boost-develop', 'method' => 'reset'),
        ),
        'website' => array(
            'master' => '/home/www/live.boost.org',
            'beta' => array('path' => '/home/www/beta.boost.org', 'method' => 'reset'),
        ),
        'build' => array(
           'website' => '/home/www/shared/build-site',
        ),
        'documentation-fixes' => array(
           'master' => '/home/www/shared/repos/documentation-fixes',
        ),
    );

    $payload = $event->payload;
    $branch = preg_replace('@^refs/heads/@', '', $payload->ref);

    $repo_path = null;
    if (array_key_exists($payload->repository->name, $repos)) {
        $branches = $repos[$payload->repository->name];
    }
    else {
        echo "Nothing to do for repository: {$payload->repository->name}.\n";
        return false;
    }

    if (array_key_exists($branch, $branches)) {
        $repo_details = $branches[$branch];
        $repo_path = $branches[$branch];
    }
    else {
        echo "Ignoring repository {$payload->repository->name}, ref: {$payload->ref}\n";
        return false;
    }

    // Get the update method that we're going to use.
    if (is_array($repo_details)) {
        $repo_path = $repo_details['path'];
        $update_method = array_get($repo_details, 'method', 'pull');
    } else {
        $repo_path = $repo_details;
        $update_method = 'pull';
    }

    // These aren't needed until later, but getting them out of the way
    // before doing anything with side effects.
    $email_title = "[boost website] Error updating {$payload->repository->name} {$branch}";
    $git_commits = commit_details($payload);

    // Update the repo
    echo "Updating {$repo_path}\n";

    $git_output = update_git_checkout($repo_path, $update_method, $branch);

    // Email if something went wrong when updating.
    if (!$git_output) {
        echo "Done.\n";
    } else {
        echo "Done with git error, emailing results.\n";

        $result = '';

        $result .= "Pull\n";
        $result .= "====\n";
        $result .= $git_output;
        $result .= "\n";

        $result .= "Commits\n";
        $result .= "=======\n";
        $result .= $git_commits;
        $result .= "\n";

        // Email the result
        mail('dnljms@gmail.com', "{$email_title} update ".date('j M Y'), $result);
    }
}

/* Returns output if anything failed */
function update_git_checkout($repo_path, $update_method, $branch) {
    $failed = false;

    $result = '';

    $repo = new RepoBase($repo_path);

    try {
        switch($update_method) {
        case 'pull':
            $result .= $repo->commandWithOutput('pull -q --ff-only');
            break;
        case 'reset':
            $result .= $repo->commandWithOutput("fetch -q");
            $result .= $repo->commandWithOutput("reset --hard origin/{$branch}");
            break;
        default:
            return "Error: invalid update method: {$update_method}";
        }
    }
    catch (\RuntimeException $e) {
        return "Git pull failed";
    }

    return '';
}

function commit_details($payload) {
    $result = '';

    $result .= "Branch: {$payload->ref}\n";
    $result .= $payload->forced ? "Force pushed " : "Pushed ";
    $result .= "by: {$payload->pusher->name} <{$payload->pusher->email}>\n";

    foreach ($payload->commits as $commit) {
        $result .= "\n";
        $result .= "commit {$commit->id}\n";
        $result .= "Author: {$commit->author->name} <{$commit->author->email}>\n";
        $result .= "\n";
        $result .= preg_replace('@^(?!$)@m', "    ", $commit->message)."\n";
    }

    return $result;
}

function webhook_pull_request_handler($event) {
    $payload = $event->payload;

    $record = Settings::database()->dispense('pull_request_event');
    $record->action = $payload->action;
    $record->repo_full_name = $payload->repository->full_name;
    $record->pull_request_id = $payload->pull_request->id;
    $record->pull_request_number = $payload->pull_request->number;
    $record->pull_request_url = $payload->pull_request->html_url;
    $record->pull_request_title = $payload->pull_request->title;
    $record->pull_request_created_at = $payload->pull_request->created_at;
    $record->pull_request_updated_at = $payload->pull_request->updated_at;
    $record->pull_request_state = $payload->pull_request->state;
    $record->store();
}

class GitHubWebHookEvent {
    var $event_type;
    var $payload;
}

function get_webhook_event() {
    $secret_key = Settings::settings('github-webhook-secret');
    if (!$secret_key) {
        throw new RuntimeException("github-webhook-secret not set.");
    }

    $post_body = file_get_contents('php://input');

    // Check the signature

    $signature = array_key_exists('HTTP_X_HUB_SIGNATURE', $_SERVER) ?
        $_SERVER['HTTP_X_HUB_SIGNATURE'] : false;

    if (!preg_match('@^(\w+)=(\w+)$@', $signature, $match)) {
        throw new RuntimeException("Unable to parse signature");
    }

    $check_signature = hash_hmac($match[1], $post_body, $secret_key);
    if ($check_signature != $match[2]) {
        throw new RuntimeException("Signature doesn't match");
    }

    // Get the payload

    switch($_SERVER['CONTENT_TYPE']) {
    case 'application/json':
        $payload_text = $post_body;
        break;
    case 'application/x-www-form-urlencoded':
        if (!array_key_exists('payload', $_POST)) {
            throw new RuntimeException("Unable to find payload");
        }
        $payload_text = $_POST['payload'];
        break;
    default:
        throw new RuntimeException("Unexpected content_type: ".$_SERVER['CONTENT_TYPE']);
    }

    $payload = json_decode($payload_text);
    if (!$payload) {
        throw new RuntimeException("Error decoding payload.");
    }

    // Get the event type

    if (!array_key_exists('HTTP_X_GITHUB_EVENT', $_SERVER)) {
        throw new RuntimeException("No event found.");
    }
    $event_type = $_SERVER['HTTP_X_GITHUB_EVENT'];

    $x = new GitHubWebHookEvent;
    $x->event_type = $event_type;
    $x->payload = $payload;
    return $x;
}
