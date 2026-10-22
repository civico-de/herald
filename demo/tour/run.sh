#!/usr/bin/env bash
# Records demo/tour/out/herald-tour.mp4 and writes the handbook screenshots in
# English and German, against the running dev stack (.docker).
set -euo pipefail
cd "$(dirname "$0")/../.."

compose() { docker compose -f .docker/docker-compose.yml "$@"; }
cv() { compose exec -T app cv "$@"; }
port() { compose port "$1" "$2" | sed 's/.*://'; }

export TOUR_BASE_URL="http://localhost:$(port app 80)"
export TOUR_MAILDEV_URL="http://localhost:$(port maildev 1080)"
mkdir -p demo/tour/out handbook/images/de
trap 'cv api4 Setting.set +v lcMessages=en_US >/dev/null' EXIT

cv scr /var/www/html/ext/herald/demo/digests.php
cv scr /var/www/html/ext/herald/demo/tour/prepare.php > demo/tour/out/state.json
curl -fsS -X DELETE "$TOUR_MAILDEV_URL/email/all" >/dev/null

cv api4 Setting.set +v lcMessages=en_US >/dev/null
TOUR_LOCALE=en npx playwright test -c demo/tour/playwright.config.ts
video="$(find demo/tour/out/results -name '*.webm' | head -1)"
ffmpeg -y -loglevel error -i "$video" -c:v libx264 -pix_fmt yuv420p -movflags +faststart demo/tour/out/herald-tour.mp4

cv scr /var/www/html/ext/herald/demo/tour/prepare.php > demo/tour/out/state.json
cv api4 Setting.set +v lcMessages=de_DE >/dev/null
TOUR_LOCALE=de npx playwright test -c demo/tour/playwright.config.ts

echo "video: demo/tour/out/herald-tour.mp4"
