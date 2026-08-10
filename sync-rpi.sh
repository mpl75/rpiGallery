#!/usr/bin/env bash
#
# Jednosměrný deploy lokálního repa rpiGallery na Raspberry Pi.
# Lokál = zdroj pravdy. Soubory smazané lokálně se smažou i na serveru (--delete),
# KROMĚ chráněných výjimek (config, náhledy, runtime stavy, certifikáty).
#
# Použití:
#   ./sync-rpi.sh       # ostrý deploy (vč. mazání na serveru)
#   ./sync-rpi.sh -n    # dry-run: jen vypíše, co by se stalo, nic nezmění
#
# Chráněné výjimky (nikdy se nenahrají ani nesmažou na serveru):
#   config.json         reálné bcrypt hashe + authSecret žijí jen na serveru
#   thumbnails/ fullsize symlinky na RAID (/mnt/md1)
#   .well-known/        Let's Encrypt ACME challenge
#   shares.json         živé share odkazy
#   crawler.json/.pid/.stop, backup.log, *.log   runtime stav
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REMOTE_HOST="michal@raspberrypi.local"
REMOTE_DIR="/var/www/html/rpiGallery"

DRY=""
if [ "${1:-}" = "-n" ] || [ "${1:-}" = "--dry-run" ]; then
  DRY="--dry-run"
  echo "=== DRY RUN — nic se nezmění ==="
fi

rsync -rltv --delete $DRY \
  --rsync-path="sudo rsync" \
  --exclude='.git/' \
  --exclude='.gitignore' \
  --exclude='.gitmodules' \
  --exclude='.claude/' \
  --exclude='CLAUDE.md' \
  --exclude='INFRA.local.md' \
  --exclude='.DS_Store' \
  --exclude='sync-rpi.sh' \
  --exclude='*.bak' --exclude='*.bak-*' \
  --exclude='config.json' \
  --exclude='thumbnails' \
  --exclude='fullsize' \
  --exclude='.well-known/' \
  --exclude='shares.json' \
  --exclude='crawler.json' \
  --exclude='crawler.pid' \
  --exclude='crawler.stop' \
  --exclude='backup.log' \
  --exclude='*.log' \
  "$SCRIPT_DIR/" "$REMOTE_HOST:$REMOTE_DIR/"

if [ -z "$DRY" ]; then
  # sudo rsync zapsal soubory jako root -> dorovnat na www-data (mimo symlinky a .well-known)
  ssh "$REMOTE_HOST" \
    "sudo find '$REMOTE_DIR' -path '$REMOTE_DIR/.well-known' -prune -o -not -type l -exec chown www-data:www-data {} +"
  echo "=== hotovo: nasazeno + chown www-data ==="
else
  echo "=== dry-run konec — pro ostrý běh spusť bez -n ==="
fi
