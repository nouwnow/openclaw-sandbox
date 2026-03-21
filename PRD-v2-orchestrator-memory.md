# PRD — Openclaw v2: Multi-Project Orchestrator + Hybride Memory
**Status:** Draft
**Datum:** 2026-03-21
**Scope:** Architectuuruitbreiding van het bestaande openclaw-sandbox systeem

---

## 1. Huidige situatie (baseline)

### 1.1 Gateway & agents
Één gateway op poort 18789 met vier interne agents in één `openclaw.json`:
```
coordinator (default, Discord-facing)
    ├── writer
    ├── researcher
    └── editor
```
Alle agents delen één `stateDir`: `/home/agent/workspace/.openclaw`
Geen project-isolatie. Alles zit in dezelfde workspace.

### 1.2 Memory — wat er nu is
| Bestand | Type | Status |
|---|---|---|
| `.openclaw/memory/main.sqlite` | SQLite + embeddings (RAG) | Aanwezig, leeg |
| `.openclaw/memory/coordinator.sqlite` | Coordinator-specifiek SQLite | Aanwezig |
| `.openclaw/agents/*/sessions/*.jsonl` | Per-sessie JSONL | Actief gebruikt |
| `.openclaw/workspace/SOUL.md` | Identiteits-markdown | Actief |
| `.openclaw/workspace/USER.md` | Gebruikersprofiel | Actief |
| `.openclaw/workspace-writer/MEMORY.md` | Langetermijngeheugen (writer) | Aanwezig |
| `memory/YYYY-MM-DD.md` | Dagnotities per agent | In AGENTS.md gedefinieerd |

**Conclusie:** Methode 1 (Folders) bestaat al gedeeltelijk. Methode 2 (SQLite/embeddings) is aangelegd maar niet gevuld. Methode 3 (MEM0) en systematische Methode 4 (agent-driven SQLite) ontbreken.

### 1.3 Dashboard (Mission Control)
Next.js app op poort 3333, 5 tabs:
- **Dashboard** — live feed, agents, sessions, taken
- **Office** — pixel art met live gateway events
- **Calendar** — cron/schedule beheer
- **Memory** — sessie-viewer, workspace markdown
- **Docs** — document viewer met categorieën

### 1.4 Discord-kanalen
| Kanaal | ID | Gebruik |
|---|---|---|
| #alerts | 1484546106669928499 | Urgente meldingen |
| #daily-digest | 1484546226262249622 | Dagelijkse samenvattingen |
| #research | 1484546274404339863 | Onderzoeksresultaten |
| #social-drafts | 1484546314296365139 | Social media concepten |
| #script-drafts | 1484546366083567746 | Scripts en teksten |
| #approvals | 1484546410278813776 | Goedkeuringsverzoeken |

---

## 2. Doelstelling

Uitbreiding naar een systeem met:
1. **Één centrale orchestrator** die meerdere project-gateways aanstuurt
2. **Hybride memory** per project (folders + SQLite + MEM0-achtige extractie)
3. **Minimale context per delegatie** — agents krijgen alleen wat ze nodig hebben
4. **Dashboard en Discord** volledig bijgewerkt voor multi-project overzicht

---

## 3. Doelarchitectuur

```
Discord / Mission Control
          │
          ▼
┌─────────────────────────────────────────────────────────┐
│  ORCHESTRATOR  (poort 18789)                            │
│  - Kent alle projecten                                  │
│  - Routeert op basis van Discord-kanaal of trefwoord    │
│  - Stuur alleen de taakinstructie door, nooit context   │
│  - Leest: orchestrator/routing.md, orchestrator/projects.md │
└──────────────┬──────────────────────┬───────────────────┘
               │                      │
               ▼                      ▼
┌──────────────────────┐  ┌──────────────────────────────┐
│  PROJECT-A GATEWAY   │  │  PROJECT-B GATEWAY           │
│  (poort 18790)       │  │  (poort 18791)               │
│                      │  │                              │
│  Agents:             │  │  Agents:                     │
│  - coordinator-a     │  │  - coordinator-b             │
│  - writer-a          │  │  - analyst-b                 │
│  - researcher-a      │  │  - researcher-b              │
│                      │  │                              │
│  Workspace:          │  │  Workspace:                  │
│  /project-a/.openclaw│  │  /project-b/.openclaw        │
│                      │  │                              │
│  Memory (hybride):   │  │  Memory (hybride):           │
│  - memory/folders/   │  │  - memory/folders/           │
│  - memory/search.db  │  │  - memory/search.db          │
│  - memory/facts.db   │  │  - memory/facts.db           │
└──────────────────────┘  └──────────────────────────────┘
```

---

## 4. Hybride Memory Architectuur

### 4.1 Overzicht vier methoden

