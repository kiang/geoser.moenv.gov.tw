#!/bin/bash

ROOT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NOW=$(date '+%Y-%m-%d %H:%M:%S')

cd "$ROOT_PATH"

/usr/bin/git pull

php -q "$ROOT_PATH/scripts/crawler.php"

/usr/bin/git add -A

/usr/bin/git commit --author 'auto commit <noreply@localhost>' -m "auto update @ $NOW"

/usr/bin/git push origin master
