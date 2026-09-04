#!/usr/bin/env bash
# Find Job with AI — server-side deploy step.
#
# Runs ON the server as `ubuntu`, after `git push server HEAD:demo-deploy`
# from the workstation has checked the branch out in /var/www/findjob
# (the repo has receive.denyCurrentBranch=updateInstead). Driven by deploy.sh
# in the mkado-dev workspace:
#     ./deploy.sh findjob            # backend only
#     ./deploy.sh findjob --assets   # also rebuild public/build (Vite) on the workstation and upload it
# The server has no Node, so public/build is built locally and uploaded by the
# wrapper; it is gitignored and left alone here.
# Never creates or edits .env or database/database.sqlite (the demo data).
set -euo pipefail
cd /var/www/findjob
[ -f .env ] || { echo "!! /var/www/findjob/.env is missing; this script never creates it" >&2; exit 1; }
composer install --optimize-autoloader --no-interaction --no-progress --quiet
touch database/database.sqlite
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache || php artisan route:clear
php artisan view:cache
sudo systemctl reload php8.3-fpm
echo "findjob: live at $(git rev-parse --short HEAD) — $(git log -1 --format=%s | cut -c1-70)"
