#!/bin/sh

touch files.list

find ../ -name '*.php' > files.list
find ../ -name '*.tpl' > files.list

xgettext -kt -o messages.pot -L PHP -f files.list
