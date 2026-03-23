#!/usr/bin/env bash
# build-plugin-overlay.sh — Bouw de Openclaw bundled plugins overlay
#
# WAAROM: De Nix-package kopieert openclaw.plugin.json manifests niet naar de dist-output.
# Openclaw slaat plugins zonder manifest over — Discord (en alle andere 74 plugins) worden
# nooit geladen. Dit script maakt een writable overlay aan met echte bestanden (geen symlinks
# — die worden geblokkeerd door checkSourceEscapesRoot()) die de plugin code re-exporteren
# via ESM wrappers met absolute Nix store paden.
#
# GEBRUIK:
#   ./build-plugin-overlay.sh
#
# NA EEN NIX BUILD UPGRADE:
#   De Nix store hash verandert — draai dit script opnieuw en herstart de gateway:
#   sudo systemctl restart openclaw-gateway  (in de VM)
#
set -euo pipefail

OVERLAY="${OPENCLAW_BUNDLED_PLUGINS_DIR:-/home/agent/workspace/.openclaw-bundled-plugins}"

# Zoek de huidige openclaw-gateway in de Nix store
NIX_PKG=$(ls -d /nix/store/*-openclaw-gateway-*/lib/openclaw 2>/dev/null | head -1)

if [[ -z "$NIX_PKG" ]]; then
  echo "ERROR: openclaw-gateway niet gevonden in Nix store."
  echo "       Voer eerst 'nix build' uit in ~/openclaw-sandbox."
  exit 1
fi

EXTENSIONS="$NIX_PKG/dist/extensions"

if [[ ! -d "$EXTENSIONS" ]]; then
  echo "ERROR: extensions map niet gevonden: $EXTENSIONS"
  exit 1
fi

echo "Nix package : $NIX_PKG"
echo "Overlay     : $OVERLAY"
echo ""

mkdir -p "$OVERLAY"

ok=0
skip=0

for plugin_dir in "$EXTENSIONS"/*/; do
  plugin=$(basename "$plugin_dir")
  out="$OVERLAY/$plugin"
  mkdir -p "$out"

  # Manifest: zoek in broncode tree (packages/extensions/<plugin>/)
  manifest=""
  if [[ -f "$plugin_dir/openclaw.plugin.json" ]]; then
    manifest="$plugin_dir/openclaw.plugin.json"
  else
    found=$(find /nix/store -path "*/extensions/$plugin/openclaw.plugin.json" 2>/dev/null | head -1 || true)
    if [[ -n "$found" ]]; then
      manifest="$found"
    fi
  fi

  if [[ -z "$manifest" ]]; then
    echo "  SKIP $plugin (geen manifest)"
    rm -rf "$out"
    skip=$((skip + 1))
    continue
  fi

  rm -f "$out/openclaw.plugin.json"
  cp "$manifest" "$out/openclaw.plugin.json"

  # ESM wrapper voor index.js
  if [[ -f "$plugin_dir/index.js" ]]; then
    cat > "$out/index.js" << WRAPPER
// openclaw Nix overlay wrapper — re-exports compiled plugin from read-only Nix store
export * from '$plugin_dir/index.js';
export { default } from '$plugin_dir/index.js';
WRAPPER
  fi

  # ESM wrapper voor setup-entry.js (indien aanwezig)
  if [[ -f "$plugin_dir/setup-entry.js" ]]; then
    cat > "$out/setup-entry.js" << WRAPPER
// openclaw Nix overlay wrapper — re-exports compiled plugin from read-only Nix store
export * from '$plugin_dir/setup-entry.js';
export { default } from '$plugin_dir/setup-entry.js';
WRAPPER
  fi

  echo "  OK  $plugin"
  ok=$((ok + 1))
done

echo ""
echo "Klaar: $ok plugins aangemaakt, $skip overgeslagen."
echo ""
echo "Zorg dat OPENCLAW_BUNDLED_PLUGINS_DIR=$OVERLAY in .env staat."
echo "Herstart de gateway: sudo systemctl restart openclaw-gateway"
