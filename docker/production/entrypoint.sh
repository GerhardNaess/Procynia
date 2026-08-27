#!/bin/sh
#
# Procynia production entrypoint (app and web images).
#
# Kept deliberately small. It does three things and then hands over:
#
#   1. Makes sure the writable tree exists. storage/app is an Azure Files mount at runtime, and a
#      freshly mounted share is empty, so the subdirectories the application expects have to be
#      recreated on every boot rather than only at build time.
#   2. Warms the Laravel caches that are safe to warm. Config is NOT cached at build time — it would
#      bake build-time defaults into the image and silently ignore every Container Apps environment
#      variable — so it is cached here instead, once the real environment is present.
#   3. exec's the requested command, so the workload becomes PID 1's child and receives SIGTERM
#      directly. That matters for queue workers: Laravel finishes the current job on SIGTERM.
#
# It never writes a .env, never generates an APP_KEY and never runs migrations. Migrations are a
# separate Container Apps Job (see docs/azure-staging-runbook.md).

set -e

APP_ROOT="${PROCYNIA_APP_ROOT:-/var/www/html}"

# 1. Writable tree. storage/app may be a freshly mounted, empty Azure Files share.
mkdir -p \
    "${APP_ROOT}/storage/app/private" \
    "${APP_ROOT}/storage/app/public" \
    "${APP_ROOT}/storage/framework/cache/data" \
    "${APP_ROOT}/storage/framework/sessions" \
    "${APP_ROOT}/storage/framework/views" \
    "${APP_ROOT}/storage/logs" \
    "${APP_ROOT}/bootstrap/cache"

# Ownership only where it is cheap. Recursing into a mounted share can be slow and is unnecessary:
# the mount carries its own permissions from the storage account.
chown www-data:www-data \
    "${APP_ROOT}/storage" \
    "${APP_ROOT}/storage/framework" \
    "${APP_ROOT}/storage/framework/cache" \
    "${APP_ROOT}/storage/framework/cache/data" \
    "${APP_ROOT}/storage/framework/sessions" \
    "${APP_ROOT}/storage/framework/views" \
    "${APP_ROOT}/storage/logs" \
    "${APP_ROOT}/bootstrap/cache" 2>/dev/null || true

# 2. Cache warming, at runtime, with the real environment in place.
if [ "${PROCYNIA_OPTIMIZE_ON_BOOT:-true}" = "true" ]; then
    echo "[Procynia][entrypoint] Warming configuration, route and view caches ..."
    php "${APP_ROOT}/artisan" config:cache --no-interaction
    php "${APP_ROOT}/artisan" route:cache --no-interaction
    php "${APP_ROOT}/artisan" view:cache --no-interaction
else
    echo "[Procynia][entrypoint] PROCYNIA_OPTIMIZE_ON_BOOT is not 'true'; skipping cache warming."
fi

echo "[Procynia][entrypoint] Starting: $*"

# 3. Hand over PID 1 so signals reach the workload directly.
exec "$@"
