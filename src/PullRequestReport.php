<?php

/*
 * Copyright 2014 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

use Nette\Object;

class PullRequestReport extends Object {
    public function run() {
        // Date parsing:
        // echo date("r\n", strtotime("2014-01-27T05:26:41Z"));

        $pull_requests = Array();
        foreach (EvilGlobals::github_cache()->iterate('/orgs/boostorg/repos') as $repo) {
            foreach (EvilGlobals::github_cache()->iterate("/repos/{$repo->full_name}/pulls") as $pull) {
                $data = new \stdClass();
                $data->id = $pull->id;
                $data->html_url = $pull->html_url;
                $data->title = $pull->title;
                $data->body = $pull->body;
                $data->created_at = $pull->created_at;
                $data->updated_at = $pull->updated_at;
                $pull_requests[$repo->full_name][] = $data;
            }
        }

        ksort($pull_requests);

        $json_data = Array(
            'last_updated' => date('c'),
            'pull_requests' => $pull_requests,
        );

        $json = json_encode($json_data);

        if (\EvilGlobals::settings('website-data')) {
            file_put_contents(\EvilGlobals::settings('website-data').'/pull-requests.json', $json);
        }
        else {
            echo $json;
        }
    }
}
