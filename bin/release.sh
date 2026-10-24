#!/usr/bin/env bash
# Release a new Hub Client version for Git Updater.
#   bin/release.sh 0.4.1 "What changed"
# Bumps the version in hub-client.php, commits, tags and pushes.
set -euo pipefail
cd "$(dirname "$0")/.."

V="${1:-}"; MSG="${2:-Release $V}"
[[ "$V" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Usage: bin/release.sh X.Y.Z \"message\""; exit 1; }
git rev-parse "$V" >/dev/null 2>&1 && { echo "Tag $V already exists"; exit 1; }
[ -z "$(git status --porcelain -- . ':!hub-client.php')" ] || { echo "Commit your other changes first"; git status --short; exit 1; }

CUR=$(sed -n "s/.*define('HUB_CLIENT_VERSION', '\(.*\)').*/\1/p" hub-client.php)
[ "$(printf '%s\n%s\n' "$CUR" "$V" | sort -V | tail -1)" = "$V" ] && [ "$CUR" != "$V" ] || { echo "$V must be higher than $CUR"; exit 1; }

sed -i.bak -e "s/^\( \* Version: *\).*/\1$V/" -e "s/define('HUB_CLIENT_VERSION', '.*')/define('HUB_CLIENT_VERSION', '$V')/" hub-client.php && rm hub-client.php.bak
php -l hub-client.php >/dev/null 2>&1 || command -v php >/dev/null || true

git add hub-client.php
git commit -m "$MSG" -m "Version $V"
git tag -a "$V" -m "$MSG"
git push origin HEAD --follow-tags
echo "Released $V — sites see it under Plugins → Updates (instantly if the GitHub webhook is set up)."
