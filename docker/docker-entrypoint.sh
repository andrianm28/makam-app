#!/bin/bash
#
# Entrypoint for the makam-app runtime image (see ../Dockerfile).
#
# One image, promoted everywhere (ci-cd-and-release.md §1) — the SAME image
# runs dev-web/stg-web (this "web" mode: nginx + php-fpm), stg-horizon, and
# the queue workers (docker-compose.dev-stg.yml: `command: ["php", "artisan",
# "horizon"]` etc.), which must NOT start a web server. So: `web` is a
# special-cased first argument; anything else is exec'd directly, exactly
# like the stock php-cli entrypoint pattern.
#
# "web" mode runs nginx (foreground) and php-fpm (background) as one unit,
# not via a full process supervisor (s6-overlay etc.) — this project avoids
# that class of complexity elsewhere (no Octane, no Kubernetes; AGENTS.md).
# The trade-off: if php-fpm crashes, nginx keeps running and would serve 502s
# rather than the container exiting — the HEALTHCHECK in the Dockerfile
# catches that (curls /up, which needs php-fpm to answer 200), but nothing
# here auto-restarts php-fpm alone. Revisit if that proves to be a real
# problem in practice; it has not been exercised yet (NOT TESTED).

set -euo pipefail

if [ "${1:-}" != "web" ]; then
    exec "$@"
fi

php-fpm -F &
FPM_PID=$!

nginx -g 'daemon off;' &
NGINX_PID=$!

# ci-cd-and-release.md §5 step 6: "Restart PHP-FPM/application... gracefully."
# Forward the container's stop signal to both children so neither is killed
# mid-request; wait for both to actually exit before this script does.
term_children() {
    kill -TERM "$FPM_PID" "$NGINX_PID" 2>/dev/null || true
}
trap term_children TERM INT

# If either process dies on its own (crash, not a signal we sent), stop the
# other and exit non-zero so the container is visibly unhealthy/exited
# rather than silently running half a stack.
wait -n "$FPM_PID" "$NGINX_PID"
EXIT_CODE=$?
term_children
wait "$FPM_PID" "$NGINX_PID" 2>/dev/null || true
exit "$EXIT_CODE"
