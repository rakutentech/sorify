#!/bin/bash
set -euo pipefail

if [ ! -f ~/.sorify ]; then
  echo "Error: ~/.sorify not found. Please create it with your Sorify credentials." >&2
  exit 1
fi

USERNAME=$(grep "^SORIFY_USERNAME" ~/.sorify | cut -d= -f2)
PASSWORD=$(grep "^SORIFY_PASSWORD" ~/.sorify | cut -d= -f2)

TOKEN=$(printf '%s:%s' "$USERNAME" "$PASSWORD" | base64)

printf '{"Authorization":"Basic %s"}' "$TOKEN"