| Methode | Type | Wanneer laden | Setup |
|---|---|---|---|
| **Folders** | Markdown bestanden | Altijd bij sessie-start | Dag 1 |
| **SQLite search** | Ingebouwde vector-search | Via "Remember/Search" commando's | Dag 2 |
| **Extractie-agent** | Automatische samenvatting | Na elke sessie | Dag 3 |
| **Facts DB** | Agent-beheerde SQLite | Bij gestructureerde queries | Dag 4 |

### 4.2 Methode 1 — Memory Folders (al gedeeltelijk aanwezig)

**Doelstructuur per project:**
```
/workspace/{project}/.openclaw/workspace/
├── SOUL.md              ← agent identiteit (al aanwezig)
├── USER.md              ← gebruikersprofiel (al aanwezig)
├── IDENTITY.md          ← naam/avatar (al aanwezig)
├── HEARTBEAT.md         ← periodieke taken (al aanwezig)
├── MEMORY.md            ← curated langetermijngeheugen (al aanwezig bij writer)
└── memory/
    ├── YYYY-MM-DD.md    ← dagelijkse logs (al in AGENTS.md gedefinieerd)
    ├── projects/
    │   ├── goals.md     ← projectdoelen ← NIEUW
    │   └── decisions.md ← genomen beslissingen ← NIEUW
    └── preferences/
        ├── writing-style.md  ← stijlgids ← NIEUW
        └── tools.md          ← tool-voorkeuren ← NIEUW
```

**AGENTS.md uitbreiding voor alle agents:**
```markdown
## Memory Update Protocol
Bij het einde van elke sessie:
1. Schrijf key facts naar `memory/YYYY-MM-DD.md`
2. Als een projectbeslissing is genomen: update `memory/projects/decisions.md`
3. Als een nieuw stijlpatroon is geleerd: update `memory/preferences/writing-style.md`
4. Update `MEMORY.md` als er iets significant te onthouden is voor de lange termijn
```

**Implementatie:** Alleen AGENTS.md aanpassen + mappen aanmaken. Geen code.

### 4.3 Methode 2 — SQLite Memory Search (infrastructuur aanwezig)

`memory/main.sqlite` en `memory/coordinator.sqlite` bestaan al maar zijn leeg.

Het Openclaw memory-systeem ondersteunt native vector-search via deze SQLite-bestanden (schema: `files`, `chunks`, `embedding_cache`). Voor embeddings is een externe API-key nodig (OpenAI of Voyage — niet Claude-only).

**Activatie-stappen:**
1. Voeg `VOYAGE_API_KEY` of `OPENAI_API_KEY` toe aan `.env`
2. Configureer in `openclaw.json`:
```json
"memory": {
  "search": {
    "enabled": true,
    "provider": "voyage",
    "indexPaths": [
      "/home/agent/workspace/{project}/.openclaw/workspace/memory/"
    ]
  }
}
```
3. Agents kunnen dan: `Remember: [feit]` en `Search memory: [query]`

**Optionele alternatief zonder externe API:** Sla `memory/main.sqlite` over en gebruik alleen Methode 1 + 4.

### 4.4 Methode 3 — Automatische Geheugenextractie (Extractie-agent)

In plaats van een extern MEM0-platform: een interne Openclaw **extractie-cron** die na elke voltooide sessie automatisch key facts extraheert en opslaat.

**Architectuur:**
```
Na elke agent lifecycle end →
  cron-job "memory-extractor" →
  Leest laatste sessie JSONL →
  Prompt: "Extraheer max 5 key facts uit dit gesprek als bullets" →
  Schrijft naar memory/YYYY-MM-DD.md + updates MEMORY.md indien significant
```

**Implementatie:** Nieuwe cron in `jobs.json`:
```json
{
  "id": "memory-extractor",
  "name": "geheugen-extractie",
  "schedule": { "kind": "cron", "expr": "0 23 * * *", "tz": "Europe/Amsterdam" },
  "payload": {
    "kind": "agentTurn",
    "message": "Lees de sessie-logs van vandaag in agents/coordinator/sessions/ en schrijf een samenvatting van max 10 key facts naar memory/$(date +%Y-%m-%d).md. Focus op beslissingen, geleerde feiten en actiepunten.",
    "timeoutSeconds": 120
  }
}
```

### 4.5 Methode 4 — Facts Database (Agent-beheerde SQLite)

Voor gestructureerde projectdata: agents maken een projectspecifieke SQLite-database.

**Voorbeeld schema voor een content-project:**
```sql
CREATE TABLE content_pieces (
  id TEXT PRIMARY KEY,
  title TEXT,
  type TEXT,  -- article/newsletter/script
  status TEXT, -- draft/review/published
  path TEXT,
  created_at TEXT,
  agent TEXT
);

CREATE TABLE research_facts (
  id TEXT PRIMARY KEY,
  topic TEXT,
  fact TEXT,
  source TEXT,
  confidence TEXT, -- high/medium/low
  created_at TEXT
);
```

