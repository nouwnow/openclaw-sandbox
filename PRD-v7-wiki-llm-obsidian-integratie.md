# PRD v7 — Wiki-LLM & Obsidian Integratie voor OpenClaw + Hermes-Agent

**Status:** 📋 Implementatieplan — opgesteld 2026-04-11
**Scope:** Centrale Obsidian-vault gedeeld via virtiofs met beide VMs, qmd als MCP search-server, obsidian-llm-wiki als vault-brug voor agents
**Doelgroep:** Michiel — setup met openclaw-sandbox (10.0.1.2) en hermes-sandbox (10.0.2.2)

> **Leeswijzer:** Begin bij §2 (Architectuur) voor het grote plaatje. Direct aan de slag? §4 (Stap-voor-stap). Valkuilen? §6.

---

## Inhoudsopgave

1. [Waarom dit systeem](#1-waarom-dit-systeem)
2. [Architectuuroverzicht](#2-architectuuroverzicht)
3. [Toolkeuze verantwoording](#3-toolkeuze-verantwoording)
4. [Stap-voor-stap implementatie](#4-stap-voor-stap-implementatie)
5. [MCP configuratie per VM](#5-mcp-configuratie-per-vm)
6. [Bekende valkuilen](#6-bekende-valkuilen)
7. [Gebruik door agents](#7-gebruik-door-agents)

---

## 1. Waarom dit systeem

Agents (OpenClaw en Hermes) hebben nu alleen toegang tot wat er in hun context zit of wat ze via API ophalen. Er is geen gedeelde, persistente kennisbasis die:

- over sessies heen bewaard blijft
- door meerdere agents parallel geraadpleegd kan worden
- door mensen (via Obsidian) onderhouden kan worden
- incrementeel door agents zelf uitgebreid kan worden (Karpathy LLM-wiki patroon)

**Concrete use cases:**
- Researcher haalt bronnen op → schrijft samenvatting naar wiki → Writer pikt dat op zonder alles opnieuw te doen
- Hermes-agent slaat analyse op → OpenClaw-coordinator leest dat terug in volgende sessie
- Michiel schrijft instructies/context in Obsidian → agents zien die meteen

---

## 2. Architectuuroverzicht

```
Host (michiel)
├── ~/wiki-vault/                 ← centrale Obsidian-vault
│   ├── raw/                      ← bronbestanden (immutable, niet door agents gewijzigd)
│   ├── wiki/                     ← LLM-onderhouden pagina's (agents schrijven hier)
│   ├── output/                   ← query-resultaten / rapporten
│   └── index.md                  ← zelf-onderhouden inhoudsopgave
│
├── Obsidian (host)               ← schrijft/leest ~/wiki-vault/
│
├── openclaw-sandbox VM (10.0.1.2)
│   ├── virtiofs: ~/wiki-vault  → /home/agent/wiki  (read-write)
│   ├── MCP: obsidian-llm-wiki   (stdio, connector.js → /home/agent/wiki)
│   └── MCP: qmd                 (stdio, indexeert /home/agent/wiki/wiki/)
│
└── hermes-sandbox VM (10.0.2.2)
    ├── virtiofs: ~/wiki-vault  → /home/agent/wiki  (read-write)
    ├── MCP: obsidian-llm-wiki   (stdio, connector.js → /home/agent/wiki)
    └── MCP: qmd                 (stdio, indexeert /home/agent/wiki/wiki/)
```

**Kernprincipe:** Één vault op de host, beide VMs mounten die via virtiofs. Elke VM heeft zijn eigen qmd-index (in workspace, persistent via virtiofs). Geen centrale HTTP-server nodig — stdio MCP werkt eenvoudiger en betrouwbaarder.

---

## 3. Toolkeuze verantwoording

| Tool | Rol | Waarom |
|---|---|---|
| **Obsidian** | Vault-editor op host | Bestaand gereedschap, schrijft plain Markdown |
| **obsidian-llm-wiki** | MCP-brug vault ↔ agents | Leest/schrijft/zoekt Markdown via MCP tools (read, search, create, lint) |
| **qmd** (`@tobilu/qmd`) | Hybride search-engine | BM25 + vector + LLM-reranking, npm-installeerbaar, MCP stdio mode |
| **virtiofs** | Vault-mount in VMs | Al aanwezig in beide flake.nix, geen extra infra |

**Waarom geen centrale qmd HTTP-server:**
- HTTP MCP client-config in Claude Code vereist extra setup
- SQLite-locking problemen bij gedeelde index over virtiofs
- Per-VM stdio is eenvoudiger en werkt gegarandeerd

**wiki-llm is geen OpenClaw-plugin** — het is het Karpathy LLM-wiki patroon, geïmplementeerd als Claude Code MCP plugin. Werkt ook in hermes-agent omdat beide VMs `.claude` gemount hebben.

---

## 4. Stap-voor-stap implementatie

### Stap 1 — Vault aanmaken op host

```bash
mkdir -p ~/wiki-vault/{raw,wiki,output}
cat > ~/wiki-vault/index.md << 'EOF'
# Wiki Index

## Categorieën
- [Logies op Dreef](wiki/logies-op-dreef.md)

## Recent bijgewerkt
<!-- agents vullen dit aan -->
EOF
```

Obsidian: open `~/wiki-vault/` als vault.

---

### Stap 2 — Virtiofs mount toevoegen aan openclaw-sandbox

Bestand: `~/openclaw-sandbox/flake.nix`

In de `shares = [ ... ]` sectie (rond regel 85):

```nix
shares = [
  { source = "/nix/store";                   mountPoint = "/nix/store";            tag = "ro-store";      proto = "virtiofs"; }
  { source = hostWorkspace;                  mountPoint = "/home/agent/workspace"; tag = "openclaw-data"; proto = "virtiofs"; }
  { source = "${hostWorkspace}/.claude";     mountPoint = "/home/agent/.claude";   tag = "agent-claude";  proto = "virtiofs"; }
  { source = "${hostWorkspace}/.npm-global"; mountPoint = "/home/agent/.npm-global"; tag = "agent-npm";   proto = "virtiofs"; }
  # ── Wiki-vault (nieuw) ──────────────────────────────────────────
  { source = "/home/michiel/wiki-vault";     mountPoint = "/home/agent/wiki";      tag = "wiki-vault";    proto = "virtiofs"; }
];
```

En in `systemd.tmpfiles.rules` (bestaande sectie uitbreiden):

```nix
"d /home/agent/wiki                0755 agent agent -"
"d /home/agent/workspace/.qmd      0755 agent agent -"
```

---

### Stap 3 — Virtiofs mount toevoegen aan hermes-sandbox

Bestand: `~/hermes-sandbox/flake.nix`

Zelfde patroon als stap 2. In de `shares = [ ... ]` sectie:

```nix
shares = [
  { source = "/nix/store";                   mountPoint = "/nix/store";            tag = "ro-store";     proto = "virtiofs"; }
  { source = hostWorkspace;                  mountPoint = "/home/agent/workspace"; tag = "hermes-data";  proto = "virtiofs"; }
  { source = "${hostWorkspace}/.claude";     mountPoint = "/home/agent/.claude";   tag = "agent-claude"; proto = "virtiofs"; }
  { source = "${hostWorkspace}/.npm-global"; mountPoint = "/home/agent/.npm-global"; tag = "agent-npm";  proto = "virtiofs"; }
  # ── Wiki-vault (nieuw) ──────────────────────────────────────────
  { source = "/home/michiel/wiki-vault";     mountPoint = "/home/agent/wiki";      tag = "wiki-vault";   proto = "virtiofs"; }
];
```

En in `systemd.tmpfiles.rules`:

```nix
"d /home/agent/wiki                0755 agent agent -"
"d /home/agent/workspace/.qmd      0755 agent agent -"
```

---

### Stap 4 — VMs rebuilden en herstarten

```bash
# Op de host:
cd ~/openclaw-sandbox && sudo nixos-rebuild switch --flake .#openclaw-vm
cd ~/hermes-sandbox   && sudo nixos-rebuild switch --flake .#hermes-vm
```

Controleer daarna in beide VMs:

```bash
ls /home/agent/wiki   # moet leeg zijn maar bestaan
```

---

### Stap 5 — qmd installeren (per VM, via agent-shell)

Voer dit uit in de shell van elke VM (als agent):

```bash
# qmd globaal installeren (npm-global is persistent via virtiofs)
npm install -g @tobilu/qmd

# wiki-collectie registreren
qmd collection add /home/agent/wiki/wiki --name wiki

# initiële index bouwen
qmd embed

# controleer status
qmd status
```

qmd slaat de database op in `~/.local/share/qmd/` — dit valt buiten virtiofs.
Gebruik de workspace voor persistentie:

```bash
# Overschrijf het standaard db-pad via env var of symlink:
mkdir -p /home/agent/workspace/.qmd
# In .bashrc of sessionVariables:
export QMD_DB_PATH=/home/agent/workspace/.qmd/index.sqlite
```

Voeg toe aan `flake.nix` `environment.sessionVariables`:

```nix
environment.sessionVariables.QMD_DB_PATH = "/home/agent/workspace/.qmd/index.sqlite";
```

---

### Stap 6 — obsidian-llm-wiki installeren (per VM)

```bash
# Clone naar workspace (persistent via virtiofs)
cd /home/agent/workspace
git clone https://github.com/2233admin/obsidian-llm-wiki
cd obsidian-llm-wiki
npm install
npm run build   # of: node connector.js --help om te controleren
```

---

### Stap 7 — MCP configuratie (zie §5)

---

## 5. MCP configuratie per VM

### openclaw-sandbox — Claude Code (`~/.claude/settings.json`)

Pad op host: `~/openclaw-workspace/.claude/settings.json`

```json
{
  "mcpServers": {
    "llm-wiki": {
      "command": "node",
      "args": [
        "/home/agent/workspace/obsidian-llm-wiki/connector.js",
        "/home/agent/wiki"
      ]
    },
    "qmd": {
      "command": "qmd",
      "args": ["mcp"]
    }
  }
}
```

> Als er al andere mcpServers staan: voeg `llm-wiki` en `qmd` toe aan het bestaande object.

---

### hermes-sandbox — Claude Code (`~/.claude/settings.json`)

Pad op host: `~/hermes-workspace/.claude/settings.json`

Identieke configuratie als openclaw — paden zijn gelijk want beide VMs mounten identiek als `/home/agent/...`.

```json
{
  "mcpServers": {
    "llm-wiki": {
      "command": "node",
      "args": [
        "/home/agent/workspace/obsidian-llm-wiki/connector.js",
        "/home/agent/wiki"
      ]
    },
    "qmd": {
      "command": "qmd",
      "args": ["mcp"]
    }
  }
}
```

---

### hermes-agent native tool config (indien van toepassing)

Als hermes-agent zijn eigen tool-registry heeft (buiten Claude Code), raadpleeg dan de hermes-agent docs voor hoe MCP servers worden geconfigureerd. De vault-paden blijven gelijk: `/home/agent/wiki`.

---

## 6. Bekende valkuilen

| Probleem | Oorzaak | Oplossing |
|---|---|---|
| `qmd embed` verliest index na herstart | Standaard db in `~/.local/share/qmd/` valt buiten virtiofs | Stel `QMD_DB_PATH` in op workspace-pad |
| Wiki-vault mount mislukt bij boot | virtiofsd start soms trager dan de VM | `virtiofsd` in flake.nix heeft al een while-loop restart — normaal gesproken ok |
| Beide VMs schrijven tegelijk naar zelfde wiki-pagina | Race-condition op virtiofs | Afspraken in agent-config: alleen de "writer" agent schrijft naar `wiki/`, anderen alleen lezen |
| `connector.js` kan vault niet vinden | Verkeerd pad in MCP args | Controleer dat `/home/agent/wiki` gemount is vóór claude opstart |
| qmd `embed` duurt lang bij eerste run | Embeddings worden berekend voor alle files | Normaal — volgende keren incrementeel |
| `tag` conflict in virtiofs | Beide VMs gebruiken al "wiki-vault" als tag | Tags zijn per-VM uniek in microvm.nix — geen probleem |

---

## 7. Gebruik door agents

### Beschikbare MCP tools via obsidian-llm-wiki

| Tool | Functie |
|---|---|
| `vault.read` | Lees een specifieke pagina |
| `vault.search` | Zoek op trefwoord in de vault |
| `vault.create` | Maak nieuwe pagina aan |
| `vault.get_metadata` | Lees frontmatter + links |
| `vault.lint` | Check op orphans / broken links |
| `vault.batch` | Meerdere operaties in één call |

### Beschikbare MCP tools via qmd

| Tool | Functie |
|---|---|
| `search` | Hybride BM25 + vector zoekactie |
| `get` | Haal specifiek document op |

### Schrijfconventie voor agents

Agents schrijven alleen naar `wiki/`, nooit naar `raw/`:

```markdown
---
title: VikBooking Rooms Analyse
tags: [vikbooking, analyse, logies-op-dreef]
updated: 2026-04-11
agent: researcher
---

# VikBooking Rooms Analyse

...inhoud...
```

### Voorbeeld agent-instructie

```
Zoek in de wiki naar eerdere analyses over VikBooking:
1. Gebruik qmd search "VikBooking rooms" om relevante pagina's te vinden
2. Lees de gevonden pagina's via vault.read
3. Schrijf je bevindingen naar wiki/vikbooking-update-2026-04-11.md
```

---

## Checklist implementatie

- [ ] Stap 1: `~/wiki-vault/` aangemaakt op host
- [ ] Stap 2: virtiofs mount toegevoegd aan `openclaw-sandbox/flake.nix`
- [ ] Stap 3: virtiofs mount toegevoegd aan `hermes-sandbox/flake.nix`
- [ ] Stap 4: beide VMs gerebuilt en herstart
- [ ] Stap 5a: qmd geïnstalleerd in openclaw VM
- [ ] Stap 5b: qmd geïnstalleerd in hermes VM
- [ ] Stap 5c: `QMD_DB_PATH` env var toegevoegd aan beide flake.nix
- [ ] Stap 6a: obsidian-llm-wiki gecloned in openclaw workspace
- [ ] Stap 6b: obsidian-llm-wiki gecloned in hermes workspace
- [ ] Stap 7a: MCP config toegevoegd aan openclaw `.claude/settings.json`
- [ ] Stap 7b: MCP config toegevoegd aan hermes `.claude/settings.json`
- [ ] Obsidian vault geopend op `~/wiki-vault/`
- [ ] Test: agent kan `vault.search "test"` uitvoeren
- [ ] Test: agent kan pagina aanmaken in `wiki/`
