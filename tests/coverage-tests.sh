#!/bin/sh -e

cd $(dirname $0)/..
#./vendor/bin/tester -c tests/config/php-coverage.ini -p php tests --coverage-src src --coverage tests/output/coverage.xml
./vendor/bin/tester -c tests/config/php-coverage.ini -p php tests --coverage-src src --coverage tests/output/coverage.html
