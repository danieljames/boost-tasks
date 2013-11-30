import requests
import json
import sys
import os
import os.path
import errno
from urlparse import urlparse

def make_dir(path):
    path = os.path.realpath(path)
    try:
        os.makedirs(path)
    except OSError as exc:
        if exc.errno == errno.EEXIST and os.path.isdir(path):
            pass
        else:
            raise

def run_command(command):
    if os.system(command) != 0:
        raise Exception("Error running %s" % command)

def mirror_boostorg(root_dir):
    git_dir = os.path.join(root_dir, 'git')

    r = requests.get('https://api.github.com/orgs/boostorg/repos')
    if (r.ok):
        for repo in json.loads(r.text or r.content):
            url = repo['clone_url']
            # Note using os.path.join because url path is absolute.
            path = git_dir + urlparse(url).path
            make_dir(os.path.join(path, os.pardir))

            # TODO: Check that path is actually a git repo?
            if os.path.isdir(path):
                run_command("git --git-dir=" + path + " fetch")
            else:
                run_command("git clone --bare " + url + " " + path)

mirror_boostorg(os.path.realpath(os.path.dirname(sys.argv[0])))
