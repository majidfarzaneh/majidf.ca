#!/usr/bin/env bash
# Deploy a commit to production. Run as root on 91.99.207.212. GitHub Actions
# (.github/workflows/deploy.yml) triggers it through deploy/ci-deploy.sh after the
# tests pass and both images for the commit are in GHCR. Also safe to run by hand:
#
#   bash deploy/deploy.sh            # latest origin/main
#   bash deploy/deploy.sh <sha>      # roll back / forward to a commit (its images must exist in GHCR)
set -euo pipefail

APP_DIR=/www/wwwroot/majidf.ca
DOMAIN=majidf.ca
REF=${1:-origin/main}
COMPOSE="docker compose -f $APP_DIR/docker-compose.prod.yml"

# Wrapped in main(): bash reads the whole function before running it, so the
# `git reset` below can rewrite this file mid-deploy without corrupting the run.
main() {
  log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
  cd "$APP_DIR"

  log "Code → $REF"
  git fetch --quiet origin
  git reset --hard "$REF"
  local sha
  sha=$(git rev-parse HEAD)
  git log -1 --format='%h %s'

  # Compose reads .env for interpolation only: the image tag and the MySQL/Redis credentials.
  # Laravel itself reads .env.production.
  {
    echo "IMAGE_TAG=$sha"
    grep -E '^(REDIS_PASSWORD|DB_DATABASE|DB_USERNAME|DB_PASSWORD|DB_ROOT_PASSWORD)=' .env.production
  } > .env
  chmod 600 .env

  log "Pulling images $sha"
  $COMPOSE pull --quiet

  # The app container migrates and builds its caches on start (docker/php/entrypoint.sh);
  # queue, scheduler and web wait for it to be healthy.
  log "Starting containers"
  $COMPOSE up -d --remove-orphans --wait --wait-timeout 180

  log "Health check"
  for _ in $(seq 1 20); do
    if curl -fsS -o /dev/null -H "Host: $DOMAIN" -H 'X-Forwarded-Proto: https' http://127.0.0.1:8094/up \
      && curl -fsS -o /dev/null -H "Host: $DOMAIN" -H 'X-Forwarded-Proto: https' http://127.0.0.1:8094/; then
      echo "healthy"
      docker image prune -f --filter "until=168h" >/dev/null || true
      exit 0
    fi
    sleep 3
  done
  echo "health check FAILED"
  $COMPOSE ps
  $COMPOSE logs --tail 60 app web
  exit 1
}

main "$@"
