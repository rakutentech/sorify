#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "$0")" && pwd)
HELPER_PATH="$SCRIPT_DIR/mcp-auth-headers.sh"
CONFIG_PATH="${CODEX_HOME:-$HOME/.codex}/config.toml"

if [ ! -x "$HELPER_PATH" ]; then
  echo "Error: MCP auth helper is not executable: $HELPER_PATH" >&2
  exit 1
fi

if [ ! -f "$HOME/.sorify" ]; then
  echo "Error: ~/.sorify not found. Please create it with your Sorify credentials." >&2
  exit 1
fi

if [ -z "${SORIFY_URL:-}" ] && [ -f "$HOME/.sorify" ]; then
  SORIFY_URL=$(sed -n 's/^SORIFY_URL=//p' "$HOME/.sorify" | head -n 1)
fi

if [ -z "${SORIFY_URL:-}" ]; then
  echo "Error: SORIFY_URL is not set and ~/.sorify was not found." >&2
  exit 1
fi

if [ -f "$CONFIG_PATH" ]; then
  cp "$CONFIG_PATH" "${CONFIG_PATH}.bak"
fi

mkdir -p "$(dirname -- "$CONFIG_PATH")"

python3 - "$CONFIG_PATH" "$HELPER_PATH" "${SORIFY_URL%/}/mcp" <<'PY'
import json
import pathlib
import re
import sys

config_path = pathlib.Path(sys.argv[1])
helper_path = sys.argv[2]
url = sys.argv[3]
helper_line = f"http_headers_helper = {json.dumps(helper_path)}"
url_line = f"url = {json.dumps(url)}"

lines = config_path.read_text(encoding="utf-8").splitlines() if config_path.exists() else []
section_start = None
section_end = len(lines)

for index, line in enumerate(lines):
    if line.strip() == "[mcp_servers.sorify]":
        section_start = index
        continue
    if section_start is not None and line.startswith("["):
        section_end = index
        break

if section_start is None:
    if lines and lines[-1].strip():
        lines.append("")
    lines.extend(["[mcp_servers.sorify]", url_line, helper_line])
else:
    fields = {
        "url": (url_line, None),
        "http_headers_helper": (helper_line, None),
    }
    for index in range(section_start + 1, section_end):
        match = re.match(r"^\s*(url|http_headers_helper)\s*=", lines[index])
        if match:
            key = match.group(1)
            replacement, _ = fields[key]
            lines[index] = replacement
            fields[key] = (replacement, index)

    insertion_index = section_end
    for key in ("url", "http_headers_helper"):
        replacement, index = fields[key]
        if index is None:
            lines.insert(insertion_index, replacement)
            insertion_index += 1

config_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
PY

echo "Configured Codex Sorify MCP authentication."
echo "  MCP URL: ${SORIFY_URL%/}/mcp"
echo "  Header helper: $HELPER_PATH"
echo "  Config: $CONFIG_PATH"
echo "Restart Codex and start a new session."
