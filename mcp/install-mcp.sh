#!/bin/sh
# Installs the sorify-recorder-mcp MCP server binary for the current OS/arch.
# Usage: curl -fsSL https://raw.githubusercontent.com/rakutentech/sorify/main/mcp/install-mcp.sh | sh
set -e

REPO='rakutentech/sorify'
THIS_PROJECT_NAME='sorify-recorder-mcp'
BIN_DIR="${BIN_DIR:-$HOME/.sorify-bin}"
THE_ARCH_BIN=''

THISOS=$(uname -s)
ARCH=$(uname -m)

case $THISOS in
   Linux*)
      case $ARCH in
        arm64|aarch64)
          THE_ARCH_BIN="$THIS_PROJECT_NAME-linux-arm64"
          ;;
        *)
          THE_ARCH_BIN="$THIS_PROJECT_NAME-linux-amd64"
          ;;
      esac
      ;;
   Darwin*)
      case $ARCH in
        arm64)
          THE_ARCH_BIN="$THIS_PROJECT_NAME-darwin-arm64"
          ;;
        *)
          THE_ARCH_BIN="$THIS_PROJECT_NAME-darwin-amd64"
          ;;
      esac
      ;;
   Windows|MINGW64_NT*|MSYS_NT*)
      THE_ARCH_BIN="$THIS_PROJECT_NAME-windows-amd64.exe"
      THIS_PROJECT_NAME="$THIS_PROJECT_NAME.exe"
      ;;
esac

if [ -z "$THE_ARCH_BIN" ]; then
   echo "This script is not supported on $THISOS and $ARCH"
   exit 1
fi

mkdir -p "$BIN_DIR"

curl -kL --progress-bar "https://github.com/$REPO/releases/latest/download/$THE_ARCH_BIN" -o "$BIN_DIR/$THIS_PROJECT_NAME"
chmod +x "$BIN_DIR/$THIS_PROJECT_NAME"

echo "Installed successfully to: $BIN_DIR/$THIS_PROJECT_NAME"
