#!/bin/sh
# The reflection oracle on every supported PHP version, one Docker image at
# a time (php:8.X-cli, 1 GB, 2 CPUs), so it fits on a small machine.
#
#   tests/Oracle/run-reflection.sh [versions...]   (default: 8.2 8.3 8.4 8.5)
set -u
root=$(cd "$(dirname "$0")/../.." && pwd)
status=0

for version in ${@:-8.2 8.3 8.4 8.5}; do
    docker run --rm --memory=1g --cpus=2 -v "$root":/app:ro -w /app "php:$version-cli" php tests/Oracle/reflection.php || status=1
done

exit $status
