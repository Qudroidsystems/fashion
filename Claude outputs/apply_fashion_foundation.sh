#!/usr/bin/env bash
# Run from the project root (htdocs/fashion), on the fashion-foundation branch.
set -euo pipefail
[ -f artisan ] || { echo "Run this inside the Laravel project folder."; exit 1; }
[ "$(git branch --show-current)" = "fashion-foundation" ] || { echo "Switch to the fashion-foundation branch first."; exit 1; }
DIR="$(cd "$(dirname "$0")" && pwd)"
tar -xzf "$DIR/fhmp_sync.tgz"
while IFS= read -r f; do [ -n "$f" ] && rm -f -- "$f"; done < "$DIR/fhmp_deleted.txt"
find app database resources routes tests public/images public/website docs -type d -empty -delete 2>/dev/null || true
echo "Done. Next: composer update laravel/sanctum && composer install, set DB_DATABASE to a new empty database, php artisan migrate --seed"
