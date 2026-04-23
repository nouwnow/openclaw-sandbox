# Analyse: Lab Decision Board & Team Organogram — OpenClaw Dashboard

**Datum:** 2026-03-28
**Status:** Afgerond — alle fixes doorgevoerd

---

## Uitgevoerde acties (2026-03-28)

### Fixes

| # | Probleem | Oorzaak | Fix |
|---|----------|---------|-----|
| 1 | Lege tasklist in Lab Decision Board | `agent-tasks.json` bevatte invalide JSON op r.125 — Muddy had het bestand direct beschreven (buiten de API om), de LLM genereerde een dubbel veld zonder `key: value` formaat. De stille `catch {}` in `readTasks()` gaf `[]` terug zonder foutmelding. | Drie corrupte regels verwijderd. Bestand valideert nu correct (11 taken). |
| 2 | Skills-endpoint HTTP 500 (organogram leeg) | `readCronJobs()` in `dashboard/src/app/api/skills/route.ts` verwachtte een JSON-array, maar `cron/jobs.json` heeft format `{"version":1,"jobs":[...]}`. Het `.find()` op het geretourneerde object crashte onvangen. | `readCronJobs()` aangepast: `Array.isArray(data) ? data : (data.jobs ?? [])`. |

### Dashboard verbeteringen

| Onderdeel | Wijziging |
|-----------|-----------|
| Lab Decision Board (`lab/page.tsx`) | Taaknamen klikbaar → rechter detailpaneel met volledige omschrijving, plan, resultaat en feedback. Ook archief-titels klikbaar. `result` veld toegevoegd aan interface. |
| Sessieoverzicht (`page.tsx`) | Leesbare sessielabels ipv ruwe sleutels (`agent:muddy:cron:...`). Agent-emoji, sessienaam, model, tokens, context%-meter. Verwijderknop verschijnt bij hover. |
| Sessions API (`api/sessions/route.ts`) | DELETE endpoint toegevoegd (`sessions.delete` via gateway). |

### Preventieve maatregelen

| Bestand | Toevoeging |
|---------|-----------|
| `workspace/AGENTS.md` | Red Line toegevoegd: `agent-tasks.json` is read-only voor alle agents (incl. Muddy bij "doe het nu"). Schrijven gaat uitsluitend via `curl` naar de dashboard API (`POST`/`PATCH`). Met uitleg waarom directe writes gevaarlijk zijn. |
| `workspace-elon/skills/skill-building/SKILL.md` | Gouden regel #6 toegevoegd (zelfde principe, Elon-specifieke context). Stap 3e (cron toevoegen) herschreven met correct Python-patroon dat de `{"version":1,"jobs":[...]}` wrapper respecteert + validatie-check. |

### Sessies
Sessies werken via de WebSocket-gateway en waren niet aangetast. De gateway leverde tijdens de gehele periode correct 17+ actieve sessies.

---

## Samenvatting van de problemen

Er zijn **twee afzonderlijke oorzaken** voor de lege weergave:

1. **`/api/agent-tasks` geeft een lege array terug** — het JSON-bestand met taken bestaat niet (of is leeg) op het pad dat de dashboard-server verwacht.
2. **`/api/skills` geeft HTTP 500 terug** — de endpoint crasht, waardoor ook het team-organogram leeg blijft.

Bovendien geldt: zelfs als de API wél taken teruggeeft, zijn **alle 10 huidige taken `done` of `cancelled`**, en die worden standaard verborgen door het frontend-filter. Ze zijn pas zichtbaar als de gebruiker op "Toon voltooid" klikt.

Sessies werken **wel correct** — die worden via de WebSocket-gateway opgehaald en zijn niet aangetast.

---

## 1. Takenlijst — Lab Decision Board

### Hoe werkt het?

**Frontend** (`dashboard/src/app/lab/page.tsx`)
- Haalt elke 30 seconden op: `GET /api/agent-tasks`
- Filtert bij binnenkomst: alleen taken met status **niet** in `['done', 'cancelled']` worden standaard getoond
- Kolommen: `proposed` | `in_progress` | `blocked` | `review`
- Voltooide taken zijn verborgen achter een toggle (`showDone`)

