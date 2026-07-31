#!/bin/sh
set -eu

mkdir -p /var/www/html/cache \
    /var/www/html/var/cache \
    /var/www/html/var/log \
    /var/www/html/public/uploads \
    /var/www/html/public/uploads/images

chown -R www-data:www-data \
    /var/www/html/cache \
    /var/www/html/var/cache \
    /var/www/html/var/log \
    /var/www/html/public/uploads

# Clear compiled Twig templates on start so a deploy's template changes actually
# take effect. Production runs Twig with auto_reload off and the cache dir is a
# persistent volume, so stale compiled templates would otherwise survive the
# rebuild. Only the hashed subdirectories are removed (keeps .htaccess/.gitkeep).
find /var/www/html/cache -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + 2>/dev/null || true

# Optional auth: seed the initial admin when configured. Idempotent: an
# existing user is never touched, so in-app password changes survive restarts.
if [ -n "${AUTH_ADMIN_USER:-}" ] && [ -n "${AUTH_ADMIN_PASSWORD:-}" ]; then
    php /var/www/html/bin/console.php user:ensure "${AUTH_ADMIN_USER}" "${AUTH_ADMIN_PASSWORD}" || true
fi

# Background workers (disable all with POLLER_ENABLED=false). Each runs detached
# alongside the web server and is self-healing: a failed run never stops the
# loop.
if [ "${POLLER_ENABLED:-true}" = "true" ]; then
    POLL_INTERVAL="${POLL_INTERVAL:-30}"
    LIBRARIES_CACHE_TTL="${LIBRARIES_CACHE_TTL:-300}"
    SEERR_POLL_INTERVAL="${SEERR_POLL_INTERVAL:-120}"
    echo "[entrypoint] starting background workers (history every ${POLL_INTERVAL}s, libraries every ${LIBRARIES_CACHE_TTL}s, jellyseerr every ${SEERR_POLL_INTERVAL}s)"

    # Records currently-playing sessions so history logs even when nobody has
    # the dashboard open.
    (
        while true; do
            php /var/www/html/bin/console.php history:poll || true
            sleep "${POLL_INTERVAL}"
        done
    ) &

    # Keeps the library overview cache warm so the Libraries page never triggers
    # a cold scan inside a visitor's request.
    (
        while true; do
            php /var/www/html/bin/console.php libraries:warm || true
            sleep "${LIBRARIES_CACHE_TTL}"
        done
    ) &

    # Mirrors Jellyseerr requests and pushes an alert when a new one appears.
    # Requests trickle in far slower than playback, so this runs on its own,
    # longer interval. No-op unless JELLYSEER_URL / JELLYSEER_API_TOKEN are set.
    (
        while true; do
            php /var/www/html/bin/console.php seerr:poll || true
            sleep "${SEERR_POLL_INTERVAL}"
        done
    ) &
fi

exec "$@"
