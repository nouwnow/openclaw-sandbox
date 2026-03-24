#!/usr/bin/env bash
# backup.sh — Stop OpenClaw VM en maak backup
# Gebruik: ./backup.sh
#
# Na de backup start je zelf opnieuw:
#   Terminal 1: cd ~/openclaw-sandbox && ./result/bin/virtiofsd-run
#   Terminal 2: cd ~/openclaw-sandbox && ./result/bin/microvm-run

set -euo pipefail

SANDBOX="$HOME/openclaw-sandbox"
BACKUP_DATE=$(date +%Y-%m-%d)
BACKUP_DIR="$HOME/Documents/OpenClaw-Backup/$BACKUP_DATE"

log() { echo "[$(date +%H:%M:%S)] $*"; }

log "=== OpenClaw Backup ==="
log "Doelmap: $BACKUP_DIR"
echo ""

# ─────────────────────────────────────────────────────────
# 1. STOP DE VM
# ─────────────────────────────────────────────────────────
log "Stap 1/3 — VM afsluiten..."

if pgrep -f "microvm@openclaw-agent" > /dev/null 2>&1; then
    if [[ -S "$SANDBOX/control.sock" ]]; then
        curl -sf --unix-socket "$SANDBOX/control.sock" \
            -X PUT http://localhost/vm.shutdown > /dev/null \
            && log "  Shutdown signaal verstuurd"
    fi

    for i in $(seq 1 30); do
        if ! pgrep -f "microvm@openclaw-agent" > /dev/null 2>&1; then
            log "  VM gestopt na ${i}s"
            break
        fi
        sleep 1
        if [[ $i -eq 30 ]]; then
            log "  Timeout — force kill VM..."
            pkill -f "microvm@openclaw-agent" || true
            sleep 2
        fi
    done
else
    log "  VM was al gestopt"
fi

# ─────────────────────────────────────────────────────────
# 2. STOP VIRTIOFSD
# ─────────────────────────────────────────────────────────
log "Stap 2/3 — virtiofsd stoppen..."

pkill -9 -f "virtiofsd.*openclaw" 2>/dev/null || true
pkill -9 -f "supervisord.*openclaw" 2>/dev/null || true
sleep 2

rm -f "$SANDBOX"/openclaw-agent-virtiofs*.sock
rm -f "$SANDBOX"/openclaw-agent-virtiofs*.sock.pid

log "  virtiofsd gestopt en sockets opgeruimd"

# ─────────────────────────────────────────────────────────
# 3. BACKUP
# ─────────────────────────────────────────────────────────
log "Stap 3/3 — Backup maken..."
mkdir -p "$BACKUP_DIR"

log "  openclaw-sandbox/ ..."
rsync -a --info=progress2 \
    --exclude='result' \
    --exclude='*.sock' \
    --exclude='*.sock.pid' \
    --exclude='supervisord.log' \
    --exclude='supervisord.pid' \
    --exclude='notify.vsock' \
    --exclude='control.sock' \
    "$SANDBOX/" "$BACKUP_DIR/openclaw-sandbox/"

log "  openclaw-workspace/ ..."
rsync -a --info=progress2 \
    "$HOME/openclaw-workspace/" "$BACKUP_DIR/openclaw-workspace/"

BACKUP_SIZE=$(du -sh "$BACKUP_DIR" | cut -f1)

echo ""
log "=== Backup klaar ==="
log "Locatie: $BACKUP_DIR ($BACKUP_SIZE)"
echo ""
log "Start de omgeving nu zelf opnieuw:"
log "  Terminal 1:  cd ~/openclaw-sandbox && ./result/bin/virtiofsd-run"
log "  Terminal 2:  cd ~/openclaw-sandbox && ./result/bin/microvm-run"
