#!/usr/bin/env bash
# wpe-run-php.sh — run a one-off PHP script against a WP Engine-hosted site's live WordPress,
# using wp-cli's update_field()/get_field()/etc. Standardizes the SFTP-upload + wp eval-file
# dance every WP Engine content edit otherwise reinvents (see docs/content-editing.md,
# FRICTION 2026-10-13, memory [[wp-engine-ssh-gateway-limitation]]).
#
# WHY TWO CHANNELS: WP Engine's git-push SSH gateway (the key-based `wp` access) is a forced-
# command dispatcher with no real shell — it can't take multi-word arguments (so `wp eval "..."`
# and any string field with spaces fails), and has no SFTP/SCP subsystem. WP Engine's SEPARATE
# SFTP feature (its own host/port/user/password, nothing to do with the SSH key) can upload
# files. So: upload the PHP file over SFTP, then run it with `wp eval-file <path>` over the SSH
# gateway (one argument, no spaces — fine), then delete it over SFTP again.
#
# Usage:
#   WPE_INSTALL=podwebnav7945 \
#   WPE_SSH_KEY=~/.ssh/id_ed25519_pod \
#   WPE_SFTP_USER=podwebnav7945-jacob \
#   WPE_SFTP_PASSWORD_FILE=~/.pod-secrets/website-navigator-sftp-password.txt \
#     ./wp/cli/wpe-run-php.sh ./my-update.php
#
# Required env vars (put these in a per-client .env.wpe-cli file, gitignored, and `source` it —
# never hardcode a client's install/host/key in this script):
#   WPE_INSTALL             - WP Engine install name, e.g. podwebnav7945
#   WPE_SSH_KEY              - path to the git-push SSH private key for this install
#   WPE_SFTP_USER             - the WP Engine portal SFTP username (NOT the SSH gateway user)
#   WPE_SFTP_PASSWORD_FILE    - path to a file containing ONLY the SFTP password (chmod 600)
#
# Optional:
#   WPE_SSH_HOST    - defaults to "${WPE_INSTALL}.ssh.wpengine.net"
#   WPE_SFTP_HOST    - defaults to "${WPE_INSTALL}.sftp.wpengine.com"

set -euo pipefail

PHP_FILE="${1:?Usage: wpe-run-php.sh <local-php-file>}"
[ -f "$PHP_FILE" ] || { echo "No such file: $PHP_FILE" >&2; exit 1; }

: "${WPE_INSTALL:?Set WPE_INSTALL (WP Engine install name)}"
: "${WPE_SSH_KEY:?Set WPE_SSH_KEY (path to the git-push SSH key)}"
: "${WPE_SFTP_USER:?Set WPE_SFTP_USER (WP Engine portal SFTP username)}"
: "${WPE_SFTP_PASSWORD_FILE:?Set WPE_SFTP_PASSWORD_FILE (path to the SFTP password file)}"

WPE_SSH_HOST="${WPE_SSH_HOST:-${WPE_INSTALL}.ssh.wpengine.net}"
WPE_SFTP_HOST="${WPE_SFTP_HOST:-${WPE_INSTALL}.sftp.wpengine.com}"

SSH_CMD=(ssh -i "$WPE_SSH_KEY" "${WPE_INSTALL}@${WPE_SSH_HOST}")
REMOTE_NAME="$(basename "$PHP_FILE")"

echo "→ resolving site root over the SSH gateway..." >&2
CONFIG_PATH="$("${SSH_CMD[@]}" "wp config path")"
SITE_ROOT="$(dirname "$CONFIG_PATH")"
REMOTE_PATH="${SITE_ROOT}/${REMOTE_NAME}"

cleanup() {
  echo "→ cleaning up uploaded script..." >&2
  sshpass -f "$WPE_SFTP_PASSWORD_FILE" sftp -P 2222 -o StrictHostKeyChecking=no \
    "${WPE_SFTP_USER}@${WPE_SFTP_HOST}" >/dev/null 2>&1 <<EOF || true
rm /${REMOTE_NAME}
EOF
}
trap cleanup EXIT

echo "→ uploading ${PHP_FILE} via SFTP..." >&2
sshpass -f "$WPE_SFTP_PASSWORD_FILE" sftp -P 2222 -o StrictHostKeyChecking=no \
  "${WPE_SFTP_USER}@${WPE_SFTP_HOST}" <<EOF
put ${PHP_FILE}
EOF

echo "→ running wp eval-file ${REMOTE_PATH}..." >&2
"${SSH_CMD[@]}" "wp eval-file ${REMOTE_PATH}"