**Backend** (`dashboard/src/app/api/agent-tasks/route.ts`)
- Leest van schijf: `process.env.AGENT_TASKS_FILE || '/home/agent/workspace/.openclaw/agent-tasks.json'`
- Geeft de volledige array terug — geen serverside filtering op status

### Het probleem

```
API-resultaat:   {"tasks": []}   ← leeg
Bestand op host: /home/michiel/openclaw-workspace/.openclaw/agent-tasks.json  ← 10 taken aanwezig
Pad op VM:       /home/agent/workspace/.openclaw/agent-tasks.json  ← BESTAAT NIET of is leeg
```

De Next.js-server draait op de VM (IP 10.0.1.2). Die leest van `/home/agent/workspace/...`. Op de host-machine staat het bestand op `/home/michiel/openclaw-workspace/...`. Dit zijn twee verschillende paden — als de virtiofs-mount er niet voor zorgt dat ze naar hetzelfde bestand wijzen, leest de server een leeg of afwezig bestand.

### Huidige taken in het bestand (op de host)

| ID | Titel | Status | Agent |
|----|-------|--------|-------|
| `warren-revenue-skill-001` | Skill: Warren Revenue Analytics | **done** | elon |
| `gary-content-insights-skill-001` | Skill: Gary Content Insights | **done** | elon |
| `LDR-2026-001` | VikBooking snapshot model + cron | **done** | elon |
| `vikbooking-bookings-skill-001` | Skill: vikbooking-bookings | **done** | elon |
| `blog-ligging-001` | Blogpost: Unieke ligging | **cancelled** | gary |
| `demo-1` | SEO-artikel: Wandelen Utrechtse Heuvelrug | **cancelled** | gary |
| `demo-2` | Matomo API koppeling | **cancelled** | warren |
| `blog-ligging-002` | Blogpost: Unieke ligging (v2) | **cancelled** | gary |
| `project-a-config-001` | Project-A configuratie upgrade | **done** | elon |
| `matomo-skill-verificatie-001` | Verificatie Matomo skill | **done** | elon |
| `wp-admin-rechten-001` | WP-admin rechten blogger_nouwnow | **done** | elon |

**Conclusie:** Alle taken zijn afgerond of geannuleerd. Zelfs als de API-verbinding hersteld wordt, zou de Kanban board leeg lijken — totdat een agent een nieuwe taak toevoegt met status `proposed`, `in_progress`, `blocked` of `review`.

---

## 2. Skills — Team Organogram

### Hoe werkt het?

**Backend** (`dashboard/src/app/api/skills/route.ts`)
- Variabele: `BASE = process.env.OPENCLAW_STATE || '/home/agent/workspace/.openclaw'`
- Scant per agent: `{BASE}/{workspace-folder}/skills/{skill-naam}/SKILL.md`
- Verrijkt met `skill.json` metadata (cron-koppeling, database-info)
- Telt SQLite-records via `python3 -c "import sqlite3..."` (subprocess)
- Leest cronjob-status uit `{BASE}/cron/jobs.json`

**Frontend** (`dashboard/src/app/team/page.tsx`)
- Roept `GET /api/skills` aan
- Toont skills per agent in organogram

### Het probleem

De API geeft **HTTP 500 Internal Server Error** terug. Mogelijke oorzaken (in volgorde van waarschijnlijkheid):

1. **Skill-mappen bestaan niet op de VM** — als `/home/agent/workspace/.openclaw/workspace-elon/skills/` niet bestaat op de VM, probeert de code verder te itereren maar loopt vast op een onverwachte fout die buiten de try/catch valt
2. **`python3` niet beschikbaar** — de `execSync` aanroep voor SQLite-record-telling gebruikt `python3`; als dit niet in PATH staat op de VM crasht de request (de inner try/catch vangt dit normaal op, maar mogelijk is er een timeout-gerelateerde fout)
3. **Uncaught exception** — `readdirSync` of `statSync` kan gooien als de onderliggende schijf onverwacht reageert

### Volledige skills-inventaris (op de host)

