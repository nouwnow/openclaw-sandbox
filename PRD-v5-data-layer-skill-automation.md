# PRD v5 — Data Layer, Script-Driven Skills & Lab Approval Flow

**Status:** ✅ Actief — Fase 1–4 volledig geïmplementeerd
**Datum:** 2026-03-25 → bijgewerkt 2026-03-26
**Scope:** Uniforme data-ingestielaag, script-driven skills, subagent skill manager, Lab Decision Board approval flow

---

## 1. Probleem

### 1.1 Hoe het werkte (en waarom het niet schaalbaar was)

```
Michiel (Discord)
    → Muddy delegeert naar Elon
        → Elon roept vikbooking-data skill aan
            → LLM haalt data op (alle details, API-responses, ruwe data)
            → LLM verwerkt data tot overzicht
            → Warren interpreteert overzicht als CRO
```

**Knelpunten:**
| Probleem | Impact |
|---|---|
| Elon's LLM ziet alle ruwe API-data | Hoge tokenkosten (inputTokens exploderen) |
| Data wordt niet opgeslagen | Elke vraag = opnieuw ophalen = opnieuw hoge kosten |
| Vergelijkingen over tijd zijn onmogelijk | Snapshots ontbreken → geen tijdreeks |
| Skills zijn ad-hoc, niet uniform | Moeilijk te onderhouden, niet inzichtelijk voor Michiel |
| Geen controle over skill-wijzigingen | Elon past scripts aan zonder validatie |
| Schalen naar meerdere databronnen is omslachtig | Matomo, GSC, DataforSEO zijn niet geïntegreerd |

---

## 2. Oplossing — Overzicht

```
┌──────────────────────────────────────────────────────────────────────┐
│  SKILL PAKKET (zelfstandig, door Elon's subagent beheerd)            │
│                                                                      │
│  skills/vikbooking-bookings/                                         │
│  ├── skill.json   ← beschrijving + cron config + on-demand flag      │
│  └── fetch_bookings.py  ← het script                                 │
│                                                                      │
│  skill.json stuurt:                                                  │
│  ├── cron job  (wekelijks automatisch → snapshot naar SQLite)        │
│  └── on-demand (Elon triggert direct wanneer actuele data nodig is)  │
└──────────────────────────────────────────────────────────────────────┘

Elke run slaat snapshot op in SQLite met timestamp:
  vikbooking.db → tabel: snapshots
  → na verloop van tijd: tijdreeks voor periodesvergelijking

Wijziging aan skill, script of cron:
  Elon → Lab Decision Board verzoek → Michiel valideert → subagent voert uit
```

### 2.1 Snapshot model (tijdreeks)

De brain bridge endpoints geven altijd de **laatste 30 dagen**. Door elke run als snapshot op te slaan met timestamp, ontstaat automatisch een tijdreeks:

```
fetched_at          | bookings | revenue  | avg_nights | conv_rate | ...
2026-03-25 08:00    |    42    | €8.400   |    3.2     |   4.8%    |
2026-04-01 08:00    |    38    | €7.200   |    3.0     |   4.1%    |
2026-04-08 08:00    |    51    | €10.100  |    3.5     |   5.3%    |
```

Warren kan dan vergelijken:
```sql
-- Laatste 2 periodes
SELECT * FROM snapshots ORDER BY fetched_at DESC LIMIT 2;

-- Trend over 3 maanden
SELECT fetched_at, bookings, revenue FROM snapshots
WHERE fetched_at >= date('now', '-90 days')
ORDER BY fetched_at;
```

---

## 3. Doelstellingen

1. **Tokenreductie** — scripts vervangen LLM-gedreven data-ophaling (~85-90% minder tokens per query)
2. **Historische vergelijkingen** — snapshot model accumuleert tijdreeks automatisch
3. **Multi-source** — uniforme architectuur voor VikBooking, Matomo, GSC, DataforSEO en toekomstige bronnen
4. **Skill-autonomie** — Elon coördineert, subagent beheert skills end-to-end (script + cron + on-demand)
5. **Human-in-the-loop** — elke wijziging aan skill/script/cron passeert Lab Decision Board
6. **Inzichtelijkheid** — alle skills zijn leesbare bestanden die Michiel zelf kan inzien en aanpassen

