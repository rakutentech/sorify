#!/usr/bin/env bash
# Cross-compiles the sorify-recorder-mcp MCP server for macOS, Linux, and
# Windows. Output binaries land in mcp/sorify-recorder-mcp/dist/.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/sorify-recorder-mcp"

BIN_NAME="sorify-recorder-mcp"
DIST_DIR="dist"

TARGETS=(
  "darwin/amd64"
  "darwin/arm64"
  "linux/amd64"
  "linux/arm64"
  "windows/amd64"
)

rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

for target in "${TARGETS[@]}"; do
  os="${target%/*}"
  arch="${target#*/}"

  ext=""
  if [ "$os" = "windows" ]; then
    ext=".exe"
  fi

  out="$DIST_DIR/${BIN_NAME}-${os}-${arch}${ext}"
  echo "Building $out"
  GOOS="$os" GOARCH="$arch" CGO_ENABLED=0 go build -trimpath -o "$out" .
done

echo
echo "Done. Binaries in $(pwd)/$DIST_DIR:"
ls -la "$DIST_DIR"
