#!/bin/sh -e

cd $(dirname $(dirname $0))
./vendor/bin/tester -c tests/config/php.ini -p php tests