---

## 4. Architectuur

### 4.1 Mappenstructuur

```
~/openclaw-workspace/
└── .openclaw/
    └── workspace/
        ├── AGENTS.md
        ├── skills/                           ← alle skill pakketten
        │   ├── skill-building/               ← meta-skill: playbook voor Elon ✅
        │   │   └── skill.json
        │   ├── vikbooking-bookings/          ← Fase 1 ✅
        │   │   ├── skill.json
        │   │   └── fetch_bookings.py
        │   ├── matomo-traffic/               ← Fase 4 ✅
        │   │   ├── skill.json
        │   │   └── fetch_matomo.py
        │   ├── gsc-rankings/                 ← Gepland (MCP)
        │   │   ├── skill.json
        │   │   └── fetch_gsc.py
        │   └── dataforseo-volume/            ← Gepland (REST API)
        │       ├── skill.json
        │       └── fetch_dataforseo.py
        └── data/                             ← SQLite databases (per bron)
            ├── vikbooking.db                 ✅ Actief
            ├── matomo.db                     ✅ Actief
            ├── gsc.db                        ← Gepland
            └── dataforseo.db                 ← Gepland

workspace-elon/
└── skills/
    ├── skill-building/SKILL.md               ← Playbook ✅
    ├── vikbooking-bookings/SKILL.md          ✅
    └── matomo-traffic/SKILL.md               ✅
```

### 4.2 Skill pakket — `skill.json` (volledig schema)

```json
{
  "id": "vikbooking-bookings",
  "name": "VikBooking Boekingen",
  "description": "Haalt boekingssnapshots op via de brain bridge API en slaat ze op als tijdreeks in SQLite",
  "version": "1.0.0",
  "owner": "elon",
  "source": "vikbooking",
  "script": "fetch_bookings.py",
  "database": "data/vikbooking.db",
  "table": "snapshots",

  "cron": {
    "name": "vikbooking-weekly-sync",
    "schedule": { "kind": "cron", "expr": "0 8 * * 1", "tz": "Europe/Amsterdam" },
    "sessionTarget": "isolated",
    "agentId": "elon",
    "payload": {
      "kind": "agentTurn",
      "message": "Voer de vikbooking-bookings skill uit: ...",
      "lightContext": true
    },
    "description": "Wekelijks elke maandag 08:00 een nieuwe snapshot ophalen"
  },

  "on_demand": { "enabled": true },
  "credentials": ["WP_API_USER", "WP_API_PASSWORD", "WP_STAGING_URL"],
  "endpoint": "/wp-json/brain/v1/vikbooking/summary",
  "queries": {
    "latest":           "SELECT * FROM snapshots ORDER BY fetched_at DESC LIMIT 1",
    "compare_last_two": "SELECT * FROM snapshots ORDER BY fetched_at DESC LIMIT 2",
    "trend_90d":        "SELECT fetched_at, bookings, revenue FROM snapshots WHERE fetched_at >= date('now', '-90 days') ORDER BY fetched_at"
  },
  "managed_by": "elon-skill-manager",
  "approved_by": "michiel"
}
```

### 4.3 WordPress Brain Bridge (`open-brain-analytics-bridge`)

De bridge is een WordPress plugin op de staging site die REST API endpoints blootstelt. Agents authenticeren met Basic Auth (`WP_API_USER:WP_API_PASSWORD`).

> **Let op:** `web_fetch` werkt niet voor logiesopdreef.nl. Scripts gebruiken altijd `curl` via bash of Python's `urllib`.

**Beschikbare endpoints:**

| Endpoint | Data |
|---|---|
| `/wp-json/brain/v1/vikbooking/summary` | Boekingen, omzet, conv_rate, top_referrers (30d) |
| `/wp-json/brain/v1/matomo/summary` | Site-wide analytics: visits, bounce, funnel, devices, geo |
| `/wp-json/brain/v1/matomo/diagnostic` | Alle Matomo tabellen + kolommen + sample data |
| `/wp-json/brain/v1/performance/{post_id}` | Gecombineerde Matomo + VikBooking data per pagina |
| `/wp-json/brain/v1/diagnostic/tables` | VikBooking + Matomo tabelstructuur |

