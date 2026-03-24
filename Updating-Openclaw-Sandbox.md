# Updating Openclaw Sandbox — 2026.3.14 → 2026.3.23

Volledig update-stappenplan met risicoanalyse, backup-procedure en alle configuratiewijzigingen die nodig zijn om de huidige functionaliteit intact te houden.

---

## ✅ Changelog — Update uitgevoerd op 2026-03-24

**Status: COMPLETED**

| Stap | Uitgevoerd | Resultaat |
|------|-----------|-----------|
| Backup gemaakt (rsync sandbox + workspace) | ✅ | `~/Documents/OpenClaw-Backup/2026-03-24/` |
| `permittedInsecurePackages` → `2026.3.23` | ✅ | flake.nix |
| `nodejs_20` + `corepack_20` → `nodejs_22` | ✅ | flake.nix, VM draait nu op v22.22.1 |
| `CLAWDBOT_*` vars verwijderd uit project-a service | ✅ | flake.nix |
| `nix flake update nix-openclaw` | ✅ | 15 derivaties gebouwd |
| `nix build` | ✅ | Succesvol |
| Plugin overlay herbouwd (74 plugins) | ✅ | `~/openclaw-workspace/.openclaw-bundled-plugins/` |
| VM herstart met nieuwe build | ✅ | Alle 3 services active |
| Discord verbonden | ✅ | @OpenClaw Agent online |
| Cron jobs intact | ✅ | 5 jobs aanwezig |
| `openclaw doctor --fix` | ✅ | Geen nieuwe issues |
| `heartbeat.directPolicy: "allow"` ingesteld | ✅ | `agents.defaults.heartbeat` in openclaw.json |

---

## Nieuwe functionaliteit in 2026.3.22–2026.3.23 — relevant voor onze stack

### 🟢 Beschikbaar zonder configuratie

| Functionaliteit | Wat het doet | Actie |
|----------------|-------------|-------|
| `/btw` command | Stel een terzijde-vraag aan de agent zonder de lopende conversatie te onderbreken | Geen — werkt direct in Discord |
| Operator scope fix (`dangerouslyDisableDeviceAuth`) | Bug opgelost waarbij scopes gewist werden bij device-auth bypass — onze dashboard-verbinding is hierdoor betrouwbaarder | Geen — profijt automatisch |
| Session metadata bewaard bij reset | `lastAccountId` en `lastThreadId` blijven bewaard na gateway herstart | Geen — automatisch |
| Auth token fix | Live gateway writes revertten freshly saved credentials niet meer (was ons `thinkingDefault` crashprobleem) | Geen — automatisch opgelost |

### 🟡 Configureerbaar — aanbevolen voor onze stack

| Functionaliteit | Wat het doet | Actie |
|----------------|-------------|-------|
| **Bundled providers: Exa, Tavily, Firecrawl** | Dedicated zoek- en web-scraping providers — aanzienlijk beter dan generieke web_search voor de researcher agent | Configureer Exa of Tavily als search provider in `openclaw.json` voor de researcher agent — vereist API key |
| **Per-agent thinking/reasoning model defaults** | Elke agent kan nu een eigen `thinkingBudget` en model krijgen met auto-revert als het crasht | Nu veilig te configureren (crash-bug opgelost). Overweeg lagere thinking budget voor writer/editor, hoger voor researcher |
| **Chrome MCP `existing-session` mode** | Legacy Chrome extension relay verwijderd, vervangen door stabielere `existing-session` driver | Als browser control geconfigureerd is: update `browser.profiles` van `driver: "extension"` naar `driver: "existing-session"` |
| **Pluggable sandbox backends (OpenShell, SSH)** | Agents kunnen commando's uitvoeren via configureerbare shell backends | Overweeg voor researcher agent om shell-toegang te sandboxen via OpenShell |

### 🔵 Interessant maar lagere prioriteit

