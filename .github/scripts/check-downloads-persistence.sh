#!/usr/bin/env bash
set -euo pipefail

# Use only disposable CI containers and their own database storage.
fixture="$(pwd)/.github/scripts/downloads-persistence.php"
network="jellydash-downloads-persistence"
database="jellydash-downloads-db-ci"
sqlite_volume="jellydash-downloads-sqlite-ci"
unraid_volume="jellydash-downloads-unraid-ci"
cleanup() {
  docker rm --force "$database" >/dev/null 2>&1 || true
  docker volume rm "$sqlite_volume" "$unraid_volume" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$network" >/dev/null
docker run --detach --name "$database" --network "$network" \
  --env MARIADB_ROOT_PASSWORD=ci-persistence-only \
  --env MARIADB_DATABASE=ci_downloads mariadb:11 >/dev/null
ready=false
for attempt in {1..60}; do
  if docker exec "$database" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
    ready=true
    break
  fi
  sleep 1
done
test "$ready" = true

# MariaDB: neither app container has a data-directory mount. Only the DB survives.
for mode in seed verify; do
  docker run --rm --network "$network" \
    --env APP_ENV=testing --env POLLER_ENABLED=false \
    --env DB_DRIVER=mysqli --env DB_HOST="$database" --env DB_NAME=ci_downloads \
    --env DB_USER=root --env DB_PASS=ci-persistence-only \
    --volume "$fixture:/ci-downloads-persistence.php:ro" \
    jellydash:ci gosu www-data php /ci-downloads-persistence.php "$mode"
done

# SQLite Compose persists var/data. The official Unraid template persists var.
for layout in sqlite unraid; do
  if [ "$layout" = sqlite ]; then
    volume="$sqlite_volume"
    target=/var/www/html/var/data
  else
    volume="$unraid_volume"
    target=/var/www/html/var
  fi
  docker volume create "$volume" >/dev/null
  for mode in seed verify; do
    docker run --rm \
      --env APP_ENV=testing --env POLLER_ENABLED=false \
      --env DB_DRIVER=sqlite3 --env DB_NAME=/var/www/html/var/data/ci-downloads.sqlite \
      --volume "$volume:$target" --volume "$fixture:/ci-downloads-persistence.php:ro" \
      jellydash:ci gosu www-data php /ci-downloads-persistence.php "$mode"
  done
done