---

## 5. Rollen en verantwoordelijkheden

### 5.1 Elon (CTO) — coördinator

Elon is verantwoordelijk voor:
- Beoordelen welke skill/script/cron aangemaakt of aangepast moet worden
- Lab Decision Board verzoek opstellen met alle details
- Na validatie: subagent spawnen met volledige instructie
- Resultaat controleren na uitvoer door subagent
- Warren en andere agents informeren dat data beschikbaar is

Elon triggert de subagent **nooit zonder Lab Decision Board goedkeuring**.

### 5.2 Skill-Building Playbook

Elon beschikt over een meta-skill (`skill-building`) met:
- Stappenplan voor elke nieuwe skill (ongeacht databron-type)
- Lab Decision Board request template
- Templates voor `fetch_*.py`, `skill.json`, `SKILL.md`
- Overzicht van databron-types: WordPress bridge, REST API, MCP
- Cron-spreidingsschema (ma=VikBooking, wo=Matomo, vr=GSC gepland, di=DataforSEO gepland)

Locatie: `workspace-elon/skills/skill-building/SKILL.md`

### 5.3 On-demand vs cron

| Trigger | Wie | Wanneer |
|---|---|---|
| **Cron** | OpenClaw scheduler (automatisch) | Wekelijks op vaste dag/tijd |
| **On-demand** | Elon of zijn subagent | Als Michiel actuele data vraagt die verser is dan de laatste snapshot |

### 5.4 Warren (CRO) — data consument

Warren queries direct de SQLite database. Elon geeft Warren bij een data-vraag:
1. Het pad naar de database
2. De relevante query uit `skill.json → queries`
3. Nooit de ruwe API-response

---

## 6. Lab Decision Board — Approval Flow

### 6.1 Wanneer een verzoek aanmaken

| Situatie | Type verzoek |
|---|---|
| Nieuwe skill nodig | `skill_create` |
| Script aanpassen | `script_update` |
| Cron schema wijzigen | `cron_update` |
| Nieuwe databron toevoegen | `source_add` |
| WordPress bridge plugin aanpassen | `plugin_update` |
| Skill verwijderen | `skill_delete` |
| Andere agent heeft data-behoefte (Gary, Warren) | Via Elon → `skill_create` of `script_update` |

### 6.2 Verzoek formaat

```
🔬 LAB DECISION REQUEST
────────────────────────────────────────────
Type:        [NIEUWE SKILL | SKILL WIJZIGING | PLUGIN UPDATE | CRON AANPASSING]
Skill naam:  <skill-id>
Agent:       <agent die de skill krijgt>
Prioriteit:  [hoog | medium | laag]

## Wat
<Eén zin: wat ga ik bouwen of aanpassen>

## Waarom
<Welk agent-probleem lost dit op? Welke data ontbreekt nu?>

## Databron
Type:      [WordPress Bridge | REST API | MCP]
Endpoint:  <URL of toolnaam>
Data:      <lijst van velden die opgeslagen worden>
Kosten:    <API kosten per call, of 'gratis'>

## Implementatieplan
1. <stap 1>
2. <stap 2>

## SQLite schema
Tabel:     <tabelnaam>
Kolommen:  <kolom: type — beschrijving>

## Cron
Frequentie: <bijv. wekelijks woensdag 08:00>

────────────────────────────────────────────
Reageer met 🚀 goedkeuren | ❌ afwijzen | 💬 feedback
```

### 6.3 Reacties van Michiel

| Reactie | Actie door Elon |
|---|---|
| 🚀 | Subagent spawnen met volledige instructie |
| ❌ | Verzoek annuleren, aanvragende agent informeren |
| 💬 tekst | Verzoek herzien, nieuwe versie sturen |

---

## 7. Multi-Source Architectuur

### 7.1 Ondersteunde bronnen

