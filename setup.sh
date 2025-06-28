#!/bin/bash

# install composer deps
composer install

# set correct permissions if needed
chmod -R 755 web/sites/default/files
