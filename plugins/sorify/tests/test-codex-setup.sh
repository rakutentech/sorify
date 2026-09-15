#!/usr/bin/env bash
set -euo pipefail

PLUGIN_ROOT=$(cd -- "$(dirname -- "$0")/.." && pwd)
SETUP_SCRIPT="$PLUGIN_ROOT/scripts/setup-codex.sh"
AUTH_HELPER="$PLUGIN_ROOT/scripts/mcp-auth-headers.sh"
TEST_ROOT=$(mktemp -d)

cleanup() {
  rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

export HOME="$TEST_ROOT/home"
unset SORIFY_URL
mkdir -p "$HOME/.codex"

cat > "$HOME/.codex/config.toml" <<'EOF'
[mcp_servers.sorify]
url = "https://old.example/sorify/mcp"
http_headers_helper = "/old/helper"

[mcp_servers.other]
url = "https://other.example/mcp"
EOF
cp "$HOME/.codex/config.toml" "$TEST_ROOT/original-config.toml"

cat > "$HOME/.sorify" <<'EOF'
SORIFY_URL=https://new.example/sorify
SORIFY_USERNAME_HINT=not-the-username
SORIFY_USERNAME=qa=user
SORIFY_PASSWORD_HINT=not-the-password
SORIFY_PASSWORD=abc=def
EOF

bash "$SETUP_SCRIPT"

cmp "$HOME/.codex/config.toml.bak" "$TEST_ROOT/original-config.toml"
rg -qx 'url = "https://new.example/sorify/mcp"' "$HOME/.codex/config.toml"
rg -qx "http_headers_helper = \"$AUTH_HELPER\"" "$HOME/.codex/config.toml"
rg -qx 'url = "https://other.example/mcp"' "$HOME/.codex/config.toml"

expected_token=$(printf '%s:%s' 'qa=user' 'abc=def' | base64)
actual_headers=$(bash "$AUTH_HELPER")
[ "$actual_headers" = "{\"Authorization\":\"Basic $expected_token\"}" ]

sed -i.bak 's|SORIFY_URL=https://new.example/sorify|SORIFY_URL=https://newer.example/sorify|' "$HOME/.sorify"
bash "$SETUP_SCRIPT"
rg -qx 'url = "https://newer.example/sorify/mcp"' "$HOME/.codex/config.toml"

echo 'Codex Sorify setup tests passed.'