| Bron | Status | Methode | Database | Cron | Snapshot inhoud |
|---|---|---|---|---|---|
| **VikBooking** | ✅ Actief | WordPress bridge | vikbooking.db | Ma 08:00 | bookings, revenue, avg_nights, conv_rate, top_referrers |
| **Matomo** | ✅ Actief | WordPress bridge | matomo.db | Wo 08:00 | visits, bounce, funnel (5 goals), devices, geo, top_pages, ai_assistants |
| **Google Search Console** | 🔜 Gepland | MCP | gsc.db | Vr 08:00 | clicks, impressions, avg_position, top_queries |
| **DataforSEO** | 🔜 Gepland | REST API | dataforseo.db | Di 08:00 | keyword volumes, difficulty, trend |

### 7.2 Matomo snapshot — extra rijke data

De Matomo skill slaat aanzienlijk meer data op dan VikBooking vanwege de rijke Matomo tabelstructuur:

**Acquisition:**
- Traffic sources (direct/search/social/website/campaign)
- Search engines + zoekwoorden
- Social networks
- AI assistants (via `ai_agent_name` kolom in `matomo_log_visit`)

**Visitors:**
- Devices (desktop/mobiel/tablet)
- Browsers, OS
- Landen + steden

**Behaviour:**
- Top 15 pagina's (pageviews + unieke bezoeken)
- Entry pages + Exit pages
- Outlinks

**Booking funnel (5 goals):**
| Stap | Goal | Trigger |
|---|---|---|
| 1 Interesse | Goal 1 | URL bevat `checkin=` |
| 2a Intentie NL | Goal 3 | URL exact `/searchform/` |
| 2b Intentie EN | Goal 4 | URL exact `/en/searchform/` |
| 3 Actie | Goal 2 | URL bevat `task=oconfirm` |
| 4 Succes | Goal 5 | URL bevat `/reservering/` |

### 7.3 Cross-source queries (Warren's kracht)

```sql
-- Conversieratio bezoekers → boekingen per snapshot-week
SELECT
    v.fetched_at,
    m.total_visits,
    v.bookings,
    ROUND(CAST(v.bookings AS FLOAT) / m.total_visits * 100, 2) as conv_pct
FROM vikbooking.snapshots v
JOIN matomo.snapshots m
  ON strftime('%Y-%W', v.fetched_at) = strftime('%Y-%W', m.fetched_at)
ORDER BY v.fetched_at DESC;
```

---

## 8. Token Impact (gerealiseerd)

| Situatie | inputTokens per data-vraag |
|---|---|
| Oude aanpak (LLM haalt data op) | ~8.000–15.000 |
| Nieuwe aanpak (script → SQLite → query resultaat) | ~800–1.500 |
| **Reductie** | **~85-90%** |

---

## 9. Implementatiefasen

### ✅ Fase 1 — VikBooking skill

- [x] `skills/vikbooking-bookings/skill.json` aangemaakt
- [x] `skills/vikbooking-bookings/fetch_bookings.py` aangemaakt en getest
- [x] Cron job `vikbooking-weekly-sync` geregistreerd (ma 08:00)
- [x] On-demand trigger ingeschakeld
- [x] `data/vikbooking.db` — meerdere snapshots aanwezig
- [x] `workspace-elon/skills/vikbooking-bookings/SKILL.md` aangemaakt
- [x] Via Lab Decision Board goedgekeurd door Michiel

**Gerealiseerde data:** 27+ boekingen, €3.143,84 omzet, 10.09% conv_rate (eerste test)

---

### ✅ Fase 2 — Subagent visualisatie in Office dashboard

**Geïmplementeerd:** mini-clone animatie in pixel art Office tab

| Moment | Animatie |
|---|---|
| Subagent gespawnd | Mini-agent (50% schaal) "springt" uit parent |
| Subagent actief | Mini loopt naar werkbank, toont 🔧 + busy-animatie |
| Parent wacht | Parent krijgt pulserend randje (gesplitst-state) |
| Subagent klaar | Mini loopt terug, merge-flash, verdwijnt |

- [x] `SubAgent` interface + `WORKBENCH` constant
- [x] `drawWorkbench()`, `drawMiniSprite()`, `drawParentFlash()` functies
- [x] SSE handler detecteert onbekende agent IDs als subagents
- [x] Gele "subagent" legenda in Office tab

---

### ✅ Fase 3 — Lab Decision Board dashboard + Skills overview

