# Openclaw Setup

Van een werkende VM naar een actieve Openclaw-agent met Discord en multi-agent content pipeline.

**Aanpak:** Declaratief via `nix-openclaw` NixOS module. Claude Code beheert de configuratie in `flake.nix` — geen interactieve wizard nodig.

---

## Architectuur

```
Discord  →  Openclaw Gateway (coordinator)  →  instances: writer / researcher / editor
                      ↓
             /home/agent/workspace/.openclaw*    (virtiofs → ~/openclaw-workspace/)
```

- **Coordinator** (poort 18789) — ontvangt Discord-berichten, verdeelt taken
- **Writer** (poort 18790) — schrijft content op basis van briefing
- **Researcher** (poort 18791) — verzamelt bronnen en achtergrondinfo
- **Editor** (poort 18792) — redigeert en finaliseert output

Alle state wordt opgeslagen in `~/openclaw-workspace/.openclaw*` via virtiofs → persistent na reboots.

---

## Stap 1 — OAuth token ophalen

In de VM:
```bash
claude setup-token
# Kopieer de sk-ant-oat01-... token
```

---

## Stap 2 — Discord bot aanmaken

1. Ga naar [discord.com/developers/applications](https://discord.com/developers/applications)
2. **New Application** → naam (bijv. `Openclaw Agent`)
3. **Bot** → **Add Bot** → kopieer het token
4. Zet aan bij **Privileged Gateway Intents**: **Message Content Intent** + **Server Members Intent** → **Save Changes**
5. **OAuth2 → URL Generator**: scopes `bot` + `applications.commands`,
   permissions: Send Messages, Read Messages/View Channels, Read Message History
6. Kopieer de gegenereerde URL onderaan → open in browser → voeg bot toe aan je server

> **Tip:** De permissions integer in de URL Generator wordt niet opgeslagen — dat is normaal. Hij is alleen bedoeld om de invite-URL te genereren. Na uitnodiging staan de rechten als een rol in je server.

---

## Stap 3 — `.env` aanmaken op de workspace

Op de **host**:
```bash
mkdir -p ~/openclaw-workspace
nano ~/openclaw-workspace/.env
```

Inhoud:
```
OPENCLAW_CONFIG_PATH=/home/agent/workspace/.openclaw/openclaw.json
OPENCLAW_DISCORD_TOKEN=your-discord-bot-token
OPENCLAW_BUNDLED_PLUGINS_DIR=/home/agent/workspace/.openclaw-bundled-plugins
```

> **Let op:** De variabele `CLAUDE_CODE_OAUTH_TOKEN` wordt **niet** door Openclaw gelezen voor API-calls.
> Openclaw's Anthropic-plugin leest `auth-profiles.json` (zie Stap 4b). Zet geen OAuth token in `.env`.

---

## Stap 4 — Anthropic auth configureren (auth-profiles.json)

Dit is de **kritieke stap** die vaak ontbreekt. De pairing code werkt zonder auth, maar echte AI-antwoorden vereisen dit bestand.

In de VM:
```bash
mkdir -p /home/agent/workspace/.openclaw/agents/main/agent
```

Maak aan: `/home/agent/workspace/.openclaw/agents/main/agent/auth-profiles.json`
```json
{
  "version": 1,
  "profiles": {
    "anthropic:default": {
      "type": "token",
      "provider": "anthropic",
      "token": "sk-ant-oat01-..."
    }
  }
}
```

Haal de token op via `claude setup-token` in de VM.

> **Waarom niet via .env?**
> `CLAUDE_CODE_OAUTH_TOKEN` is voor Claude Code (de CLI), niet voor Openclaw.
> Openclaw's Anthropic-plugin leest uitsluitend `auth-profiles.json`. Het token is hetzelfde (sk-ant-oat01-...) maar moet op de juiste plek staan.

---

## Stap 5 — Bundled plugins overlay aanmaken

### Waarom dit nodig is

De Nix-package compileert TypeScript naar JavaScript, maar kopieert de `openclaw.plugin.json` manifest-bestanden **niet** mee naar de output. In de Nix store ontbreken ze daardoor:

```
/nix/store/.../dist/extensions/discord/
  index.js        ✅ aanwezig
  setup-entry.js  ✅ aanwezig
  openclaw.plugin.json  ❌ ONTBREEKT
```

Openclaw slaat elke plugin over waarvan het manifest ontbreekt — Discord wordt nooit geladen.

**Waarom geen symlinks?**
De Nix store is read-only (schrijven onmogelijk), en Openclaw's `checkSourceEscapesRoot()` volgt symlinks via `safeRealpathSync()` en weigert plugins waarvan het echte pad buiten de plugin-root valt. Een symlink naar de Nix store zou worden geblokkeerd.

**De oplossing:** een writable overlay directory met echte bestanden die de plugin code re-exporteren via ESM wrappers. De boundary check slaagt omdat het echte pad van de wrappers *binnen* de overlay directory valt.

### Script aanmaken

Maak op de **host** een script aan dat de overlay (her)genereert:

```bash
cat > ~/openclaw-sandbox/build-plugin-overlay.sh << 'EOF'
#!/usr/bin/env bash
set -euo pipefail

OVERLAY=/home/agent/workspace/.openclaw-bundled-plugins
NIX_PKG=$(ls -d /nix/store/*-openclaw-gateway-*/lib/openclaw 2>/dev/null | head -1)

if [[ -z "$NIX_PKG" ]]; then
  echo "ERROR: openclaw-gateway niet gevonden in Nix store. Voer eerst 'nix build' uit."
  exit 1
fi

EXTENSIONS="$NIX_PKG/dist/extensions"
SOURCE_MANIFESTS="$NIX_PKG/../../../share/openclaw/extensions"  # source dir indien aanwezig

echo "Nix package: $NIX_PKG"
echo "Overlay: $OVERLAY"

mkdir -p "$OVERLAY"

for plugin_dir in "$EXTENSIONS"/*/; do
  plugin=$(basename "$plugin_dir")
  out="$OVERLAY/$plugin"
  mkdir -p "$out"

  # Manifest: kopieer vanuit source tree of genereer minimaal manifest
  if [[ -f "$plugin_dir/openclaw.plugin.json" ]]; then
    cp "$plugin_dir/openclaw.plugin.json" "$out/openclaw.plugin.json"
  else
    # Zoek in broncode (packages/extensions/<plugin>/)
    src_manifest=$(find /nix/store -path "*/packages/extensions/$plugin/openclaw.plugin.json" 2>/dev/null | head -1)
    if [[ -n "$src_manifest" ]]; then
      cp "$src_manifest" "$out/openclaw.plugin.json"
    else
      echo "WARN: geen manifest gevonden voor $plugin, overgeslagen"
      continue
    fi
  fi

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

  echo "  ✓ $plugin"
done

echo ""
echo "Overlay aangemaakt: $(ls "$OVERLAY" | wc -l) plugins"
EOF
chmod +x ~/openclaw-sandbox/build-plugin-overlay.sh
```

### Overlay bouwen

In de VM (of op de host via virtiofs):
```bash
# Op de host:
~/openclaw-sandbox/build-plugin-overlay.sh

# Of in de VM:
bash /nix/store/*-source/build-plugin-overlay.sh  # als het script in de VM beschikbaar is
```

Of laat Claude Code het doen in de VM:
```bash
cd ~/workspace && claude
# Opdracht: Maak de bundled plugins overlay aan in ~/.openclaw-bundled-plugins
# voor alle extensies in de huidige Nix store. Kopieer elk manifest en maak ESM wrappers.
```

### Controleren

```bash
ls /home/agent/workspace/.openclaw-bundled-plugins/ | wc -l  # moet ~74 zijn
cat /home/agent/workspace/.openclaw-bundled-plugins/discord/openclaw.plugin.json
```

### Na een `nix build` upgrade

> [!WARNING]
> Na elke `nix build` verandert de Nix store hash. De ESM wrappers bevatten het absolute pad naar de Nix store en worden dan ongeldig.
>
> Voer na elke upgrade uit:
> ```bash
> ~/openclaw-sandbox/build-plugin-overlay.sh
> sudo systemctl restart openclaw-gateway  # in de VM
> ```

---

## Stap 5b — Coordinator AGENTS.md aanmaken

De coordinator weet standaard niet dat hij `thread: true` moet meegeven bij subagent spawning. Zonder dit krijgt elke subagent geen eigen Discord-thread en zie je de voortgang niet live.

```bash
mkdir -p /home/agent/workspace/.openclaw/workspace
```

Maak aan: `/home/agent/workspace/.openclaw/workspace/AGENTS.md`

```markdown
# AGENTS.md — Coordinator

Je bent de coordinator van een multi-agent team. Jij luistert op Discord en verdeelt werk over gespecialiseerde subagents.

## Jouw team

| Agent | Specialisatie |
|-------|---------------|
| writer | Teksten schrijven, content, brieven, samenvattingen |
| researcher | Informatie opzoeken, analyseren, feiten checken |
| editor | Teksten redigeren, verbeteren, consistent maken |

## Subagents spawnen

Voor de content pipeline (researcher/writer/editor) gebruik je `mode: "run"`:

```
sessions_spawn({
  agentId: "researcher",
  task: "Zoek de drie belangrijkste trends in AI in 2025",
  mode: "run"
})
```

Na spawn wacht je op de push-completion — niet pollen.

Rapporteer voortgang naar de gebruiker via Discord berichten ("Feiten binnen, Writer aan de slag!").

## Spawning-modi

| Modus | Gebruik |
|-------|---------|
| `mode: "run"` | Eénmalige taak, resultaat terug bij coordinator (pipeline) |
| `thread: true` | Alleen voor ACP harness (Claude Code/Codex) in Discord-thread — **niet** voor researcher/writer/editor |

## Workflow voor complexe vragen

1. Analyseer de vraag
2. Spawn de juiste subagent(s) met thread: true
3. Wacht op push-completion (niet pollen)
4. Combineer en presenteer aan de gebruiker

Voor eenvoudige vragen handel je zelf af zonder subagents te starten.
```

> **Bevestigd door de coordinator zelf:** `thread: true` is uitsluitend bedoeld voor ACP harness-sessies (Claude Code/Codex) die interactief in een Discord-thread leven. Voor een researcher/writer/editor pipeline is `mode: "run"` de juiste keuze — de coordinator post zelf status-updates naar Discord.

---

## Stap 6 — Openclaw config aanmaken

In de VM of op de host (`~/openclaw-workspace/.openclaw/openclaw.json`):

```json
{
  "commands": {
    "native": "auto",
    "nativeSkills": "auto",
    "restart": true,
    "ownerDisplay": "raw"
  },
  "channels": {
    "discord": {
      "enabled": true,
      "token": {
        "source": "env",
        "provider": "default",
        "id": "OPENCLAW_DISCORD_TOKEN"
      },
      "groupPolicy": "open",
      "streaming": "off"
    }
  },
  "gateway": {
    "mode": "local",
    "auth": {
      "mode": "token",
      "token": "your-gateway-token-here"
    },
    "controlUi": {
      "dangerouslyDisableDeviceAuth": true
    }
  }
}
```

> **dangerouslyDisableDeviceAuth: true** — vereist voor het Mission Control dashboard. Staat toe dat `openclaw-control-ui` clients verbinden zonder device-keypair pairing, mits de gateway auth token klopt en de verbinding via loopback (127.0.0.1) gaat.

> **groupPolicy: "open"** — iedereen in je server kan berichten sturen zonder te pairen.
> De pairing code die de bot stuurt bij eerste contact is een welkomstmechanisme, geen vereiste.

---

## Stap 7 — Gateway herstarten

```bash
sudo systemctl restart openclaw-gateway
sleep 3
tail -10 /tmp/openclaw-gateway.log
# Verwacht: "logged in to discord as ..."
```

---

## Stap 8 — Testen via Discord

Stuur een bericht in je server met **@mention**:
```
@OpenClaw Agent hoi, werk je?
```

> **Belangrijk:** In server channels reageert de bot alleen op @mentions.
> In DMs kan de bot direct aangeschreven worden.

---

## Stap 9 — Mission Control dashboard

Het Mission Control dashboard is een Next.js applicatie die live in de VM draait en verbinding maakt met de Openclaw gateway via WebSocket. Het toont actieve agents, sessies, taakwachtrij en een live event feed.

### Wat het dashboard doet

- **Agents** — lijst van alle geconfigureerde agents (coordinator, writer, researcher, editor) met sessiestatus
- **Active Sessions** — lopende agent-sessies met kind, timestamp en tokengebruik
- **Task Backlog** — taakwachtrij met status (pending/running/done/failed), aangemaakt via het formulier of Discord
- **Live Feed** — SSE stream van gateway push-events (sessie-updates, agent-events)
- **New Task** — formulier om direct taken toe te voegen aan de wachtrij

### Installatie

Het dashboard staat in `~/workspace/dashboard/` (gedeeld via virtiofs).

**Op de host** — maak de configuratie aan:
```bash
cat > ~/openclaw-workspace/dashboard/.env.local << 'EOF'
GATEWAY_URL=ws://127.0.0.1:18789
GATEWAY_TOKEN=af21006010193b74f53fae1550ea72e6685d497880f4e9c2
EOF
```

> Vervang `GATEWAY_TOKEN` met de waarde uit `gateway.auth.token` in `openclaw.json`.

**Firewall** — poort 3333 staat al open via `networking.firewall.allowedTCPPorts` in `flake.nix`. Na een `nix build` + herstart is dit automatisch actief. Tijdelijk zonder rebuild:
```bash
sudo iptables -I INPUT -p tcp --dport 3333 -j ACCEPT
```

**Starten als achtergrondservice** — het dashboard start automatisch via systemd. Na `nix build` + VM-herstart is het beschikbaar op `http://10.0.1.2:3333` zonder verdere stappen.

Status en logs bekijken:
```bash
sudo systemctl status openclaw-dashboard
sudo journalctl -u openclaw-dashboard -f
```

> **Let op:** de systemd-service draait `npm run build` bij elke (her)start. Dit duurt ~15-20 seconden. Het dashboard is pas beschikbaar nadat de build klaar is.

### Gateway WebSocket protocol

De dashboard-client (`src/lib/gateway.ts`) verbindt via een niet-triviale handshake met de Openclaw gateway. Dit zijn de vereisten die ontdekt zijn tijdens de implementatie:

**1. Request frame format — `type: "req"` is verplicht**
```json
{ "type": "req", "id": "uuid", "method": "sessions.list", "params": {} }
```
De gateway valideert elk inkomend bericht tegen `RequestFrameSchema` dat `type: "req"` als literal vereist. Zonder dit veld krijg je: `1008 invalid request frame`.

**2. Challenge-response handshake**
Bij elke nieuwe verbinding stuurt de gateway eerst een challenge event — de client moet wachten op dit event vóór hij `connect` verstuurt:
```json
{ "type": "event", "event": "connect.challenge", "payload": { "nonce": "...", "ts": 1234 } }
```
Direct `connect` sturen bij `ws.on('open')` werkt niet; de gateway sluit de verbinding zonder reactie.

**3. Connect params met auth, role en scopes**
```json
{
  "minProtocol": 3, "maxProtocol": 3,
  "client": { "id": "openclaw-control-ui", "mode": "ui", "version": "1.0.0", "platform": "node" },
  "auth": { "token": "GATEWAY_TOKEN" },
  "role": "operator",
  "scopes": ["operator.admin", "operator.read", "operator.write"],
  "caps": []
}
```
- `client.id` moet een geldige `GATEWAY_CLIENT_IDS` waarde zijn (`"openclaw-control-ui"`, `"cli"`, `"gateway-client"`, etc.)
- `client.mode` moet een geldige `GATEWAY_CLIENT_MODES` waarde zijn (`"ui"`, `"cli"`, `"backend"`, `"node"`, `"probe"`, `"test"`, `"webchat"`)
- Zonder `role` en `scopes` worden de aangevraagde scopes gewist door de gateway (`clearUnboundScopes`)

**4. Control UI client zonder device-keypair (dangerouslyDisableDeviceAuth)**

De Openclaw gateway vereist normaal een Ed25519 device-keypair voor alle clients. Dit is onpraktisch voor server-side dashboard verbindingen. De gateway biedt een expliciete bypass voor control UI clients:

```json
"gateway": {
  "controlUi": { "dangerouslyDisableDeviceAuth": true }
}
```

Met `client.id = "openclaw-control-ui"` EN `dangerouslyDisableDeviceAuth: true`:
- De gateway accepteert de verbinding zonder device-handtekening
- Scopes worden **niet** gewist (normale clients zonder device identity verliezen hun scopes)
- Vereist: geldige gateway auth token én verbinding via loopback (127.0.0.1)

**5. Origin header vereist voor control UI**
```javascript
new WebSocket(GATEWAY_URL, {
  headers: {
    Authorization: `Bearer ${GATEWAY_TOKEN}`,
    Origin: 'http://127.0.0.1:3333',
  }
})
```
De gateway voert een origin-check uit voor alle control UI verbindingen. Zonder Origin header: `{ ok: false, reason: "origin missing or invalid" }`. Een loopback origin (`127.0.0.1`) wordt altijd geaccepteerd voor lokale clients.

**6. Response frame format — `payload`, niet `result`**
Succesvolle responses gebruiken `payload`, niet `result`:
```json
{ "type": "res", "id": "uuid", "ok": true, "payload": { ... } }
```

**7. `agents.list` en `sessions.list` returnen objecten, geen arrays**
```javascript
// agents.list → { defaultId, agents: [...], ... }
const result = await gatewayRequest('agents.list', {})
return result?.agents ?? []

// sessions.list → { sessions: [...], count, ... }
const result = await gatewayRequest('sessions.list', { limit: 20 })
return result?.sessions ?? []
```

**8. Next.js bundelt `ws` niet correct op Node.js 22**
```javascript
// next.config.js
serverExternalPackages: ['ws', 'bufferutil', 'utf-8-validate']
```
Zonder dit krijg je: `bufferutil.mask is not a function`.

### Tasks API

Taken worden opgeslagen in een lokaal JSON-bestand in de workspace:

```
/home/agent/workspace/.openclaw/tasks.json
```

Het dashboard leest en schrijft dit bestand rechtstreeks via de Next.js API routes (`/api/tasks`). Er is geen aparte server nodig. Het bestand is persistent via virtiofs en overleeft VM-reboots.

> **Opmerking:** `tasks.list` bestaat niet als gateway-methode. Pogingen om taken via de gateway op te halen resulteren in `unknown method: tasks.list`. De file-based aanpak is de juiste oplossing.

---

## `.claude.json` persistent maken

Na de eerste succesvolle Claude login in de VM:
```bash
cp ~/.claude.json ~/workspace/.claude.json
```

Systemd maakt deze symlink automatisch bij volgende boots (geconfigureerd in `flake.nix`).

---

## Claude Code als manager

Claude Code beheert de Openclaw-configuratie. In de VM:
```bash
cd ~/workspace && claude
```

Voorbeeldopdrachten:
```
Voeg een nieuwe agent-instantie toe voor social media scheduling op poort 18793
Zet de coordinator zo in dat writer-output altijd door editor gaat voor publicatie
Configureer de researcher om DuckDuckGo te gebruiken als primaire zoekbron
```

Claude past `flake.nix` aan → `nix build` → reboot → nieuwe configuratie actief.

---

## Content pipeline gebruiken via Discord

```
@OpenClaw Agent schrijf een uitgebreid blogartikel over AI trends — gebruik het volledige team
@OpenClaw Agent research de concurrenten van [bedrijf] en maak een SWOT-analyse
@OpenClaw Agent elke maandag om 9:00: genereer de content kalender voor de week
@OpenClaw Agent toon alle actieve agents en hun status
```

---

## Services beheren

```bash
# Status
sudo systemctl status openclaw-gateway openclaw-writer openclaw-researcher openclaw-editor

# Herstarten (bijv. na .env wijziging)
sudo systemctl restart openclaw-gateway

# Logs
tail -f /tmp/openclaw-gateway.log
tail -f /tmp/openclaw/openclaw-$(date +%Y-%m-%d).log   # gedetailleerd
```

---

## Bekende problemen

### Bot reageert niet op Discord berichten

Stuur berichten met **@mention** in een server channel. De bot negeert berichten zonder mention:
```
discord: skipping guild message — reason: no-mention
```
In DMs is geen mention nodig.

Check of de gateway Discord events ontvangt:
```bash
tail -f /tmp/openclaw/openclaw-$(date +%Y-%m-%d).log
```

### Bot verbindt maar geeft geen AI-antwoorden

De pairing code werkt zonder auth — echte AI-antwoorden vereisen `auth-profiles.json`:
```bash
cat /home/agent/workspace/.openclaw/agents/main/agent/auth-profiles.json
```
Ontbreekt het bestand? Zie **Stap 4**.

### "No pending pairing request found"

De pairing code is verlopen (standaard ~60 seconden). Wis de verlopen code en probeer opnieuw:
```bash
echo '{"version":1,"requests":[]}' > /home/agent/workspace/.openclaw/credentials/discord-pairing.json
sudo systemctl restart openclaw-gateway
```
Stuur daarna meteen een @mention in Discord.

### Plugin manifests ontbreken (0 manifests loaded) of Discord laadt niet

De Nix-package mist `openclaw.plugin.json` bestanden. Controleer:
```bash
echo $OPENCLAW_BUNDLED_PLUGINS_DIR
ls /home/agent/workspace/.openclaw-bundled-plugins/ | wc -l  # moet ~74 zijn
```

Ontbreekt de overlay of is hij leeg? Zie **Stap 5** en voer het script uit:
```bash
~/openclaw-sandbox/build-plugin-overlay.sh
sudo systemctl restart openclaw-gateway
```

Na een `nix build` upgrade veranderen de Nix store paden in de ESM wrappers — overlay opnieuw bouwen met hetzelfde script.

### Discord token niet herkend (401)

Reset het token in de Discord Developer Portal (Bot → Reset Token) en update `.env`:
```bash
nano /home/agent/workspace/.env  # vervang OPENCLAW_DISCORD_TOKEN
sudo systemctl restart openclaw-gateway
```

### OAuth token verlopen

```bash
claude setup-token
# Kopieer de nieuwe sk-ant-oat01-... token
nano /home/agent/workspace/.openclaw/agents/main/agent/auth-profiles.json
sudo systemctl restart openclaw-gateway
```

### Internet werkt niet in VM

```bash
ping 8.8.8.8
# Niet bereikbaar? Op de host:
sudo ~/openclaw-sandbox/setup-network.sh
```

### Bot invite link kwijt

De bot ID staat in de gateway log. Gebruik:
```bash
grep "logged in to discord" /tmp/openclaw-gateway.log | tail -1
# Noteert de bot ID (bijv. 1484294700486627408)
```
Invite URL: `https://discord.com/oauth2/authorize?client_id=BOT_ID&permissions=68608&scope=bot%20applications.commands`

---

### Dashboard: "Gateway connection closed before completing"

De gateway sluit de verbinding zonder reactie. Meest voorkomende oorzaken:

**a) `type: "req"` ontbreekt in het request frame**
Het gateway protocol vereist `{ type: "req", id, method, params }`. Zonder `type` geeft de gateway: `1008 invalid request frame`.

