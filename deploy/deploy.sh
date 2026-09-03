#!/usr/bin/env bash
# Deploy/update this app at /var/www/findjob on the VPS. Run as the `deploy` user.
#
# This only pulls PHP code — the built frontend (public/build) is produced
# locally with `npm run build` and uploaded separately, e.g.:
#   rsync -av public/build/ deploy@findjob.mkado.dev:/var/www/findjob/public/build/
set -euo pipefail
cd /var/www/findjob

git pull origin demo-deploy
composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
    cp .env.production.example .env
    php artisan key:generate --force
fi

touch database/database.sqlite
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo systemctl reload php8.3-fpm
echo "Find Job with AI deployed. Remember to rsync public/build/ if the frontend changed."