| Functionaliteit | Wat het doet | Actie |
|----------------|-------------|-------|
| **Anthropic Vertex AI support** | Claude draaien via Google Vertex AI (alternatief voor directe Anthropic API) | Alleen relevant als je Google Cloud gebruikt of Vertex pricing wil benutten |
| **ClawHub skill manager** | Native skill search/install/update flows — skills installeren zonder Nix rebuild | In Nix-setup beperkt bruikbaar (skills via Nix overlay), maar interessant voor experimentele skills |
| **Canvas expand button (Control UI)** | Groter chatvenster in de ingebouwde Control UI | Geen actie — zit in openclaw-gateway's eigen UI, niet ons custom dashboard |
| **Auto-renamed DM forum topics** | Discord DM-topics krijgen automatisch een LLM-gegenereerde naam | Geen actie — werkt automatisch als je Discord forum channels gebruikt |

### 🔴 Niet van toepassing

| Functionaliteit | Reden |
|----------------|-------|
| Matrix plugin (matrix-js-sdk) | Matrix niet geconfigureerd |
| Telegram custom Bot API endpoint | Telegram niet gebruikt |
| Feishu approval cards | Feishu niet gebruikt |
| Qwen/MiniMax model toevoegingen | Qwen/MiniMax niet geconfigureerd |
| ClawHub bundle discovery | Nix-based install, niet ClawHub |

---

## Aanbevolen vervolgstappen (prioriteit volgorde)

1. **Exa of Tavily configureren** voor researcher agent — grootste impact op researchtaken
2. **Per-agent thinking budget** instellen nu dat het veilig is
3. **Browser config updaten** naar `existing-session` als browser MCP in gebruik is

---

## Huidig stack overzicht

| Component | Huidige waarde |
|-----------|---------------|
| Openclaw versie | `2026.3.14` |
| Nix package | `openclaw-gateway-unstable-823a09ac` |
| nix-openclaw flake | `github:openclaw/nix-openclaw` (floating, geen pinned commit) |
| Node.js in VM | `nodejs_20` (package: `corepack_20`) |
| permittedInsecurePackages | `"openclaw-2026.3.12"` ← al verouderd t.o.v. huidige install |
| Gateway poort | 18789 (primary), 18790 (project-a) |
| Plugin overlay | `~/.openclaw-bundled-plugins` (76 plugins, ESM wrappers) |

---

## Breaking changes analyse — wat raakt onze setup

### 🔴 KRITIEK — Direct actie vereist

#### 1. `CLAWDBOT_*` env vars verwijderd (2026.3.22)

**Impact:** De `openclaw-gateway-project-a` systemd service in `flake.nix` gebruikt nog de verwijderde variabelen.

**Huidige code in `flake.nix` (regels 173–175):**
```nix
CLAWDBOT_STATE_DIR   = "/home/agent/workspace/project-a/.openclaw";
CLAWDBOT_CONFIG_PATH = "/home/agent/workspace/project-a/.openclaw/openclaw.json";
```

**Moet worden:**
```nix
OPENCLAW_STATE_DIR  = "/home/agent/workspace/project-a/.openclaw";
OPENCLAW_CONFIG_PATH = "/home/agent/workspace/project-a/.openclaw/openclaw.json";
```

De main gateway gebruikt al de juiste `OPENCLAW_*` variabelen via `.env`. Alleen project-a is nog fout.

**Risico als niet gefixt:** Project-A gateway (poort 18790) start op maar vindt zijn config niet → lege state, sessies kwijt, agent werkt niet.

---

#### 2. Node.js minimumversie verhoogd: 22.16+ vereist (2026.3.22/23)

**Impact:** De VM gebruikt `nodejs_20` en `corepack_20`. Dit valt onder de nieuwe minimumvereiste.