**Lab Decision Board (`🧪 Lab` tab):**
- [x] `decisions.json` als storage backend
- [x] REST API (`/api/decisions`) met GET/POST/PATCH/DELETE
- [x] Status flow: `needs_decision` → `approved/rejected/feedback_given`
- [x] Gateway notificatie naar Muddy bij statuswijziging
- [x] Inline approve/reject/feedback in dashboard

**Skills overview (Team tab):**
- [x] `/api/skills` endpoint leest `skill.json` + SQLite record counts + `jobs.json` cron status
- [x] Status badges: 🗄 records, 🕐 last run, ⏰ cron status, ⚡ on-demand

---

### ✅ Fase 4 — Matomo data layer

**WordPress bridge uitgebreid:**
- [x] Nieuw endpoint `/brain/v1/matomo/summary` — volledige site-wide analytics
- [x] Nieuw endpoint `/brain/v1/matomo/diagnostic` — alle Matomo tabellen + sample data
- [x] Getest op live staging site: 274 bezoeken, 51.09% bounce, booking funnel actief

**Skill gebouwd:**
- [x] `skills/matomo-traffic/fetch_matomo.py` — haalt 15 datapunten op in één API call
- [x] `skills/matomo-traffic/skill.json` — cron wo 08:00, on-demand ingeschakeld
- [x] `data/matomo.db` — eerste snapshot aanwezig
- [x] `workspace-elon/skills/matomo-traffic/SKILL.md` — volledige documentatie
- [x] Cron job `matomo-weekly-sync` geregistreerd (wo 08:00)

---

### ✅ Fase 5 — Skill-Building Playbook

- [x] `workspace-elon/skills/skill-building/SKILL.md` — meta-skill met volledig playbook
- [x] `workspace/skills/skill-building/skill.json` — referentie metadata
- [x] Dekt 3 databron-types: WordPress bridge, REST API, MCP
- [x] Lab Decision Board request template ingebouwd
- [x] Cron-spreidingsschema voor toekomstige skills
- [x] Schema-migratiepatroon voor `ALTER TABLE`

---

### 🔜 Fase 6 — Google Search Console (GSC via MCP)

- [ ] Lab Decision Request voor `gsc-search-performance`
- [ ] MCP tool configureren voor GSC OAuth
- [ ] `fetch_gsc.py` aanmaken (agent-turn schrijft naar SQLite)
- [ ] Cron vr 08:00

---

### 🔜 Fase 7 — DataforSEO (REST API)

- [ ] `DATAFORSEO_LOGIN` + `DATAFORSEO_PASSWORD` toevoegen aan `.env`
- [ ] Lab Decision Request voor `dataforseo-rankings`
- [ ] API kosten verifiëren + vermelden in request
- [ ] Cron di 08:00

---

## 10. Risico's en mitigaties

| Risico | Impact | Mitigatie |
|---|---|---|
| Script faalt (API down, auth verlopen) | Geen nieuwe snapshots | Elon rapporteert fout in Discord, stelt script-update LDR op |
| Subagent bouwt onveilig script | Veiligheidsrisico | Michiel leest script vóór 🚀 — altijd zichtbaar in Lab Request |
| Cron job dubbel geregistreerd na update | Dubbele snapshots | Subagent verwijdert oude cron-entry vóór aanmaken nieuwe |
| SQLite concurrent writes | DB corrupt | WAL mode activeren in scripts; per bron aparte .db file |
| Bridge plugin endpoint ontbreekt | Skill kan niet ophalen | Diagnostic endpoint controleert tabellen; plugin updaten via staging |

---

## 11. Niet in scope

- ❌ Real-time streaming — snapshot + TTL is voldoende
- ❌ Cloud database — SQLite in de VM is eenvoudig, veilig, geen externe afhankelijkheid
- ❌ Automatische skill-activatie zonder Lab Board goedkeuring — altijd menselijke validatie
- ❌ Subagent die zelfstandig scripts aanpast zonder Elon's Lab Request

---

*Aangemaakt: 2026-03-25 | Bijgewerkt: 2026-03-26*
*Bouwt voort op PRD v2 (memory), v3 (model tiers), v4 (browser/API tools)*
*Volgende stap: Fase 6 (GSC via MCP) via Lab Decision Board aanvragen*
