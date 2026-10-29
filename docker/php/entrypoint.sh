#!/bin/sh
set -e

# Dev: install dependencies on first boot of the bind-mounted code.
if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

# Only the main app container runs migrations and builds caches, so workers don't race it.
if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
  if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
  fi
  if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan optimize
  fi
fi

exec "$@"
