#!/bin/bash
# Shared by post-merge/post-checkout: reinstalls composer/npm dependencies
# and rebuilds frontend assets whenever their lock files changed between
# two refs, so nobody has to remember to do it manually after a pull. Also
# flags new migrations — those just get a reminder, never an auto-run,
# since migrate touches the database and may need a specific environment.
# New permissions are added automatically: permissions:add-missing only ever
# adds, so it is safe to run against any local database.
set -e

cd "$(git rev-parse --show-toplevel)"

# Local dev only (APP_ENV=local, as in .env.example). On staging and
# production the deploy scripts install, build and migrate themselves, the
# servers may lack npm, and a rebuild would overwrite the rsynced public/build.
if ! grep -qE '^APP_ENV=["'\'']?local["'\'']?\s*$' .env 2>/dev/null; then
    exit 0
fi

before="$1"
after="$2"

changed_files="$(git diff --name-only "$before" "$after" 2>/dev/null || true)"

if echo "$changed_files" | grep -qx "composer.lock"; then
    echo "→ composer.lock ändrades, kör 'composer install'..."
    composer install
fi

if echo "$changed_files" | grep -qx "package-lock.json"; then
    echo "→ package-lock.json ändrades, kör 'npm install && npm run build'..."
    npm install
    npm run build
# Tailwind v4 only emits the classes it finds in the source, so a new view or
# class needs a rebuild too. Skipped while 'npm run dev' runs (public/hot):
# Vite serves fresh CSS then.
elif [ ! -f public/hot ] && echo "$changed_files" | grep -qE '^(resources/|app/|vite\.config\.|package\.json$)'; then
    echo "→ Vyer, CSS eller JS ändrades, kör 'npm run build'..."
    if ! npm run build --silent >/dev/null; then
        echo "⚠ Bygget misslyckades — kör 'npm run build' för hand."
    fi
fi

if echo "$changed_files" | grep -qx "database/seeders/PermissionSeeder.php"; then
    echo "→ PermissionSeeder.php ändrades, kör 'php artisan permissions:add-missing'..."
    if ! php artisan permissions:add-missing; then
        echo "⚠ Gick inte att köra — kör 'php artisan permissions:add-missing' för hand (efter 'php artisan migrate' om det finns nya migreringar)."
    fi
fi

new_migrations="$(git diff --name-only --diff-filter=A "$before" "$after" -- database/migrations 2>/dev/null || true)"
if [ -n "$new_migrations" ]; then
    echo ""
    echo "⚠ Nya migreringar har tillkommit — kom ihåg att köra 'php artisan migrate':"
    echo "$new_migrations" | sed 's/^/    /'
fi