**Huidige code in `flake.nix` (regels 95, 198, 207–208):**
```nix
environment.systemPackages = with pkgs; [
  python311 nodejs_20 corepack_20
  ...
];

# Dashboard service:
path = [ pkgs.bash pkgs.nodejs_20 pkgs.coreutils pkgs.python3 ];
ExecStartPre = "${pkgs.nodejs_20}/bin/npm run build";
ExecStart    = "${pkgs.nodejs_20}/bin/npm run start";
```

**Moet worden:**
```nix
environment.systemPackages = with pkgs; [
  python311 nodejs_22 corepack_22
  ...
];

# Dashboard service:
path = [ pkgs.bash pkgs.nodejs_22 pkgs.coreutils pkgs.python3 ];
ExecStartPre = "${pkgs.nodejs_22}/bin/npm run build";
ExecStart    = "${pkgs.nodejs_22}/bin/npm run start";
```

> **Opmerking:** Controleer of `corepack_22` beschikbaar is in de nixpkgs versie die je gebruikt. Alternatief: gebruik `nodejs_22` en laat corepack weg als het niet nodig is.

**Risico als niet gefixt:** Openclaw gateway of dashboard kan weigeren te starten met versie-fout op Node.js.

---

#### 3. `permittedInsecurePackages` verouderd in `flake.nix`

**Impact:** Staat nu op `"openclaw-2026.3.12"` maar de geïnstalleerde versie is al `2026.3.14`. Na update naar `2026.3.23` blokkeert Nix de build.

**Huidige code:**
```nix
nixpkgs.config.permittedInsecurePackages = [
  "openclaw-2026.3.12"
];
```

**Moet worden:**
```nix
nixpkgs.config.permittedInsecurePackages = [
  "openclaw-2026.3.23"
];
```

**Risico als niet gefixt:** `nix build` faalt met "insecure package" error.

---

### 🟡 AANDACHT — Controleren, waarschijnlijk automatisch opgelost

#### 4. Browser/CDP: Chrome extension relay verwijderd (2026.3.22)

**Impact:** De legacy Chrome extension relay path is verwijderd. Onze setup gebruikt headless Chromium via direct CDP (cdpPort 18800) — **niet** de extension relay.

**Actie:** `openclaw doctor --fix` repareert eventuele verwijzingen automatisch.

**Risico:** Laag. Onze `browser.profiles.openclaw` config gebruikt `cdpPort`, niet `driver: "existing-session"` met Chrome extension.

---

#### 5. CSP/Control UI: SHA-256 hashes voor inline scripts (2026.3.23)

**Impact:** Het Mission Control dashboard verbindt via `client.id: "openclaw-control-ui"` met `dangerouslyDisableDeviceAuth: true`. De nieuwe CSP-hashes in `index.html` kunnen de WebSocket-verbinding beïnvloeden als de browser extra checks doet.

**Actie:** Na update dashboard testen op `http://10.0.1.2:3333`. Als verbinding mislukt: gateway config controleren op CSP-gerelateerde instellingen.

**Risico:** Gemiddeld. De `dangerouslyDisableDeviceAuth` bypass werkt via WebSocket/API, niet via browser CSP — waarschijnlijk geen probleem.

---

#### 6. Bundled plugins overlay na `nix build`

**Impact:** Na elke `nix build` verandert de Nix store hash. De ESM wrappers in `~/.openclaw-bundled-plugins/` bevatten absolute paden naar de Nix store en worden ongeldig.

**Actie:** Na `nix build` altijd opnieuw uitvoeren:
```bash
~/openclaw-sandbox/build-plugin-overlay.sh
sudo systemctl restart openclaw-gateway
```

**Risico:** Hoog als vergeten. Discord plugin laadt niet → bot offline.

---

#### 7. Auth token fix (2026.3.23) — positieve wijziging

**Impact:** Bugfix: live gateway writes revertten niet meer de freshly saved credentials. Dit was het `thinkingDefault` issue waarbij de gateway crashte na config-updates.

**Actie:** Geen. Dit is een verbetering.

---

### 🟢 NIET VAN TOEPASSING — Kan genegeerd worden

