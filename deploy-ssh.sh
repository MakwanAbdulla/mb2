#!/bin/bash
# deploy-ssh.sh — Driftsätter via SSH och git pull
# Krav: SSH-nyckel uppsatt mot Loopia, projektet klonat på servern
# Användning: ./deploy-ssh.sh [staging|production]

set -e

SECONDS=0

# Följ symlinken för att hitta repomappen, inte scripts-mappen
SCRIPT_PATH="$(readlink -f "$0")"
SCRIPT_DIR="$(dirname "$SCRIPT_PATH")"
# Om scriptet körs via symlink, använd symlänskens katalog (repomappen)
if [ -L "$0" ]; then
    LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"
else
    LOCAL_DIR="$SCRIPT_DIR"
fi

ENV="${1:-staging}"
if [ "$ENV" != "staging" ] && [ "$ENV" != "production" ]; then
    echo "FEL: Ange miljö: ./deploy-ssh.sh [staging|production]"
    exit 1
fi
ENV_UPPER=$(echo "$ENV" | tr '[:lower:]' '[:upper:]')

# Läs DEPLOY_SSH_<MILJÖ>_*-variabler från .env
set -a
source <(grep "^DEPLOY_SSH_${ENV_UPPER}_" "$LOCAL_DIR/.env" | sed "s/^DEPLOY_SSH_${ENV_UPPER}_/DEPLOY_SSH_/")
set +a

SSH_USER="$DEPLOY_SSH_USER"
SSH_HOST="$DEPLOY_SSH_HOST"
SSH_PATH="$DEPLOY_SSH_PATH"
APP_URL="$DEPLOY_SSH_URL"
PHP="$DEPLOY_SSH_PHP"

if [ -z "$SSH_USER" ] || [ -z "$SSH_PATH" ]; then
    echo "FEL: DEPLOY_SSH_${ENV_UPPER}_USER och DEPLOY_SSH_${ENV_UPPER}_PATH måste vara satta i .env"
    exit 1
fi

SSH="ssh ${SSH_USER}@${SSH_HOST}"

# Lös upp relativa sökvägar mot fjärrserverns hemkatalog (Loopia kan flytta volymer)
if [[ "$SSH_PATH" != /* ]]; then
    REMOTE_HOME=$($SSH 'echo $HOME')
    SSH_PATH="${REMOTE_HOME}/${SSH_PATH}"
    echo "==> Sökväg på fjärrserver: ${SSH_PATH}"
fi

echo "==> Deployer till $ENV ($APP_URL)..."

echo "==> Pushar till GitHub..."
git -C "$LOCAL_DIR" push

echo "==> Bygger assets lokalt..."
(cd "$LOCAL_DIR" && npm run build)

echo "==> Laddar upp byggda assets..."
rsync -az --delete "$LOCAL_DIR/public/build/" "${SSH_USER}@${SSH_HOST}:${SSH_PATH}/public/build/"

echo "==> Aktiverar underhållsläge..."
$SSH "cd $SSH_PATH && $PHP artisan down --render='errors.503' --retry=60" 2>/dev/null || true

echo "==> Hämtar senaste koden..."
$SSH "cd $SSH_PATH && git clean -f resources/views/errors/ 2>/dev/null || true && git pull --ff-only"

echo "==> Tar databas-backup..."
$SSH "cd $SSH_PATH && $PHP artisan db:backup --commit=\$(git rev-parse HEAD)"

echo "==> Installerar PHP-beroenden..."
$SSH "cd $SSH_PATH && composer install --no-dev --optimize-autoloader --no-interaction --quiet"

echo "==> Kör migrationer..."
$SSH "cd $SSH_PATH && $PHP artisan migrate --force"

# Lägg inga engångskommandon här. Se "Kommandon efter deploy" i
# docs/guides/deployment.md:
#   - körs vid varje deploy och tål att köras om → app/Console/Commands/PostDeploy.php
#   - ska köras en gång (t.ex. konvertera data)  → en migration
#   - manuellt (t.ex. inställning i en extern portal) → "Efter deploy" i PR:en

echo "==> Rensar och värmer caches..."
# route:cache ska INTE köras för Livewire-appar — en cachad
# CompiledRouteCollection krockar med Livewire's
# InitialRender::registerRouteBeforeExistingRoutes() och ger 405 på
# POST-anrop (upptäckt i bsk, se dess tests/TestCase.php som rensar bort
# samma cache lokalt av samma skäl). route:clear tar bort en eventuell
# kvarvarande cache från innan den här fixen.
#
# cache:clear (appens data-cache, t.ex. Cache::rememberForever()) är INTE
# samma sak som config:cache (bakar ihop config-filerna). En migration som
# seedar t.ex. Hjalptext/NavigationItem via DB::table()->updateOrInsert()
# går aldrig via Eloquent och rensar därför aldrig deras cachade
# rememberForever()-listor — utan denna rad kan en redan varm cache dölja
# nyss seedad data tyst, på obestämd tid (upptäckt i bsk 2026-10-01: en
# hjälptext fanns i databasen direkt efter migrate, men syntes inte förrän
# cache:clear kördes för hand).
$SSH "cd $SSH_PATH && $PHP artisan config:cache && $PHP artisan cache:clear && $PHP artisan route:clear && $PHP artisan view:clear && $PHP artisan event:cache && rm -f storage/framework/views/livewire/classes/*.php"

# Efter config:cache, så att nya .env-värden (t.ex. DISCORD_PUBLIC_KEY) syns.
# Ett misslyckat steg ska inte lämna sajten i underhållsläge — vi varnar i
# stället i slutet.
echo "==> Kör steg efter deploy (behörigheter, ikoner, Discord)..."
POST_DEPLOY_FAILED=0
$SSH "cd $SSH_PATH && $PHP artisan app:post-deploy" || POST_DEPLOY_FAILED=1

echo "==> Startar om queue-worker (om aktiv)..."
$SSH "cd $SSH_PATH && $PHP artisan queue:restart" 2>/dev/null || true

echo "==> Stänger av underhållsläge..."
$SSH "cd $SSH_PATH && $PHP artisan up"

echo ""
if [ "$POST_DEPLOY_FAILED" = "1" ]; then
    echo "!!! VARNING: app:post-deploy misslyckades — se utskriften ovan och kör om:"
    echo "!!!   ssh ${SSH_USER}@${SSH_HOST} 'cd $SSH_PATH && $PHP artisan app:post-deploy'"
fi
echo "==> Klart! $APP_URL"
echo "==> Deploy avslutad: $(date '+%Y-%m-%d %H:%M:%S') (tog $((SECONDS / 60))m $((SECONDS % 60))s)"