#### Muddy — Gedeelde Workspace (`workspace/skills/`)
| Skill | Omschrijving | Cron |
|-------|-------------|------|
| `analytics-query` | Generieke Matomo query helper | — |
| `warren-revenue-analytics` | Revenue analytics (gedeeld exemplaar) | — |

#### Elon (CTO) — `workspace-elon/skills/`
| Skill | Omschrijving | Cron | Schedule |
|-------|-------------|------|----------|
| `skill-building` | Meta-skill: nieuwe skills bouwen | — | — |
| `matomo-traffic` | Matomo snapshots ophalen via brain bridge API | ✅ `matomo-weekly-sync` | Wed 08:00 CET |
| `vikbooking-bookings` | VikBooking snapshots (SQLite tijdreeks) | ✅ `vikbooking-weekly-sync` | Wed 07:30 CET |
| `warren-revenue-analytics` | Revenue analytics voor Warren | — | — |

#### Gary (CMO) — `workspace-gary/skills/`
| Skill | Omschrijving | Cron |
|-------|-------------|------|
| `gary-content-insights` | Content insights: top-pagina's, keywords, traffic bronnen | — |

#### Warren (CRO) — `workspace-warren/skills/`
| Skill | Omschrijving | Cron |
|-------|-------------|------|
| `warren-revenue-analytics` | Revenue analytics: Matomo + VikBooking | — |

#### Memory-agent — `workspace-memory-agent/`
Geen skills-map aanwezig (puur geheugenfunctie).

---

## 3. Cron Jobs — Volledig Overzicht

7 actieve cron jobs in `/home/agent/workspace/.openclaw/cron/jobs.json`:

| Naam | Agent | Schedule | Laatste run | Status |
|------|-------|----------|-------------|--------|
| `task-checker` | Muddy | Elke 30 min | 2026-03-28 ~17:10 | ✅ ok |
| `heartbeat` | Muddy | Elke 55 min | 2026-03-28 ~17:15 | ✅ ok |
| `daily-executive-sync` | Muddy | Ma–Vr 08:30 CET | — | ✅ gepland |
| `weekly-planning` | Muddy | Zo 09:30 CET | — | ✅ gepland |
| `matomo-weekly-sync` | Elon | Woe 08:00 CET | — | ✅ gepland (31 mrt) |
| `vikbooking-weekly-sync` | Elon | Woe 07:30 CET | — | ✅ gepland (31 mrt) |
| `geheugen-extractie` | Memory-agent | Dag 07:00 CET | 2026-03-28 07:12 | ✅ ok, delivered |