| Breaking change | Reden niet van toepassing |
|----------------|--------------------------|
| Plugin SDK surface change (`openclaw/extension-api` verwijderd) | Geen custom plugins gebouwd |
| `.moltbot` state dir verwijderd | Gebruiken al `.openclaw` |
| `nano-banana-pro` skill verwijderd | Niet geconfigureerd |
| Matrix plugin migratie | Matrix niet geconfigureerd |
| Mistral model defaults verlaagd | Mistral niet geconfigureerd |
| ClawHub als preferred install channel | npm/Nix-based install, niet ClawHub |
| ModelStudio/Qwen provider rename | Qwen niet geconfigureerd |
| `openclaw/extension-api` import pad | Geen custom plugin code |

---

## Volledige backup procedure

Voer dit uit **vóór** elke wijziging aan `flake.nix` of `nix build`.

### Stap 1 — Stop de VM

```bash
# Terminal 2 (VM): Ctrl+C
# Terminal 1 (virtiofsd): Ctrl+C
```

### Stap 2 — Volledige gecomprimeerde backup

```bash
cd ~
BACKUP_DATE=$(date +%Y%m%d_%H%M%S)

# Backup .openclaw state (agents, sessions, config, memory, werkruimtes)
tar -czf ~/openclaw-backup-${BACKUP_DATE}.tar.gz \
  --exclude='openclaw-workspace/.openclaw-bundled-plugins' \
  --exclude='openclaw-workspace/.openclaw/node_modules' \
  --exclude='openclaw-workspace/.openclaw/agents/*/sessions/*.jsonl' \
  openclaw-workspace/.openclaw \
  openclaw-workspace/.env

# Backup inclusief sessions (groter, optioneel)
tar -czf ~/openclaw-backup-full-${BACKUP_DATE}.tar.gz \
  --exclude='openclaw-workspace/.openclaw-bundled-plugins' \
  --exclude='openclaw-workspace/.openclaw/node_modules' \
  openclaw-workspace/.openclaw \
  openclaw-workspace/.env

# Backup sandbox (flake.nix, scripts)
tar -czf ~/openclaw-sandbox-backup-${BACKUP_DATE}.tar.gz \
  openclaw-sandbox/flake.nix \
  openclaw-sandbox/build-plugin-overlay.sh \
  openclaw-sandbox/setup-network.sh

echo "Backups aangemaakt:"
ls -lh ~/openclaw-backup-${BACKUP_DATE}.tar.gz
ls -lh ~/openclaw-sandbox-backup-${BACKUP_DATE}.tar.gz
```

### Geschatte backup groottes

| Backup | Inhoud | Geschatte grootte |
|--------|--------|-------------------|
| `openclaw-backup-*.tar.gz` | Config + memory + agent state (geen sessions) | ~2-5 MB |
| `openclaw-backup-full-*.tar.gz` | Alles inclusief sessions | ~15-20 MB |
| `openclaw-sandbox-backup-*.tar.gz` | flake.nix + scripts | < 100 KB |

### Restore procedure (bij calamiteiten)

```bash
# Stop VM eerst (zie boven)
BACKUP_DATE=20260324_120000  # vervang met jouw timestamp

cd ~
tar -xzf ~/openclaw-backup-${BACKUP_DATE}.tar.gz
# of voor full backup:
tar -xzf ~/openclaw-backup-full-${BACKUP_DATE}.tar.gz

# Herstart VM en services normaal
```

---

## Update stappenplan

### Fase 1 — Backup (zie boven, verplicht)

### Fase 2 — flake.nix aanpassen

Open `~/openclaw-sandbox/flake.nix` en pas de volgende secties aan:

#### Wijziging A — permittedInsecurePackages

```nix
# VOOR:
nixpkgs.config.permittedInsecurePackages = [
  "openclaw-2026.3.12"
];

# NA:
nixpkgs.config.permittedInsecurePackages = [
  "openclaw-2026.3.23"
];
```

