<?php

/*
 * Copyright 2017 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

use Nette\Object;

class UpdateExplicitFailures extends Object {
    var $xml;
    var $libraries;
    var $updated_libraries = array();

    static function update(Repo $repo) {
        $repo->attemptAndPush(function() use($repo) {
            $mirror = new LocalMirror();
            // TODO: Can't get this from the repo's URL.
            $mirror_path = "{$mirror->mirror_root}/boostorg/boost.git";
            $xml_path = "{$repo->path}/status/explicit-failures-markup.xml";

            // Get the libraries from the super project.
            $update = new UpdateExplicitFailures(file_get_contents($xml_path));

            // TODO: Duplicates code from LocalMirror.
            $child_repos = array();
            foreach(RepoBase::readSubmoduleConfig($repo->path) as $name => $values) {
                if (empty($values['path'])) { throw \RuntimeException("Missing path."); }
                if (empty($values['url'])) { throw \RuntimeException("Missing URL."); }
                $child_repos[$values['path']] = LocalMirror::resolveGitUrl($values['url'], $mirror_path);
            }

            foreach($repo->currentHashes(array_keys($child_repos)) as $path => $hash) {
                $submodule_repo = new RepoBase("{$child_repos[$path]}");
                // TODO: This duplicates code in update-doc-list.php from the website.
                foreach($submodule_repo->readLines("ls-tree {$hash} meta/explicit-failures-markup.xml") as $line) {
                    if (!$line) { continue; }
                    if (preg_match("@^(\d{6}) (blob) ([a-zA-Z0-9]+)\t(.*)$@", $line, $matches)) {
                        $submodule_xml = $submodule_repo->commandWithOutput("show {$matches[3]}");
                        if (Process::status("xmllint - ".
                            "--schema \"{$repo->path}/status/explicit-failures.xsd\"",
                            null, null, $submodule_xml))
                        {
                            Log::error("Error linting failure markup for {$path}");
                        } else {
                            $update->addLibraries($submodule_xml);
                        }
                    } else {
                        throw new RuntimeException("Unmatched submodule line: {$line}");
                    }
                }
            }

            file_put_contents($xml_path, $update->getUpdatedXml());
            if (Process::status("xmllint \"{$xml_path}\" ".
                "--schema \"{$repo->path}/status/explicit-failures.xsd\""))
            {
                throw new RuntimeException("Error linting final xml");
            }

            return $repo->commitAll("Update explicit-failures-markup.xml");
        });
    }

    function __construct($xml) {
        $this->xml = $xml;
        $this->libraries = $this->parseExplicitFailuresMarkup($xml);
    }

    // TODO: Should I check that the library name matches the repo?
    //       Or maybe fill in missing library names?
    function addLibraries($xml) {
        $this->updated_libraries = array_merge($this->updated_libraries,
            $this->parseExplicitFailuresMarkup($xml));
    }

    function getUpdatedXml() {
        $library_order = array_keys($this->libraries);
        foreach ($this->updated_libraries as $name => $library) {
            if (!array_key_exists($name, $this->libraries)) {
                $library_order = self::almostSortedInsert($library_order, $name);
            }
        }

        $output_xml = "";
        $offset = 0;

        // Process markup before first library early, in case a new library
        // has been added before it.
        $first_library = reset($this->libraries);
        $output_xml = substr($this->xml, 0, $first_library->start);
        $offset = $first_library->end;

        foreach ($library_order as $name) {
            if (array_key_exists($name, $this->libraries)) {
                // This is only needed because of the first block of xml.
                if ($this->libraries[$name]->start > $offset) {
                    $output_xml .= substr($this->xml, $offset, $this->libraries[$name]->start - $offset);
                }
                $offset = $this->libraries[$name]->end;
            }

            if (array_key_exists($name, $this->updated_libraries)) {
                $output_xml .= rtrim($this->updated_libraries[$name]->markup);
                $output_xml .= "\n\n";
            } else {
                $output_xml .= $this->libraries[$name]->markup;
            }
        }
        $output_xml .= substr($this->xml, $offset);
        return $output_xml;
    }

    function parseExplicitFailuresMarkup($xml) {
        preg_match_all('@
            # Comment preceeding library markup
            (?:^[ \t]*<!--[ a-z0-9]*-->[ \t]*\n)?
            [ \t]*<library\b[^>]*>
            .*?
            </library\b[^>]*>(?:\s*\n)?
        @smxi', $xml, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $libraries = array();

        foreach ($matches as $match) {
            $library = new UpdateExplicitFailures_LibraryMarkup();
            $library->start = $match[0][1];
            $library->end = $match[0][1] + strlen($match[0][0]);
            $library->markup = $match[0][0];

            // Turn off warnings while parsing library XML.
            // Will only fail if the markup does something tricky, like
            // put </library> in a comment.
            $old_error_reporting = error_reporting();
            error_reporting($old_error_reporting & ~E_WARNING);
            try {
                $simple_xml = new SimpleXMLElement($library->markup);
                error_reporting($old_error_reporting);
            } catch (\Exception $e) {
                error_reporting($old_error_reporting);
                throw new \RuntimeException("Library parse error: {$e->getMessage()}");
            }
            if ($simple_xml->getName() != 'library') {
                throw new \RuntimeException("Invalid library markup");
            }
            $attributes = $simple_xml->attributes();
            if (!isset($attributes['name'])) {
                throw new \RuntimeException("Missing library name");
            }
            $libraries[strtolower($attributes['name'])] = $library;
        }

        return $libraries;
    }

    // Insert $name into $library_order at a vaguely reasonable place,
    // assuming that $library_order is mostly sorted.
    static function almostSortedInsert($library_order, $name) {
        $score = 0;
        $best_score = 0;
        $position = 0;

        foreach ($library_order as $index => $x) {
            if ($x == $name) {
                return $library_order;
            } else if ($x < $name) {
                ++$score;
            } else {
                --$score;
            }
            if ($score >= $best_score) {
                $best_score = $score;
                $position = $index + 1;
            }
        }

        array_splice($library_order, $position, 0, array($name));
        return $library_order;
    }
}

class UpdateExplicitFailures_LibraryMarkup extends Object {
    var $start;
    var $end;
    var $markup;
}