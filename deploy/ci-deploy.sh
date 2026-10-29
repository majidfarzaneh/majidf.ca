#!/usr/bin/env bash
# Forced command for the GitHub Actions deploy key in /root/.ssh/authorized_keys:
#
#   command="/www/wwwroot/majidf.ca/deploy/ci-deploy.sh",restrict ssh-ed25519 AAAA… github-actions-deploy@majidf.ca
#
# The key can do nothing but deploy a commit that is already on origin/main, so a leaked
# secret gives no shell. The workflow sends "deploy <sha>" as the SSH command and pipes a
# short-lived GHCR token on stdin.
set -euo pipefail

APP_DIR=/www/wwwroot/majidf.ca

read -r action sha extra <<<"${SSH_ORIGINAL_COMMAND:-}" || true
if [ "$action" != "deploy" ] || ! [[ "$sha" =~ ^[0-9a-f]{40}$ ]] || [ -n "${extra:-}" ]; then
  echo "usage: deploy <40-char sha>" >&2
  exit 2
fi

cd "$APP_DIR"
git fetch --quiet origin
if ! git merge-base --is-ancestor "$sha" origin/main; then
  echo "$sha is not on origin/main" >&2
  exit 2
fi

# Log in to GHCR with the workflow's token (images may be private).
token=$(cat)
if [ -n "$token" ]; then
  printf '%s' "$token" | docker login ghcr.io -u majidfarzaneh --password-stdin >/dev/null
fi

exec bash "$APP_DIR/deploy/deploy.sh" "$sha"