#### Wijziging B — Node.js versie

```nix
# VOOR:
environment.systemPackages = with pkgs; [
  python311 nodejs_20 corepack_20
  curl git gh ffmpeg
  openclaw
  chromium
];

# NA:
environment.systemPackages = with pkgs; [
  python311 nodejs_22
  curl git gh ffmpeg
  openclaw
  chromium
];
```

```nix
# VOOR (dashboard service):
path = [ pkgs.bash pkgs.nodejs_20 pkgs.coreutils pkgs.python3 ];
ExecStartPre = "${pkgs.nodejs_20}/bin/npm run build";
ExecStart    = "${pkgs.nodejs_20}/bin/npm run start";

# NA:
path = [ pkgs.bash pkgs.nodejs_22 pkgs.coreutils pkgs.python3 ];
ExecStartPre = "${pkgs.nodejs_22}/bin/npm run build";
ExecStart    = "${pkgs.nodejs_22}/bin/npm run start";
```

#### Wijziging C — CLAWDBOT_* vars vervangen in project-a service

```nix
# VOOR:
systemd.services.openclaw-gateway-project-a = {
  ...
  environment = {
    OPENCLAW_STATE_DIR    = "/home/agent/workspace/project-a/.openclaw";
    CLAWDBOT_STATE_DIR    = "/home/agent/workspace/project-a/.openclaw";   # ← verwijderen
    OPENCLAW_CONFIG_PATH  = "/home/agent/workspace/project-a/.openclaw/openclaw.json";
    CLAWDBOT_CONFIG_PATH  = "/home/agent/workspace/project-a/.openclaw/openclaw.json"; # ← verwijderen
  };
  ...
};

# NA:
systemd.services.openclaw-gateway-project-a = {
  ...
  environment = {
    OPENCLAW_STATE_DIR   = "/home/agent/workspace/project-a/.openclaw";
    OPENCLAW_CONFIG_PATH = "/home/agent/workspace/project-a/.openclaw/openclaw.json";
  };
  ...
};
```

### Fase 3 — Nix build

```bash
cd ~/openclaw-sandbox
nix flake update nix-openclaw   # haalt 2026.3.23 op
nix build
```

> Als `nix build` faalt op `permittedInsecurePackages`: controleer of de versienaam exact overeenkomt met wat Nix rapporteert in de foutmelding.

### Fase 4 — Plugin overlay herbouwen

```bash
# Op de HOST (niet in VM) — na nix build is het Nix store pad veranderd
~/openclaw-sandbox/build-plugin-overlay.sh
```

### Fase 5 — VM starten

```bash
# Terminal 1:
./result/bin/virtiofsd-run

# Terminal 2:
./result/bin/microvm-run
```

### Fase 6 — Doctor en health check (in VM)

```bash
# In de VM:
openclaw doctor
# Controleer output op warnings

openclaw doctor --fix
# Migreert automatisch: legacy Chrome extension relay, Mistral configs, state dir layouts

openclaw gateway restart
# Of via systemd:
sudo systemctl restart openclaw-gateway

openclaw health
# Verwacht: gateway UP, alle channels groen
```

### Fase 7 — Services verifiëren

```bash
# In VM:
sudo systemctl status openclaw-gateway
sudo systemctl status openclaw-gateway-project-a
sudo systemctl status openclaw-dashboard

tail -20 /tmp/openclaw-gateway.log
# Verwacht: "logged in to discord as ..."

# Op host — test Discord
# Stuur testbericht zonder @mention (requireMention: false is geconfigureerd)
```

### Fase 8 — Dashboard verificatie

Open `http://10.0.1.2:3333` in browser:
- [ ] Gateway verbinding groen
- [ ] Agents (muddy, elon, gary, warren) zichtbaar
- [ ] Team page: Skills count zichtbaar per agent
- [ ] Memory page: bestanden laadbaar

---

## Volledige config checklist na update

### openclaw.json — geen wijzigingen verwacht