**b) `connect` gestuurd vóór de challenge**
De gateway stuurt eerst een `connect.challenge` event. De client moet wachten op dit event en pas daarna `connect` sturen. Direct sturen bij `ws.on('open')` resulteert in een stille verbindingsbreuk.

**c) `auth.token` ontbreekt of klopt niet**
Stuur `auth: { token: "..." }` met de waarde uit `gateway.auth.token` in `openclaw.json`. De nonce uit de challenge hoeft NIET meegestuurd te worden bij token-authenticatie (de nonce is alleen voor device-keypair auth).

### Dashboard: "missing scope: operator.read"

De connect handshake slaagde maar methode-aanroepen mislukken. Oorzaak: de gateway wist alle aangevraagde scopes voor clients zonder device-identity.

Oplossing: gebruik `client.id = "openclaw-control-ui"` met `dangerouslyDisableDeviceAuth: true` in de gateway config (zie **Stap 9**). Dit voorkomt scope-wissing voor loopback control UI verbindingen.

### Dashboard: sessions/agents tonen 0 items terwijl API 200 geeft

De gateway returnt objecten, geen arrays:
- `sessions.list` → `{ sessions: [...], count, ts, ... }` — gebruik `result?.sessions ?? []`
- `agents.list` → `{ agents: [...], defaultId, ... }` — gebruik `result?.agents ?? []`