**Aanmaak:** Via Claude in de VM:
```
Maak een SQLite database facts.db aan in /workspace/project-a/memory/
met tabel research_facts (id, topic, fact, source, confidence, created_at)
en content_pieces (id, title, type, status, path, created_at, agent).
Test met een INSERT en SELECT.
```

---

## 5. Multi-Project Gateway Architectuur

### 5.1 Mappenstructuur per project

```
~/openclaw-workspace/
├── .openclaw/               ← orchestrator (bestaand, poort 18789)
│   ├── openclaw.json        ← orchestrator-config
│   └── workspace/
│       ├── AGENTS.md        ← routeringstabel + projectoverzicht
│       └── memory/
├── project-a/               ← NIEUW
│   └── .openclaw/
│       ├── openclaw.json    ← project-a config (eigen agents + tools)
│       └── workspace/
│           ├── SOUL.md
│           ├── USER.md
│           └── memory/
└── project-b/               ← NIEUW
    └── .openclaw/
        ├── openclaw.json
        └── workspace/
```

### 5.2 flake.nix uitbreiding

Per project één extra systemd-service + virtiofs-mount:

```nix
# Project-A gateway (poort 18790)
services.openclaw-gateway-project-a = {
  enable       = true;
  package      = pkgs.openclaw;
  user         = "agent";
  group        = "agent";
  createUser   = false;
  stateDir     = "/home/agent/workspace/project-a/.openclaw";
  port         = 18790;
  environmentFiles = [ "/home/agent/workspace/.env" ];
  restart      = "always";
  logPath      = "/tmp/openclaw-project-a.log";
  config.gateway.mode = "local";
};

# Firewall openen voor project-gateways (intern verkeer)
networking.firewall.allowedTCPPorts = [ 3333 18790 18791 ];
```

### 5.3 Orchestrator routing

De orchestrator-coordinator krijgt een uitgebreide `AGENTS.md` met routeringstabel:

```markdown
## Project Routing

| Trigger | Gateway | Poort | Gebruik voor |
|---|---|---|---|
| "project-a" of "#website" | project-a | 18790 | Website content |
| "project-b" of "#admin" | project-b | 18791 | Administratie |
| Geen trigger | Zelf afhandelen | — | Algemene vragen |

## Delegatie protocol
Stuur bij delegatie naar een project-gateway ALLEEN:
1. De taakomschrijving (max 2 zinnen)
2. Het gewenste output-formaat
3. Het doelkanaal voor het resultaat

Stuur NOOIT mee: andere project-context, eerdere gesprekken, bestanden van andere projecten.
```

### 5.4 openclaw.json orchestrator (uitgebreid)

```json
{
  "agents": {
    "list": [
      {
        "id": "coordinator",
        "default": true,
        "name": "Orchestrator",
        "subagents": {
          "allowAgents": ["project-a-coord", "project-b-coord"]
        }
      },
      {
        "id": "project-a-coord",
        "name": "Project A",
        "gateway": { "url": "ws://127.0.0.1:18790" }
      },
      {
        "id": "project-b-coord",
        "name": "Project B",
        "gateway": { "url": "ws://127.0.0.1:18791" }
      }
    ]
  }
}
```

---

## 6. Dashboard Uitbreidingen (Mission Control)

### 6.1 Nieuwe tab: Projects

Nieuw tabblad tussen Dashboard en Office:

```
Nav: Dashboard | Projects | 🏢 Office | Calendar | Memory | Docs
```

**Projects tab toont:**
- Lijst van alle actieve project-gateways (naam, poort, status)
- Per project: aantal actieve sessies, laatste activiteit
- Quick-switch: klik op project → alle andere tabs filteren op dat project
- Gateway health indicator (groen/rood) via `/api/projects` route

**API route `/api/projects`:**
```typescript
// Leest project-gateways uit een config-bestand
// Doet een health-check per gateway (WebSocket ping)
// Returnt: [{ id, name, port, url, status, lastActivity }]
```

### 6.2 Memory tab uitbreiding

**Toevoegen aan de Memory tab:**
- **Memory folders** viewer — naast de bestaande sessie-viewer: toon MEMORY.md, projects/goals.md, projects/decisions.md, preferences/
- **Facts DB** viewer — toon inhoud van facts.db per project als tabel
- **Project filter** — filter geheugen per project-gateway

### 6.3 Dashboard tab uitbreiding

- **Project selector** — dropdown bovenin om te filteren per project-gateway
- **Multi-gateway feed** — live events van alle project-gateways gecombineerd

### 6.4 Office tab uitbreiding