**Opmerking:** De `task-checker` cron (elke 30 min) leest uit `workspace/tasks.json` (Muddy's interne workflow-taken), **niet** uit `agent-tasks.json` (het Lab Decision Board bestand). Dit zijn twee aparte bestanden met aparte doelen.

---

## 4. Sessies — Hoe werkt het?

**Backend** (`dashboard/src/app/api/sessions/route.ts`)
- Haalt op via WebSocket-gateway: `gatewayRequest('sessions.list', { limit: 20 })`
- Paden op schijf: `/home/agent/workspace/.openclaw/agents/{agent}/sessions/sessions.json`

**API-resultaat (nu, 17 sessies):**

| Sessie | Agent | Type | Model | Laatste activiteit |
|--------|-------|------|-------|-------------------|
| `dagelijkse-briefing` cron | Muddy | cron | claude-opus-4-6 | 28 mrt 18:00 |
| memory-agent subagent | Memory-agent | subagent | qwen3.5:9b (Ollama) | 28 mrt 17:59 |
| `heartbeat` main | Muddy | direct | deepseek-chat | 28 mrt 17:43 |
| `heartbeat` cron | Muddy | cron | deepseek-chat | 28 mrt 17:42 |
| `task-checker` cron | Muddy | cron | deepseek-chat | 28 mrt ~15:54 |
| Discord #algemeen | Muddy | group/discord | deepseek-chat | 28 mrt ~04:01 |
| Discord #daily-digest | Muddy | group/discord | deepseek-chat | 28 mrt ~02:14 |
| Discord c-suite | Muddy | group/discord | claude-opus-4-6 | 26 mrt |
| `executive-daily-sync` | Muddy | cron | deepseek-chat | 26 mrt |
| Elon c-suite | Elon | group/discord | claude-sonnet-4-6 | 26 mrt |
| Gary c-suite | Gary | group/discord | claude-sonnet-4-6 | ~25 mrt |
| ... | ... | ... | ... | ... |

**Conclusie:** Sessies werken correct. De gateway levert ze live aan. Het dashboard toont de actuele stand.

---

## 5. Architectuur — Padmapping host ↔ VM

```
HOST (waar jij bent, /home/michiel/)
  openclaw-workspace/
    .openclaw/
      agent-tasks.json        ← 10 taken (all done/cancelled)
      cron/jobs.json          ← 7 cron jobs
      workspace-elon/skills/  ← 4 skills
      workspace-gary/skills/  ← 1 skill
      workspace-warren/skills/← 1 skill
      workspace/skills/       ← 2 skills (gedeeld)

VM (10.0.1.2, waar Next.js draait)
  /home/agent/workspace/
    .openclaw/
      agent-tasks.json        ← ? (API geeft leeg terug)
      workspace-*/skills/     ← ? (API geeft 500)
```

Of de host-mappen via virtiofs gemount zijn op de VM op `/home/agent/workspace/` is de cruciale vraag. Als dat niet klopt, werken beide API-endpoints niet.

---

## 6. Strategische opties — ter bespreking

### Optie A: Padmapping repareren (structurele fix)
Zorg dat de Next.js-server op de VM de bestanden kan bereiken.
- Controleer of virtiofs-mount correct is geconfigureerd
- Of: stel `AGENT_TASKS_FILE` en `OPENCLAW_STATE` env-vars in op de juiste paden
- **Voordeel:** Duurzame oplossing, geen codewijzigingen nodig
- **Risico:** Vereist inzicht in de VM-configuratie

### Optie B: Frontend-filter aanpassen (tijdelijke zichtbaarheid)
Toon ook `done`/`cancelled` taken standaard, bijv. de laatste 7 dagen.
- **Voordeel:** Geeft meteen historisch overzicht
- **Nadeel:** Pakt het onderliggende API-probleem niet aan; board wordt rommelig

### Optie C: Skills endpoint hardener (fix 500)
De `execSync` voor SQLite record-telling is de riskante call. Wrap de hele `GET()` handler in try/catch, of verwijder de record-count feature tijdelijk.
- **Voordeel:** Skills tonen weer in organogram
- **Risico:** Verbergt de echte oorzaak van de 500

### Optie D: Taken zichtbaar houden via archief-sectie
In het Lab Decision Board een permanente "Archief" sectie toevoegen met de laatste N voltooide taken (bijv. laatste 30 dagen), ongeacht filter-status.
- **Voordeel:** Continuïteit van overzicht ook als alles 'done' is
- **Nadeel:** Meer frontend-werk

### Mijn aanbeveling (ter bespreking)

1. **Eerst:** Controleer of de padmapping correct is (kan via shell op de VM: `ls /home/agent/workspace/.openclaw/agent-tasks.json`)
2. **Dan:** Fix de skills 500 door de `GET()` handler te wrappen in try/catch (kleine, veilige wijziging)
3. **Daarna:** Overweeg optie D voor structureel overzicht — zodat voltooide taken niet "verdwijnen" als een agent alle taken afmaakt

---

## Bijlage: Gebruikte bestandspaden

```
# Dashboard (frontend + API)
dashboard/src/app/lab/page.tsx
dashboard/src/app/team/page.tsx
dashboard/src/app/api/agent-tasks/route.ts
dashboard/src/app/api/skills/route.ts
dashboard/src/app/api/sessions/route.ts
dashboard/src/app/api/agents/route.ts
dashboard/src/lib/gateway.ts

# Data (op de host)
.openclaw/agent-tasks.json
.openclaw/cron/jobs.json
.openclaw/openclaw.json
.openclaw/workspace/tasks.json          (Muddy's interne taken)
.openclaw/workspace/skills/             (gedeelde skills)
.openclaw/workspace-elon/skills/
.openclaw/workspace-gary/skills/
.openclaw/workspace-warren/skills/
.openclaw/agents/*/sessions/            (sessie-opslag per agent)
```