De `Array.isArray()` check in page.tsx geeft `[]` terug als de API een object returnt.

### Dashboard systemd-service: `spawn sh ENOENT` bij opstarten

`npm run build` faalt direct omdat systemd-services in NixOS een minimale `PATH` krijgen zonder `/bin/sh`. `npm` roept intern `sh` aan om scripts te starten.

Oplossing: voeg `path = [ pkgs.bash pkgs.nodejs_20 pkgs.coreutils ]` toe aan de service-definitie in `flake.nix`. Dit zet de benodigde binaries in de service-PATH.

### Dashboard: `tasks.list` geeft `unknown method`

De gateway heeft geen `tasks.list` methode. Taken worden opgeslagen in een lokaal JSON-bestand (`/home/agent/workspace/.openclaw/tasks.json`), niet via de gateway. Zie **Tasks API** hierboven.

### Dashboard bereikbaar maar poort 3333 geblokkeerd na reboot

De VM firewall staat standaard geen extra poorten toe. Na elke reboot:
```bash
sudo iptables -I INPUT -p tcp --dport 3333 -j ACCEPT
```

Voor permanente toegang: voeg `3333` toe aan `networking.firewall.allowedTCPPorts` in `flake.nix` (staat er al in na de laatste rebuild).

### Gateway bereikbaar via ws://127.0.0.1:18789 maar NIET via ws://10.0.1.2:18789

De gateway bindt standaard aan loopback (127.0.0.1), niet aan de VM-netwerk interface. Het dashboard **moet in de VM draaien** (niet op de host) om de gateway te kunnen bereiken. Gebruik `GATEWAY_URL=ws://127.0.0.1:18789` in `.env.local`.