De huidige `openclaw.json` gebruikt geen deprecated velden. Controleer wel:

```bash
# In VM na update:
openclaw config validate
# of
openclaw doctor
```

Specifiek te controleren velden:

| Veld | Huidige waarde | Status na 2026.3.23 |
|------|---------------|---------------------|
| `gateway.auth.mode` | `"token"` | ✅ Ongewijzigd |
| `gateway.controlUi.dangerouslyDisableDeviceAuth` | `true` | ✅ Ongewijzigd |
| `channels.discord.groupPolicy` | `"open"` | ✅ Ongewijzigd |
| `channels.discord.guilds.*.requireMention` | `false` | ✅ Ongewijzigd |
| `agents.defaults.model.primary` | `"anthropic/claude-opus-4-6"` | ✅ Ongewijzigd |
| `browser.executablePath` | Nix store path chromium | ⚠️ Pad wijzigt na nix build — maar Openclaw gebruikt `pkgs.chromium` via PATH, niet het absolute pad direct |
| `models.providers.ollama.baseUrl` | `"http://10.0.1.1:11434"` | ✅ Ongewijzigd |

### .env — te controleren

```bash
# Na update: controleer of CLAWDBOT_* vars ook in .env staan (waarschijnlijk niet)
grep -i "clawdbot\|moltbot" ~/openclaw-workspace/.env
# Verwacht: geen output — als er wel iets staat, verwijderen
```

### Cron jobs — geen wijzigingen verwacht

De 7 geconfigureerde cron jobs gebruiken geen deprecated APIs. Na update:

```bash
# In VM:
openclaw cron list
# Controleer dat alle enabled jobs nog zichtbaar zijn
```

### Auth profiles — geen wijzigingen verwacht

Elk agent heeft een `auth-profiles.json` met OAuth token. Deze worden **niet** gereset door een update.

```bash
# Verificatie:
cat /home/agent/workspace/.openclaw/agents/main/agent/auth-profiles.json
# Token moet nog aanwezig zijn
```

---

## Rollback procedure

Als de update mislukt of functionaliteit breekt:

```bash
# 1. Stop VM
# Ctrl+C in beide terminals

# 2. Herstel flake.nix uit backup
BACKUP_DATE=20260324_120000
tar -xzf ~/openclaw-sandbox-backup-${BACKUP_DATE}.tar.gz

# 3. Herstel .openclaw state als nodig
tar -xzf ~/openclaw-backup-${BACKUP_DATE}.tar.gz

# 4. Bouw de oude runner opnieuw
cd ~/openclaw-sandbox
nix build  # bouwt nu weer de oude versie (want flake.nix is teruggezet)

# 5. Plugin overlay herbouwen voor oude versie
~/openclaw-sandbox/build-plugin-overlay.sh

# 6. Start VM
./result/bin/virtiofsd-run   # terminal 1
./result/bin/microvm-run     # terminal 2
```

---

## Samenvatting: wat moet absoluut veranderen

| # | Bestand | Wijziging | Risico zonder fix |
|---|---------|-----------|-------------------|
| 1 | `flake.nix` | `permittedInsecurePackages`: `2026.3.12` → `2026.3.23` | Nix build faalt |
| 2 | `flake.nix` | `nodejs_20` + `corepack_20` → `nodejs_22` | Gateway/dashboard start niet |
| 3 | `flake.nix` | `CLAWDBOT_STATE_DIR` + `CLAWDBOT_CONFIG_PATH` verwijderen uit project-a service | Project-A gateway vindt config niet |
| 4 | Na build | `build-plugin-overlay.sh` opnieuw uitvoeren | Discord plugin laadt niet, bot offline |
| 5 | In VM | `openclaw doctor --fix` uitvoeren | Mogelijke legacy config remnants |

**Aanbevolen volgorde:** Backup → flake.nix wijzigen → nix flake update → nix build → overlay herbouwen → VM starten → doctor --fix → testen.