- **Project kamers** — de pixel art office krijgt afgebakende zones per project
- Agents van Project-A bewegen in de linkerhelft, Project-B rechts
- Orchestrator staat centraal en beweegt naar beide zones bij delegatie

---

## 7. Discord Uitbreidingen

### 7.1 Nieuwe kanalen per project

| Kanaal | Gebruik |
|---|---|
| `#project-a-output` | Alles van project-a gateway |
| `#project-b-output` | Alles van project-b gateway |
| `#orchestrator-log` | Alleen routing-beslissingen van orchestrator |

### 7.2 Routing-update in orchestrator AGENTS.md

```markdown
## Discord Project-kanalen
- `#project-a-output` (ID: ...) — stuur project-a resultaten hier naartoe
- `#project-b-output` (ID: ...) — stuur project-b resultaten hier naartoe
- Vraag van gebruiker in `#website` → delegeer naar project-a, output naar `#project-a-output`
```

---

## 8. Implementatiefasen

### Fase 1 — Memory Folders (1-2 uur, geen rebuild)
- [ ] Maak mappenstructuur aan via Claude in de VM
- [ ] Update AGENTS.md voor coordinator, writer, researcher, editor
- [ ] Test: voer een taak uit, controleer of `memory/YYYY-MM-DD.md` wordt aangemaakt

### Fase 2 — Facts DB (1 uur, geen rebuild)
- [ ] Laat coordinator `facts.db` aanmaken in workspace
- [ ] Voeg instructie toe aan AGENTS.md: "sla key research facts op in facts.db"
- [ ] Test: vraag om facts, controleer DB

### Fase 3 — Extractie-cron (30 min, geen rebuild)
- [ ] Voeg `memory-extractor` cron toe via Calendar tab
- [ ] Test na einde van een sessie

### Fase 4 — Project-A gateway (rebuild vereist)
- [ ] Maak `~/openclaw-workspace/project-a/.openclaw/` aan
- [ ] Schrijf `project-a/openclaw.json` met eigen agents
- [ ] Voeg service toe aan `flake.nix` (port 18790)
- [ ] `nix build` + herstart
- [ ] Koppel orchestrator via agent-binding

### Fase 5 — Dashboard Projects tab (geen rebuild na deploy)
- [ ] Bouw `/api/projects` route
- [ ] Bouw Projects tab in Next.js
- [ ] Update Memory tab met folder-viewer en facts-viewer
- [ ] `sudo systemctl restart openclaw-dashboard`

### Fase 6 — Memory Search (optioneel, vereist API-key)
- [ ] Kies provider: Voyage AI (goedkoop) of OpenAI
- [ ] Voeg API-key toe aan `.env`
- [ ] Activeer in `openclaw.json`
- [ ] Test "Remember" en "Search" commando's

---

## 9. Wat we NIET bouwen (scope-afbakening)

- ❌ MEM0 third-party plugin — te veel afhankelijkheid, extractie-cron is equivalent
- ❌ Aparte gateway per sub-agent (writer/researcher/editor) — overkill voor huidige schaal
- ❌ Cross-project context doorsturen — bewust verboden voor isolatie
- ❌ Aparte VM per project — te zwaar, één VM met meerdere gateways volstaat

---

## 10. Risico's en mitigatie

| Risico | Impact | Mitigatie |
|---|---|---|
| Orchestrator delegeert verkeerd project | Hoog | Expliciete routeringstabel in AGENTS.md + test scenario's |
| Memory groeit onbeheersbaar | Laag | Dagelijkse extractie overschrijft niet — archiveer per maand |
| flake.nix rebuild breekt bestaande setup | Hoog | Test eerst op branch, backup `.openclaw/` voor rebuild |
| SQLite corruptie door gelijktijdige writes | Medium | Eén agent schrijft per project, geen concurrent writes |
| Port conflict (18790 al in gebruik) | Laag | Check met `ss -tlnp` voor activatie |

---

## 11. Acceptatiecriteria

**Fase 1-3 klaar wanneer:**
- Coordinator schrijft automatisch naar `memory/YYYY-MM-DD.md` na elke sessie
- Na 3 dagen: `memory/projects/decisions.md` bevat merkbare beslissingen
- Facts DB bevat minimaal 5 research-facts na een research-taak

**Fase 4 klaar wanneer:**
- `sudo systemctl status openclaw-gateway-project-a` toont `active (running)`
- Discord-bericht gericht aan project-a gaat via orchestrator naar de juiste gateway
- Project-A heeft geen toegang tot project-B workspace (verifieer met `ls`)

**Fase 5 klaar wanneer:**
- Projects tab toont beide gateways met status-indicator
- Memory tab toont MEMORY.md content naast sessie-logs
- Office tab toont project-zones

---

*Dit PRD is leidend voor de implementatie. Begin met Fase 1 — die vereist geen rebuild en geeft direct zichtbaar resultaat.*
