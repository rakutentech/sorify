#!/bin/bash
set -euo pipefail

if [ ! -f ~/.sorify ]; then
  echo "Error: ~/.sorify not found. Please create it with your Sorify credentials." >&2
  exit 1
fi

read_credential() {
  local key="$1"
  local line

  while IFS= read -r line || [ -n "$line" ]; do
    if [[ "$line" == "$key="* ]]; then
      printf '%s' "${line#*=}"
      return 0
    fi
  done < "$HOME/.sorify"

  return 1
}

if ! USERNAME=$(read_credential SORIFY_USERNAME); then
  echo "Error: SORIFY_USERNAME is missing from ~/.sorify." >&2
  exit 1
fi

if ! PASSWORD=$(read_credential SORIFY_PASSWORD); then
  echo "Error: SORIFY_PASSWORD is missing from ~/.sorify." >&2
  exit 1
fi

TOKEN=$(printf '%s:%s' "$USERNAME" "$PASSWORD" | base64)

printf '{"Authorization":"Basic %s"}' "$TOKEN"
