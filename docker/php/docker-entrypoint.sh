#!/bin/sh
# Mzian PHP container entrypoint.
#
# For the "app" role (php-fpm) it prepares the application before serving:
#   1. installs Composer dependencies when vendor/ is missing (dev only)
#   2. waits for the database
#   3. runs Doctrine migrations
#   4. runs `mzian:setup` (idempotent: catalog, providers, agents, settings)
# Workers (CONTAINER_ROLE=worker) skip the bootstrap and only wait for the DB.
set -e

if [ "${1#-}" != "$1" ]; then
    set -- php-fpm "$@"
fi

if [ "$1" = 'php-fpm' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
    if [ "$APP_ENV" != 'prod' ] && [ ! -f vendor/autoload_runtime.php ]; then
        echo "[entrypoint] Installing Composer dependencies..."
        composer install --prefer-dist --no-progress --no-interaction
    fi

    mkdir -p var/cache var/log var/share var/workspaces var/repositories var/previews var/mock-providers
    setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var 2>/dev/null || chmod -R a+rwX var
    setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var 2>/dev/null || true

    if grep -q ^DATABASE_URL= .env 2>/dev/null || [ -n "$DATABASE_URL" ]; then
        echo "[entrypoint] Waiting for the database..."
        ATTEMPTS_LEFT=60
        until [ $ATTEMPTS_LEFT -eq 0 ] || DB_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
            sleep 2
            ATTEMPTS_LEFT=$((ATTEMPTS_LEFT - 1))
            echo "[entrypoint] Database not ready yet ($ATTEMPTS_LEFT attempts left)"
        done
        if [ $ATTEMPTS_LEFT -eq 0 ]; then
            echo "[entrypoint] The database is not reachable:"
            echo "$DB_ERROR"
            exit 1
        fi
    fi

    if [ "${CONTAINER_ROLE:-app}" = 'app' ] && [ "${MZIAN_AUTO_SETUP:-1}" = '1' ]; then
        echo "[entrypoint] Running migrations..."
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
        echo "[entrypoint] Running mzian:setup..."
        if [ "$APP_ENV" = 'dev' ]; then
            php bin/console mzian:setup --demo --no-interaction
        else
            php bin/console mzian:setup --no-interaction
        fi
    fi
fi

exec docker-php-entrypoint "$@"
