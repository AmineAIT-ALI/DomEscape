#!/bin/bash

# =============================================================
# sync.sh — Synchronise le projet vers le webroot Apache
# Usage : ./sync.sh
# =============================================================

SRC="/Users/amineaitali/Desktop/DomEscape/domescape/"
DST="/Library/WebServer/Documents/domescape/"

echo "Synchronisation vers $DST ..."

sudo rsync -av --delete \
    --exclude='logs/*.log' \
    --exclude='logs/*.tmp' \
    "$SRC" "$DST"

# Permissions : dossiers 755, fichiers 644
sudo find "$DST" -type d -exec chmod 755 {} \;
sudo find "$DST" -type f -exec chmod 644 {} \;

echo "Done."
