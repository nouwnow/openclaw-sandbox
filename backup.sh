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
# 1. STOP DE VM (met graceful gateway shutdown)
# ─────────────────────────────────────────────────────────
log "Stap 1/3 — VM afsluiten..."

if pgrep -f "microvm@openclaw-agent" > /dev/null 2>&1; then
    # Eerst gateways graceful stoppen voor data integriteit
    if [[ -S "$SANDBOX/control.sock" ]]; then
        log "  Gateways graceful stoppen..."
        
        # Stuur stop commando naar alle gateways
        curl -sf --unix-socket "$SANDBOX/control.sock" \
            -X PUT http://localhost/vm.exec -H "Content-Type: application/json" \
            -d '{"cmd": ["systemctl", "stop", "openclaw-gateway", "openclaw-gateway-project-a", "openclaw-dashboard"]}' \
            && log "  Gateway stop signaal verstuurd"
        
        # Wacht maximaal 30 seconden voor gateways om te stoppen
        log "  Wachten op gateway shutdown (max 30s)..."
        GATEWAY_TIMEOUT=30
        GATEWAY_STOPPED=false
        
        for i in $(seq 1 $GATEWAY_TIMEOUT); do
            # Controleer of gateways nog draaien door individueel te checken
            # We gebruiken een eenvoudige check: probeer een curl naar localhost
            # Als alle services gestopt zijn, zouden de poorten niet meer beschikbaar zijn
            SERVICES_RUNNING=0
            
            # Check main gateway (poort 18789)
            curl -sf --unix-socket "$SANDBOX/control.sock" \
                -X PUT http://localhost/vm.exec -H "Content-Type: application/json" \
                -d '{"cmd": ["timeout", "1", "bash", "-c", "echo > /dev/tcp/127.0.0.1/18789"]}' > /dev/null 2>&1 && SERVICES_RUNNING=$((SERVICES_RUNNING + 1))
            
            # Check project-a gateway (poort 18790)
            curl -sf --unix-socket "$SANDBOX/control.sock" \
                -X PUT http://localhost/vm.exec -H "Content-Type: application/json" \
                -d '{"cmd": ["timeout", "1", "bash", "-c", "echo > /dev/tcp/127.0.0.1/18790"]}' > /dev/null 2>&1 && SERVICES_RUNNING=$((SERVICES_RUNNING + 1))
            
            # Check dashboard (poort 3333)
            curl -sf --unix-socket "$SANDBOX/control.sock" \
                -X PUT http://localhost/vm.exec -H "Content-Type: application/json" \
                -d '{"cmd": ["timeout", "1", "bash", "-c", "echo > /dev/tcp/127.0.0.1/3333"]}' > /dev/null 2>&1 && SERVICES_RUNNING=$((SERVICES_RUNNING + 1))
            
            # Als geen van de services meer draait, zijn ze gestopt
            if [[ $SERVICES_RUNNING -eq 0 ]]; then
                log "  Alle gateways gestopt na ${i}s"
                GATEWAY_STOPPED=true
                break
            elif [[ $i -eq 5 ]]; then
                log "  Nog $SERVICES_RUNNING service(s) draaien..."
            fi
            
            sleep 1
            
            if [[ $i -eq 15 ]]; then
                log "  Gateways nemen lang... probeer force stop..."
                # Probeer force stop als het te lang duurt
                curl -sf --unix-socket "$SANDBOX/control.sock" \
                    -X PUT http://localhost/vm.exec -H "Content-Type: application/json" \
                    -d '{"cmd": ["systemctl", "kill", "-s", "SIGKILL", "openclaw-gateway", "openclaw-gateway-project-a", "openclaw-dashboard"]}' > /dev/null 2>&1
            fi
        done
        
        if [[ "$GATEWAY_STOPPED" == false ]]; then
            log "  Gateway shutdown timeout — ga door met VM shutdown"
        fi
    fi
    
    # Nu VM shutdown (alleen als gateways gestopt zijn of timeout)
    if [[ -S "$SANDBOX/control.sock" ]]; then
        log "  VM shutdown signaal versturen..."
        curl -sf --unix-socket "$SANDBOX/control.sock" \
            -X PUT http://localhost/vm.shutdown > /dev/null \
            && log "  VM shutdown signaal verstuurd"
    fi

    # Wacht op VM shutdown met timeout
    log "  Wachten op VM shutdown (max 30s)..."
    for i in $(seq 1 30); do
        if ! pgrep -f "microvm@openclaw-agent" > /dev/null 2>&1; then
            log "  VM gestopt na ${i}s"
            break
        fi
        sleep 1
        if [[ $i -eq 30 ]]; then
            log "  VM shutdown timeout — force kill..."
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

# Clean up all socket and PID files to prevent connection issues on restart
rm -f "$SANDBOX"/openclaw-agent-virtiofs*.sock
rm -f "$SANDBOX"/openclaw-agent-virtiofs*.sock.pid
rm -f "$SANDBOX"/control.sock
rm -f "$SANDBOX"/notify.vsock
rm -f "$SANDBOX"/*.sock "$SANDBOX"/*.sock.pid 2>/dev/null || true

log "  virtiofsd gestopt en alle socket bestanden opgeruimd"

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
