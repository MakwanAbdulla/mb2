#!/bin/sh
set -e

cd /var/www/html

[ -f .env ] || cp .env.example .env

# Dev Container: the checkout is bind-mounted, so dependencies and built assets
# may be missing on first start. Both are no-ops in the plain `docker compose up`
# image, which already has them baked in.
if [ ! -f vendor/autoload.php ]; then
    echo "Installing PHP dependencies..."
    composer install --no-interaction --no-progress
fi
if [ ! -f public/build/manifest.json ]; then
    echo "Installing JS dependencies and building assets..."
    npm ci --no-audit --no-fund
    npm run build
fi

# Gitignored by design (synced fresh from GitHub, never committed — see
# app/Console/Commands/SyncIcons.php). A brand-new checkout/build has none.
if [ -z "$(ls -A public/images/icons/bootstrap 2>/dev/null)" ]; then
    echo "Fetching Bootstrap Icons..."
    php artisan icons:sync
fi

php artisan storage:link 2>/dev/null || true

# Wait for the DB (only matters for the mysql service; a no-op for sqlite,
# where php artisan migrate succeeds immediately).
if [ "$DB_CONNECTION" = "mysql" ]; then
    echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."
    attempts=0
    until php artisan db:show >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "MySQL did not become ready in time." >&2
            exit 1
        fi
        sleep 2
    done
fi

php artisan migrate --force

# No seeder creates a fiscal year, so bookkeeping has nothing to attach
# entries to without this. The command is idempotent (skips if the year
# already exists), so it's safe to run on every boot rather than guarding it.
php artisan accounting:create-fiscal-year "$(date +%Y)"

# Seed only when the database has no users yet. Several dev seeders
# (DevDataSeeder, TestUserSeeder, the CogWorks import) use factories rather
# than updateOrCreate, so re-running them on every restart would duplicate
# demo data. The check looks at the database itself rather than a marker file:
# the database and storage live on separate volumes, and a marker that outlived
# its database used to skip seeding entirely and leave nobody able to log in.
USER_COUNT=$(php artisan tinker --execute='echo App\Models\User::count();' 2>/dev/null | tail -n 1)

if [ "$USER_COUNT" = "0" ]; then
    echo "Empty database — seeding..."
    php artisan db:seed --force
    echo "Done. Log in as admin@example.com / password — see docs/guides/dev-setup.md."
fi

exec "$@"
