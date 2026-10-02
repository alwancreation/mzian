#!/bin/sh
# Healthy when PHP-FPM answers its ping endpoint (workers: when PHP runs).
set -e
if [ "${CONTAINER_ROLE:-app}" = "worker" ]; then
    exec php -r 'exit(0);'
fi
export SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET
if cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong; then
    exit 0
fi
exit 1
