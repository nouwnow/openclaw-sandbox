# PRD v6 — Complete Guide: Skills, Data Layer & Multi-Agent Teamwork

**Status:** ✅ Referentiedocument — actueel per 2026-03-29
**Scope:** Volledige beschrijving van de skills-architectuur, data-ingestielaag, workspace-opzet, agent-rollen en onderlinge samenwerking
**Doelgroep:** Nieuwe gebruikers die een vergelijkbare OpenClaw multi-agent stack willen opzetten

> **Leeswijzer:** Nieuw? Begin bij §1 (Waarom) en §2 (Architectuuroverzicht). Wil je direct implementeren? Ga naar §5 (Stap-voor-stap). Zoek je de spelregels voor het team? Zie §7 (Lab Decision Board) en §8 (Communicatie).

---

## Inhoudsopgave

1. [Waarom dit systeem bestaat](#1-waarom-dit-systeem-bestaat)
2. [Architectuuroverzicht — het grote plaatje](#2-architectuuroverzicht--het-grote-plaatje)
3. [Workspace-structuur en waarom zo](#3-workspace-structuur-en-waarom-zo)
4. [Agent-rollen en verantwoordelijkheden](#4-agent-rollen-en-verantwoordelijkheden)
5. [Skills — de bouwstenen](#5-skills--de-bouwstenen)
6. [Concrete voorbeelden: VikBooking & Matomo](#6-concrete-voorbeelden-vikbooking--matomo)
7. [Lab Decision Board — goedkeuringsflow](#7-lab-decision-board--goedkeuringsflow)
8. [Communicatie tussen agents](#8-communicatie-tussen-agents)
9. [Wat te vermijden — bekende valkuilen](#9-wat-te-vermijden--bekende-valkuilen)
10. [Checklist: nieuwe skill toevoegen](#10-checklist-nieuwe-skill-toevoegen)
11. [Implementatie voor nieuwe gebruikers](#11-implementatie-voor-nieuwe-gebruikers)

---

## 1. Waarom dit systeem bestaat

### 1.1 Het oorspronkelijke probleem

In een eenvoudige OpenClaw setup haalt een agent data rechtstreeks op via een API-call, verwerkt die data in de LLM-context en rapporteert terug. Dat werkt prima voor één agent met incidentele vragen. Het schaalt niet.

```
❌ OUDE AANPAK (niet schaalbaar)

Michiel vraagt iets aan Muddy
  → Muddy delegeert aan Warren
    → Warren roept live API aan
      → LLM verwerkt alle ruwe API-data (8.000–15.000 tokens!)
        → Warren antwoordt
          → Volgende week: zelfde API-call, zelfde tokens, geen historiek
```

**Concrete problemen:**

| Probleem | Gevolg |
|---|---|
| Elke vraag = live API-call | Hoge en herhaalde tokenkosten |
| Ruwe API-data in LLM-context | Inputtokens exploderen |
| Data wordt niet opgeslagen | Geen tijdreeks, geen vergelijkingen |
| Skills zijn ad-hoc gebouwd | Moeilijk te onderhouden |
| Geen validatie bij wijzigingen | Elon past scripts aan zonder check |

### 1.2 De oplossing: script-driven skills met SQLite snapshot model

```
✅ NIEUWE AANPAK (schaalbaar)

Wekelijkse cron (automatisch, geen LLM betrokken)
  → Python script haalt data op via API
    → Data opgeslagen als rij in SQLite (timestamp + velden)
      → Volgende week: nieuwe rij → tijdreeks ontstaat vanzelf

Agent vraagt data op
  → Leest één rij uit SQLite (200–500 tokens)
    → Analyseert, concludeert, rapporteert
```

**Resultaat:**
- **85–90% minder tokens** per data-vraag
- **Historische vergelijkingen** mogelijk (week-over-week, 90-dagentrendlijn)
- **Eén definitie** van de data — scripts zijn leesbaar voor Michiel
- **Validatie verplicht** — elke wijziging passeert het Lab Decision Board

---

## 2. Architectuuroverzicht — het grote plaatje

```
┌─────────────────────────────────────────────────────────────────────────┐
│  MICHIEL (CEO / eigenaar)                                               │
│  Discord  →  Muddy (COO)  →  delegeert aan team                        │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │
          ┌───────────────────────┼───────────────────────┐
          │                       │                       │
     ┌────▼─────┐           ┌─────▼────┐           ┌─────▼────┐
     │  ELON    │           │  GARY    │           │ WARREN   │
     │  (CTO)   │           │  (CMO)   │           │  (CRO)   │
     └────┬─────┘           └──────────┘           └────┬─────┘
          │                                             │
          │ schrijft & beheert                          │ leest & analyseert
          ▼                                             ▼
┌─────────────────────┐                    ┌────────────────────────┐
│  SKILLS             │                    │  ANALYTICS SCRIPTS     │
│  fetch_bookings.py  │──── SQLite ────────│  query_revenue.py      │
│  fetch_matomo.py    │  workspace-elon/   │  python3 ... rooms     │
│  (cron: ma+wo 08:00)│  data/*.db         │  python3 ... summary   │
└─────────────────────┘                    └────────────────────────┘
          │
          │ haalt data op van
          ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  WORDPRESS BRAIN BRIDGE (plugin op logiesopdreef.nl)                   │
│  /wp-json/brain/v1/vikbooking/summary        ← site-wide totalen       │
│  /wp-json/brain/v1/vikbooking/rooms/summary  ← per accommodatietype    │
│  /wp-json/brain/v1/matomo/summary            ← website analytics       │
│  /wp-json/brain/v1/vikbooking/diagnostic     ← tabelstructuur          │
└─────────────────────────────────────────────────────────────────────────┘
          │
          │ bevraagt
          ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  WORDPRESS DATABASE (khj_ prefix)                                       │
│  khj_vikbooking_orders          ← boekingen                            │
│  khj_vikbooking_rooms           ← accommodatietypes                    │
│  khj_vikbooking_ordersrooms     ← koppeling order ↔ kamer              │
│  khj_vikbooking_tracking_infos  ← bezoekersdata + conversies           │
│  khj_matomo_log_visit           ← websitebezoeken                      │
│  khj_matomo_log_conversion      ← goal conversions                     │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Workspace-structuur en waarom zo

### 3.1 De mappenstructuur

```
~/openclaw-workspace/
└── .openclaw/
    ├── workspace/                        ← GEDEELDE ruimte (alle agents lezen hier)
    │   ├── AGENTS.md                     ← Muddy's identiteit en spelregels
    │   ├── c-suite-chat.jsonl            ← Async communicatiekanaal team
    │   ├── standups.json                 ← Vergaderarchief
    │   ├── data/                         ← Gedeelde databases (legacy)
    │   │   ├── matomo.db
    │   │   └── vikbooking.db
    │   └── skills/                       ← Skill-definities (leesbaar voor iedereen)
    │       ├── analytics-query/
    │       ├── vikbooking-bookings/
    │       └── warren-revenue-analytics/
    │
    ├── workspace-elon/                   ← Elon's PRIVÉ werkruimte
    │   ├── AGENTS.md                     ← Elon's identiteit en bevoegdheden
    │   ├── data/                         ← PRIMAIRE databases (Elon schrijft hier)
    │   │   ├── vikbooking.db             ← snapshots + room_snapshots tabellen
    │   │   └── matomo.db                 ← matomo snapshots tabel
    │   └── skills/                       ← Elon's skill-scripts
    │       ├── skill-building/           ← Meta-skill: playbook voor nieuwe skills
    │       │   ├── skill.json
    │       │   └── SKILL.md
    │       ├── vikbooking-bookings/      ← VikBooking data-skill
    │       │   ├── skill.json
    │       │   ├── fetch_bookings.py
    │       │   └── SKILL.md
    │       └── matomo-traffic/           ← Matomo data-skill
    │           ├── skill.json
    │           ├── fetch_matomo.py
    │           └── SKILL.md
    │
    ├── workspace-warren/                 ← Warren's PRIVÉ werkruimte
    │   ├── AGENTS.md                     ← Warren's identiteit en bevoegdheden
    │   └── skills/
    │       └── warren-revenue-analytics/ ← Warren's query-script
    │           ├── skill.json
    │           └── query_revenue.py
    │
    ├── workspace-gary/                   ← Gary's PRIVÉ werkruimte
    │   └── AGENTS.md                     ← Gary's identiteit en bevoegdheden
    │
    └── workspace-memory-agent/           ← Memory agent werkruimte
        └── AGENTS.md
```

### 3.2 Waarom aparte workspaces per agent?

**Kernprincipe: eigen verantwoordelijkheid, minimale overlap**

| Workspace | Eigenaar | Schrijftoegang | Reden |
|---|---|---|---|
| `workspace-elon/data/` | Elon | Alleen Elon | Scripts schrijven hier, niemand anders |
| `workspace-warren/skills/` | Warren | Alleen Warren | Query-scripts voor data-analyse |
| `workspace/` | Iedereen | Lezen vrij | Gedeelde communicatie en skill-definities |
| `workspace-gary/` | Gary | Alleen Gary | Content en campagne-output |

**Voordelen van deze scheiding:**

1. **Geen conflicten** — twee agents schrijven nooit naar hetzelfde bestand tegelijk
2. **Duidelijke eigenaarschap** — als een database corrupt is, weet je wie verantwoordelijk is
3. **Auditeerbaar** — Michiel kan per workspace zien wat welke agent heeft gedaan
4. **Veilig schalen** — een nieuwe agent krijgt een eigen workspace zonder bestaande agents te raken

### 3.3 De gouden regel voor database-paden

> **Database-paden zijn altijd absoluut. Nooit relatief aan de scriptlocatie.**

```python
# ❌ FOUT — breekt stiekem als het script verplaatst wordt:
DB_PATH = Path(__file__).parent.parent / "data" / "vikbooking.db"

# ✅ GOED — werkt altijd, ongeacht waar het script staat:
DB_PATH = Path("/home/agent/workspace/.openclaw/workspace-elon/data/vikbooking.db")
```

**Waarom dit zo belangrijk is:** Muddy of Michiel kan vragen om een script te verplaatsen. Een relatief pad resolveert dan naar een andere locatie. Er is geen foutmelding — het script maakt stil een nieuwe, lege database aan. De volgende cron-run schrijft naar het verkeerde bestand. Warren leest ondertussen van de oude database. Dit soort bugs zijn moeilijk te vinden.

---

## 4. Agent-rollen en verantwoordelijkheden

### 4.1 Het team

```
┌─────────────────────────────────────────────────────────────────┐
│                    MICHIEL (CEO)                                │
│              Eigenaar · Goedkeuring · Visie                     │
└──────────────────────────┬──────────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────────┐
│                    MUDDY (COO)                                  │
│         Orkestratie · Delegatie · Discord-aanspreekpunt         │
└──────┬───────────────────┬──────────────────────────┬───────────┘
       │                   │                          │
┌──────▼───────┐   ┌───────▼──────┐   ┌──────────────▼────────┐
│  ELON (CTO)  │   │  GARY (CMO)  │   │   WARREN (CRO)        │
│ Techniek     │   │ Content      │   │   Revenue & groei      │
│ Scripts      │   │ Marketing    │   │   Data-analyse         │
│ Skills       │   │ Brand        │   │   Rapportages          │
│ Databases    │   │ Campagnes    │   │                        │
└──────────────┘   └──────────────┘   └───────────────────────┘
```

### 4.2 Rollen in het data-ecosysteem

| Agent | Rol t.a.v. data | Wat ze doen | Wat ze NIET doen |
|---|---|---|---|
| **Elon** | **Producent** | Scripts bouwen, cron beheren, databases aanmaken | Data analyseren, rapporten schrijven |
| **Warren** | **Consument** | SQLite queries draaien, analyses maken, trends rapporteren | Scripts schrijven, API's aanroepen |
| **Gary** | **Beperkt consument** | Channel-performance lezen (geaggregeerd) voor campagne-beslissingen | Klantdata inzien, boekingen aanpassen |
| **Muddy** | **Orkestratie** | Elon spawnen voor data-verversing, Warren voor analyse | Zelf scripts draaien, databases schrijven |
| **Michiel** | **Eigenaar** | Lab Decision Board goedkeuren of afwijzen | — |

### 4.3 Dataflow per use case

**Scenario: Michiel vraagt wekelijkse revenue-rapportage**

```
1. Michiel in Discord: "@Muddy geef me de revenue van deze week"
        │
        ▼
2. Muddy beoordeelt: is de data vers? (check: laatste snapshot ouder dan 7 dagen?)
   ├── Vers → stap 4
   └── Verouderd → stap 3
        │
        ▼
3. Muddy spawnt Elon: "Draai de vikbooking-bookings skill"
   Elon voert fetch_bookings.py uit → nieuwe snapshot in vikbooking.db
        │
        ▼
4. Muddy spawnt Warren: "Analyseer de revenue voor Michiel"
   Warren draait: python3 query_revenue.py rooms + summary
        │
        ▼
5. Warren rapporteert aan Muddy (completion bericht)
        │
        ▼
6. Muddy presenteert Warren's analyse aan Michiel in Discord
```

**Scenario: Michiel wil een nieuwe databron toevoegen**

```
1. Michiel: "Kunnen we Google Search Console data ook ophalen?"
        │
        ▼
2. Muddy delegeert aan Elon: analyseer haalbaarheid
        │
        ▼
3. Elon analyseert: databron-type, endpoint, velden, kosten
        │
        ▼
4. Elon maakt Lab Decision Board request aan
        │
        ▼
5. Michiel beoordeelt: 🚀 goedkeuren / ❌ afwijzen / 💬 feedback
        │
        ▼
6. Bij 🚀: Elon bouwt script + database + cron
        │
        ▼
7. Elon test handmatig → rapporteert succes aan Muddy → Muddy informeert Michiel
```

---

## 5. Skills — de bouwstenen

### 5.1 Wat is een skill?

Een skill is een **zelfstandig pakket** dat één databron ontsluit. Het bestaat altijd uit vier bestanden:

```
skills/mijn-skill/
├── skill.json        ← metadata, cron-config, queries, endpoints
├── fetch_data.py     ← het Python script dat data ophaalt en opslaat
└── SKILL.md          ← documentatie voor Elon en andere agents
```

### 5.2 Het `skill.json` schema

```json
{
  "id": "vikbooking-bookings",
  "name": "VikBooking Boekingen",
  "description": "Korte omschrijving van wat de skill doet",
  "version": "1.1.0",
  "owner": "elon",
  "source": "vikbooking",
  "script": "fetch_bookings.py",

  "database": "data/vikbooking.db",
  "tables": {
    "snapshots":      "Site-wide aggregaten per snapshot-run",
    "room_snapshots": "Per-kamer data per snapshot-run"
  },

  "cron": {
    "name": "vikbooking-weekly-sync",
    "schedule": {
      "kind": "cron",
      "expr": "0 8 * * 1",
      "tz": "Europe/Amsterdam"
    },
    "sessionTarget": "isolated",
    "agentId": "elon",
    "payload": {
      "kind": "agentTurn",
      "message": "Voer de vikbooking-bookings skill uit: ...",
      "lightContext": true
    },
    "description": "Wekelijks elke maandag 08:00"
  },

  "on_demand": {
    "enabled": true,
    "description": "Direct uitvoeren voor actuele data buiten cron om"
  },

  "credentials": ["WP_API_USER", "WP_API_PASSWORD", "WP_STAGING_URL"],

  "endpoints": [
    "/wp-json/brain/v1/vikbooking/summary",
    "/wp-json/brain/v1/vikbooking/rooms/summary"
  ],

  "queries": {
    "latest":           "SELECT * FROM snapshots ORDER BY fetched_at DESC LIMIT 1",
    "trend_90d":        "SELECT fetched_at, bookings, revenue FROM snapshots WHERE fetched_at >= date('now', '-90 days') ORDER BY fetched_at",
    "rooms_latest":     "SELECT * FROM room_snapshots WHERE fetched_at = (SELECT MAX(fetched_at) FROM room_snapshots) ORDER BY room_name",
    "rooms_occupancy":  "SELECT fetched_at, room_name, occupancy_rate FROM room_snapshots WHERE fetched_at >= date('now', '-90 days') ORDER BY fetched_at, room_name"
  },

  "managed_by": "elon-skill-manager",
  "created_at": "2026-03-25",
  "approved_by": "michiel",
  "changelog": [
    { "version": "1.0.0", "date": "2026-03-25", "description": "Initiële versie" },
    { "version": "1.1.0", "date": "2026-03-29", "description": "Room breakdown toegevoegd" }
  ]
}
```

### 5.3 Het Python script-patroon

Elk `fetch_*.py` script volgt exact hetzelfde patroon:

```python
#!/usr/bin/env python3
"""
skill:   naam-van-de-skill
versie:  1.0.0
beschr:  Wat het script doet.
run:     source /home/agent/workspace/.env && python3 fetch_data.py
"""

import sqlite3, os, json, sys, ssl, base64
from urllib.request import Request, urlopen
from datetime import datetime, timezone
from pathlib import Path

# ── Config ────────────────────────────────────────────────────────────────────
WP_URL  = os.environ.get("WP_STAGING_URL", "https://www.logiesopdreef.nl")
WP_USER = os.environ.get("WP_API_USER")
WP_PASS = os.environ.get("WP_API_PASSWORD")

if not WP_USER or not WP_PASS:
    print("[ERROR] Credentials ontbreken. source .env eerst.", file=sys.stderr)
    sys.exit(1)

# !! ALTIJD ABSOLUUT PAD — nooit Path(__file__).parent.parent !!
DB_PATH  = Path("/home/agent/workspace/.openclaw/workspace-elon/data/mijn-bron.db")
ENDPOINT = f"{WP_URL}/wp-json/brain/v1/mijn-endpoint"

# ── Database init ─────────────────────────────────────────────────────────────
def init_db(conn):
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("""
        CREATE TABLE IF NOT EXISTS snapshots (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            fetched_at  TEXT NOT NULL,
            veld_1      INTEGER,
            veld_2      REAL,
            json_veld   TEXT,     -- opgeslagen als JSON string
            raw_json    TEXT      -- volledige API response
        )
    """)
    conn.commit()

# ── API fetch ─────────────────────────────────────────────────────────────────
def fetch():
    credentials = base64.b64encode(f"{WP_USER}:{WP_PASS}".encode()).decode()
    req = Request(ENDPOINT, headers={"Authorization": f"Basic {credentials}"})
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode    = ssl.CERT_NONE
    with urlopen(req, timeout=15, context=ctx) as r:
        data = json.loads(r.read().decode("utf-8"))
    if not data.get("success"):
        raise ValueError(f"API fout: {data}")
    return data

# ── Opslaan ───────────────────────────────────────────────────────────────────
def store(conn, data):
    now = datetime.now(timezone.utc).isoformat()
    conn.execute("""
        INSERT INTO snapshots (fetched_at, veld_1, veld_2, json_veld, raw_json)
        VALUES (?, ?, ?, ?, ?)
    """, [
        now,
        data.get("veld_1"),
        data.get("veld_2"),
        json.dumps(data.get("array_veld", [])),
        json.dumps(data)
    ])
    conn.commit()
    return now

# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(str(DB_PATH))
    init_db(conn)

    print(f"[mijn-skill] Ophalen van {ENDPOINT} ...")
    data = fetch()
    ts   = store(conn, data)

    print(f"[mijn-skill] ✓ Opgeslagen: {ts}")
    count = conn.execute("SELECT COUNT(*) FROM snapshots").fetchone()[0]
    print(f"[mijn-skill]   Totaal: {count} snapshot(s)")
    conn.close()

if __name__ == "__main__":
    main()
```

### 5.4 Het snapshot-model — waarom tijdgestempelde rijen?

```
snapshots tabel na 4 weken:

fetched_at                | bookings | revenue   | conv_rate
2026-03-03 08:00:00+00:00 |    38    |  €7.200   |   4.1%
2026-03-10 08:00:00+00:00 |    42    |  €8.400   |   4.8%
2026-03-17 08:00:00+00:00 |    35    |  €6.100   |   3.9%
2026-03-24 08:00:00+00:00 |    51    | €10.100   |   5.3%
```

**Voordelen:**
- **Nooit overschrijven** — elke run voegt een rij toe, historiek blijft intact
- **Tijdreeksanalyse** — week-over-week vergelijking zonder extra API-calls
- **Goedkope queries** — Warren leest één rij, niet duizenden API-records
- **Auditeerbaar** — je kunt altijd terugkijken welke data wanneer was

**Queries die Warren gebruikt:**
```sql
-- Laatste snapshot
SELECT * FROM snapshots ORDER BY fetched_at DESC LIMIT 1;

-- Week-over-week vergelijking
SELECT * FROM snapshots ORDER BY fetched_at DESC LIMIT 2;

-- 90-daagse trend
SELECT fetched_at, bookings, revenue
FROM snapshots
WHERE fetched_at >= date('now', '-90 days')
ORDER BY fetched_at;

-- Cross-source: Matomo + VikBooking combineren
SELECT v.fetched_at, m.total_visits, v.bookings,
       ROUND(CAST(v.bookings AS FLOAT) / m.total_visits * 100, 2) as conv_pct
FROM vikbooking.snapshots v
JOIN matomo.snapshots m
  ON strftime('%Y-%W', v.fetched_at) = strftime('%Y-%W', m.fetched_at)
ORDER BY v.fetched_at DESC;
```

### 5.5 Cron-spreidingsschema

Alle cron jobs draaien op vaste, gespreide tijden om conflicten te voorkomen:

```
Maandag    08:00  →  vikbooking-bookings   (VikBooking summary + rooms)
Woensdag   08:00  →  matomo-traffic        (Matomo analytics)
Vrijdag    08:00  →  gsc-rankings          (Google Search Console — gepland)
Dinsdag    08:00  →  dataforseo-volume     (DataforSEO — gepland)
```

> **Spreiding is verplicht.** Twee skills tegelijk draaien op dezelfde agent-sessie kan conflicten geven in SQLite (WAL-mode mitigeert dit, maar spreiding is de eerste verdedigingslinie).

---

## 6. Concrete voorbeelden: VikBooking & Matomo

### 6.1 VikBooking — de volledige data-stack

**Doel:** Revenue, bezettingsgraad en conversie per accommodatietype bijhouden.

#### Databron: WordPress Brain Bridge

De WordPress plugin `open-brain-analytics-bridge` (versie 1.1.0) bevraagt de VikBooking MySQL-tabellen en biedt REST-endpoints:

```
GET /wp-json/brain/v1/vikbooking/summary
    → Site-wide: total_bookings, total_revenue, avg_nights,
                 total_visitors, converting_visitors, conversion_rate,
                 top_referrers (per kanaal: Google, Direct, B&B, Overig)

GET /wp-json/brain/v1/vikbooking/rooms/summary?days=30
    → Per kamer: room_name, bookings, revenue, occupancy_rate,
                 avg_nights, avg_lead_time_days,
                 conversion_by_source (per kanaal),
                 monthly_revenue_trend (6 maanden)

GET /wp-json/brain/v1/vikbooking/diagnostic
    → Alle vikbooking_* tabellen: kolomnamen, rij-aantallen, sample rows
```

**Authenticatie:** WordPress Application Password (Basic Auth)
**Prefix:** `khj_` (site-specifiek, stel in als `WP_DB_PREFIX` in .env)

#### VikBooking database-tabellen (relevant)

```
khj_vikbooking_rooms          (2 rijen)
├── id, name, units
└── Bevat: "Gastenverblijf Boven" (45m², 2p) en "Gastenverblijf Beneden" (13m², 1p)

khj_vikbooking_orders         (1008 rijen)
├── id, ts (unix), status, days, checkin, checkout
├── total, totpaid, custdata, channel
└── Status: 'confirmed' of 'paid'

khj_vikbooking_ordersrooms    (1513 rijen)
├── idorder, idroom, adults, children
└── Koppeltabel: één boeking kan meerdere kamers hebben

khj_vikbooking_tracking_infos (9648 rijen)
├── identifier, trackingdt, referrer, idorder
└── Bezoeker-tracking: idorder > 0 = converter
```

#### SQLite schema na Elon's script

```sql
-- Tabel 1: site-wide snapshot (één rij per week)
CREATE TABLE snapshots (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    fetched_at          TEXT NOT NULL,
    period_days         INTEGER,
    bookings            INTEGER,
    revenue             REAL,
    avg_nights          REAL,
    visitors            INTEGER,
    converting_visitors INTEGER,
    conv_rate           REAL,
    top_referrers       TEXT,   -- JSON array
    last_updated        TEXT,
    raw_json            TEXT    -- volledige API response
);

-- Tabel 2: per-kamer snapshot (één rij per kamer per week)
CREATE TABLE room_snapshots (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    fetched_at          TEXT NOT NULL,
    snapshot_id         INTEGER,        -- FK naar snapshots.id
    period_days         INTEGER,
    room_id             INTEGER,
    room_name           TEXT,
    room_units          INTEGER,
    bookings            INTEGER,
    revenue             REAL,
    avg_nights          REAL,
    booked_nights       INTEGER,
    available_nights    INTEGER,
    occupancy_rate      REAL,
    avg_lead_time_days  REAL,
    conversion_by_source TEXT,          -- JSON array per kanaal
    monthly_trend       TEXT,           -- JSON array 6 maanden
    raw_json            TEXT
);
```

#### Wat Warren er mee doet

```bash
# Snelle samenvatting
python3 query_revenue.py summary

# Per-kamer analyse (voor Michiel: welke kamer presteert beter?)
python3 query_revenue.py rooms

# Resultaat voorbeeld:
# 🏠 Per-kamer analyse (laatste 30 dagen | snapshot: 2026-03-29)
#
#   Gastenverblijf Boven in Driebergen
#     Boekingen:       13
#     Omzet:           €1.986,12
#     Gem. nachten:    1,3
#     Bezettingsgraad: 56,7%  (17/30 nachten)
#     Lead time:       23,9 dagen
#     Conv per bron:
#       Direct / Eigen site       9 bezoekers,  9 boekingen, 100,0%
#       Google                    1 bezoekers,  1 boekingen, 100,0%
#
#   Gastenverblijf Beneden in Driebergen
#     Boekingen:       14
#     Omzet:           €1.227,68
#     Bezettingsgraad: 50,0%  (15/30 nachten)
#     ...
```

#### Wat Gary er mee doet

Gary (CMO) krijgt alleen de geaggregeerde channel-data:

```bash
python3 query_revenue.py rooms
# → ziet: Google converteert 2,3% vs Direct 16,1%
# → conclusie: Google-campagne budget heroverwegen
# → post bevinding in C-Suite Chat voor Warren en Michiel
```

### 6.2 Matomo — de volledige data-stack

**Doel:** Website-analytics bijhouden: bezoeken, bounce, funnel, herkomst, gedrag.

#### Databron

Matomo for WordPress slaat alle data lokaal op in de WordPress database (zelfde DB als VikBooking, andere tabellen). Geen externe Matomo-server nodig.

```
GET /wp-json/brain/v1/matomo/summary
    → Totalen: visits, unique_visitors, bounce_rate, avg_time_seconds
    → Acquisitie: traffic_sources, search_engines, keywords, social, ai_assistants
    → Gedrag: top_pages, entry_pages, exit_pages, outlinks
    → Goals: interest → intentie → actie → succes (5 funnel-stappen)
    → Geo: countries, cities

GET /wp-json/brain/v1/matomo/diagnostic
    → Alle matomo_* tabellen + sample rows
```

#### Booking funnel (5 goals)

```
Goal 1: Interesse    URL bevat checkin=         → bezoeker zoekt datum
Goal 3: Intentie NL  URL exact /searchform/     → NL boekingsformulier
Goal 4: Intentie EN  URL exact /en/searchform/  → EN boekingsformulier
Goal 2: Actie        URL bevat task=oconfirm    → bevestigingsstap
Goal 5: Succes       URL bevat /reservering/    → boeking voltooid
```

**Funnel drop-off berekening:**
```
Interesse → Intentie:  intentie / interesse × 100%
Intentie → Actie:      actie / intentie × 100%
Actie → Succes:        succes / actie × 100%
```

#### Matomo SQLite schema

```sql
CREATE TABLE snapshots (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    fetched_at        TEXT NOT NULL,
    period_days       INTEGER,
    total_visits      INTEGER,
    unique_visitors   INTEGER,
    new_visitors      INTEGER,
    returning_visitors INTEGER,
    total_pageviews   INTEGER,
    avg_time_seconds  INTEGER,
    bounce_rate       REAL,
    -- JSON kolommen:
    traffic_sources   TEXT,   -- [{type, visits}]
    search_engines    TEXT,
    search_keywords   TEXT,
    social_networks   TEXT,
    ai_assistants     TEXT,
    devices           TEXT,
    browsers          TEXT,
    countries         TEXT,
    cities            TEXT,
    top_pages         TEXT,
    entry_pages       TEXT,
    exit_pages        TEXT,
    goal_conversions  TEXT,   -- [{goal_id, name, conversions}]
    funnel            TEXT,   -- {interesse, intentie_nl, intentie_en, actie, succes}
    funnel_dropoff    TEXT,
    raw_json          TEXT
);
```

### 6.3 Cross-source analyse

Warren combineert beide databases in één query-script:

```
matomo.db   →  bezoekersdata, funnel, herkomst
vikbooking.db →  boekingen, omzet, per kamer

Combinatie: conv_rate = bookings / matomo_visits × 100
```

**Actuele resultaten (2026-03-29):**

| Metric | Waarde |
|---|---|
| Website bezoeken (30d) | 207 |
| Boekingen (30d) | 26 |
| Totale omzet | €3.055,95 |
| Conversieratio | 10,63% |
| Gem. orderwaarde | €117,54 |
| Boven bezetting | 56,7% |
| Beneden bezetting | 53,3% |
| Google conv. rate | 2,3% |
| Direct conv. rate | 16,1% |

---

## 7. Lab Decision Board — goedkeuringsflow

### 7.1 Wanneer verplicht

Elke wijziging aan het data-ecosysteem gaat via het Lab Decision Board. Geen uitzonderingen.

| Situatie | Type verzoek |
|---|---|
| Nieuwe skill toevoegen | `skill_create` |
| Bestaand script aanpassen | `script_update` |
| Cron-schema wijzigen | `cron_update` |
| WordPress plugin aanpassen | `plugin_update` |
| Nieuwe databron toevoegen | `source_add` |
| Skill verwijderen | `skill_delete` |
| Agent vraagt data die er niet is | Via Elon → `skill_create` of `script_update` |

### 7.2 De volledige flow

```
┌─────────────────────────────────────────────────────────────────────┐
│                    LAB DECISION BOARD FLOW                         │
└─────────────────────────────────────────────────────────────────────┘

  FASE 1: AANVRAAG
  ─────────────────
  Elon stelt vast dat een skill nodig is (eigen analyse of verzoek van Warren/Gary)
        │
        ▼
  Elon analyseert databron:
  - Welk type? (WordPress bridge / REST API / MCP)
  - Welke endpoint/fields zijn beschikbaar?
  - Welke kosten (API credits)?
  - Welk SQLite schema is nodig?
  - Welke cron-frequentie?
        │
        ▼
  Elon maakt Lab Decision Request aan via dashboard API:
  curl -X POST http://127.0.0.1:3333/api/agent-tasks \
    -d '{"status":"proposed","title":"...","agent":"elon",...}'
        │
        ▼

  FASE 2: MICHIEL BEOORDEELT
  ──────────────────────────
  Michiel ziet het verzoek in het dashboard (🧪 Lab tab)
        │
        ├── 🚀 GOEDKEUREN → Fase 3
        ├── ❌ AFWIJZEN   → Elon informeert aanvragende agent → EINDE
        └── 💬 FEEDBACK  → Elon herziet het verzoek → terug naar Fase 1
        │
        ▼

  FASE 3: IMPLEMENTATIE
  ─────────────────────
  Status wordt 'in_progress'
  Elon spawnt een geïsoleerde subagent met volledige instructie:
  - Directory aanmaken
  - fetch_*.py schrijven (absoluut pad, WAL-mode, error handling)
  - skill.json aanmaken
  - SKILL.md aanmaken
  - Script handmatig testen
  - Cron registreren (via OpenClaw cron API)
        │
        ▼

  FASE 4: VERIFICATIE
  ────────────────────
  Elon verifieert:
  ✓ Script draait zonder fouten
  ✓ SQLite database bevat minimaal 1 rij
  ✓ Velden zijn gevuld (niet null)
  ✓ Cron is geregistreerd
  ✓ SKILL.md is compleet
        │
        ▼
  Elon rapporteert resultaat, update status naar 'review'
        │
        ▼

  FASE 5: MICHIEL SLUIT AF
  ─────────────────────────
  Michiel bekijkt resultaat in dashboard of Discord
        │
        ├── ✅ Akkoord → status 'done'
        └── 💬 Aanpassing nodig → terug naar Fase 3
```

### 7.3 Het verzoek-format

```
🔬 LAB DECISION REQUEST
────────────────────────────────────────────────────
Type:        [NIEUWE SKILL | SKILL WIJZIGING | PLUGIN UPDATE | CRON AANPASSING]
Skill naam:  <skill-id>
Agent:       elon
Prioriteit:  [hoog | medium | laag]

## Wat
<Eén zin: wat ga ik bouwen of aanpassen>

## Waarom
<Welk agent-probleem lost dit op? Welke data ontbreekt nu?>

## Databron
Type:      [WordPress Bridge | REST API | MCP]
Endpoint:  <URL>
Data:      <lijst van velden die opgeslagen worden>
Kosten:    <API-kosten per call, of 'gratis'>

## Implementatieplan
1. Directory aanmaken: skills/<naam>/
2. fetch_<naam>.py schrijven
3. skill.json aanmaken
4. Testen
5. Cron registreren

## SQLite schema
Tabel:     snapshots
Kolommen:
  fetched_at  TEXT  — ISO timestamp
  veld_1      INT   — beschrijving
  veld_2      REAL  — beschrijving
  json_veld   TEXT  — JSON array van [...]

## Cron
Frequentie:  wekelijks [dag] 08:00 Amsterdam

────────────────────────────────────────────────────
Reageer met 🚀 goedkeuren | ❌ afwijzen | 💬 feedback
```

### 7.4 Wat Michiel weet bij elke fase

| Status | Wat Michiel ziet | Wat Michiel kan doen |
|---|---|---|
| `proposed` | Verzoek + volledige onderbouwing | 🚀 / ❌ / 💬 |
| `in_progress` | Elon is bezig | Wachten of annuleren |
| `review` | Resultaat + testbevestiging | ✅ done of 💬 aanpassen |
| `done` | Skill actief in productie | — |

---

## 8. Communicatie tussen agents

### 8.1 Drie kanalen

```
┌──────────────────────────────────────────────────────────────────┐
│  KANAAL 1: sessions_spawn / sessions_send                        │
│  ─────────────────────────────────────────────────────────────── │
│  Voor: taakoverdracht (Muddy → Elon/Gary/Warren)                 │
│  Kenmerken: asynchroon, fire-and-forget, completion bericht       │
│                                                                  │
│  sessions_spawn({                                                │
│    agentId: "elon",                                              │
│    task: "Draai fetch_bookings.py en rapporteer de snapshot",    │
│    mode: "run",                                                  │
│    thread: true   ← zichtbaar voor Michiel in Discord           │
│  })                                                              │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│  KANAAL 2: C-Suite Chat (.openclaw/workspace/c-suite-chat.jsonl) │
│  ─────────────────────────────────────────────────────────────── │
│  Voor: async statusupdates, beslissingen, signalen               │
│  Kenmerken: JSONL formaat, altijd appenden, nooit overschrijven  │
│                                                                  │
│  {"ts":"2026-03-29T10:00:00Z","from":"warren","to":"all",        │
│   "message":"Boven levert 65% van revenue maar slechts          │
│   50% van boekingen. Lead time 23d vs 21d — meer advance        │
│   bookers. Aanbeveling: vroegboekkorting voor Boven."}           │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│  KANAAL 3: Lab Decision Board (dashboard API)                    │
│  ─────────────────────────────────────────────────────────────── │
│  Voor: alle goedkeuringen en wijzigingen                         │
│  Kenmerken: NOOIT direct naar agent-tasks.json schrijven         │
│                                                                  │
│  curl -X POST http://127.0.0.1:3333/api/agent-tasks \           │
│    -H "Content-Type: application/json" \                         │
│    -d '{"status":"proposed","title":"...",...}'                  │
└──────────────────────────────────────────────────────────────────┘
```

### 8.2 Communicatieprotocol per agent

**Muddy (COO) — spelregels:**
- Delegeer ALLES aan het team, voer zelf niets uit (behalve bij expliciete trigger "doe het nu")
- Verplaats nooit skill-scripts zonder Elon te spawnen — bestanden in `skills/` zijn locatie-gevoelig
- Schrijf nooit direct naar `agent-tasks.json` — altijd via dashboard API

**Elon (CTO) — spelregels:**
- Bouw en beheer alle scripts, databases en crons
- Vraag ALTIJD eerst Lab Decision Board goedkeuring, ook voor kleine wijzigingen
- Test elk nieuw script handmatig vóór de cron inschakelen
- Informeer Warren en Gary via C-Suite Chat wanneer nieuwe data beschikbaar is

**Warren (CRO) — spelregels:**
- Lees alleen uit SQLite — roep nooit rechtstreeks de brain bridge API aan
- Vraag Elon (via Muddy) om een nieuwe snapshot als de data verouderd is
- Schrijf bevindingen naar C-Suite Chat, niet als losse Discord-berichten
- Stuur data-behoeften (nieuwe velden, nieuwe analyses) via Elon → Lab Decision Board

**Gary (CMO) — spelregels:**
- Gebruik `query_revenue.py rooms` voor channel-performance (geaggregeerd, geen klantdata)
- Geen directe toegang tot boekingen of klantdata — dat is Warren's domein
- Campagne-conclusies die data-backing vereisen: altijd via Warren bevestigen

### 8.3 Wekelijks ritme

```
Maandag 08:00  → Cron: fetch_bookings.py draait automatisch
                  Elon rapporteert nieuwe snapshot via C-Suite Chat

Woensdag 08:00 → Cron: fetch_matomo.py draait automatisch
                  Elon rapporteert nieuwe snapshot via C-Suite Chat

Maandag 08:30  → Executive standup (Muddy coördineert)
                  Warren: revenue-update (query_revenue.py summary + rooms)
                  Gary: content- en campagne-update
                  Elon: technische status + eventuele Lab Requests

Zondag 09:30   → Wekelijkse planning (Muddy coördineert)
                  Terugkijken op data van afgelopen week
                  Nieuwe analyses of skill-aanvragen initiëren
```

---

## 9. Wat te vermijden — bekende valkuilen

### 9.1 Database-paden

| ❌ Fout | ✅ Goed |
|---|---|
| `Path(__file__).parent.parent / "data" / "db"` | Absoluut pad: `/home/agent/workspace/...` |
| Muddy verplaatst script zonder Elon | Altijd Elon spawnen voor bestandswijzigingen in `skills/` |
| Warren leest uit `workspace/data/` terwijl Elon naar `workspace-elon/data/` schrijft | Één database per bron, één pad, zorg dat schrijver en lezer hetzelfde pad gebruiken |

### 9.2 Lab Decision Board

| ❌ Fout | ✅ Goed |
|---|---|
| Elon past script aan zonder request | Altijd Lab Decision Board first |
| Schrijven direct naar `agent-tasks.json` | Altijd via `curl` naar dashboard API |
| Cron zonder test inschakelen | Script eerst handmatig draaien, output verifiëren |
| Dubbele cron na update | Oude cron verwijderen vóór nieuwe aanmaken |

### 9.3 Snapshot-model

| ❌ Fout | ✅ Goed |
|---|---|
| Data overschrijven (UPDATE bestaande rij) | Altijd nieuwe rij per run (INSERT) |
| JSON-velden als losse kolommen opslaan | Arrays als JSON TEXT opslaan, parsen in Python |
| Eén grote database voor alle bronnen | Aparte .db per databron (WAL-mode werkt beter) |
| SQLite zonder WAL-mode | Altijd `PRAGMA journal_mode=WAL` in `init_db()` |

### 9.4 Agent-grenzen

| ❌ Fout | ✅ Goed |
|---|---|
| Warren roept live API aan | Warren leest alleen SQLite |
| Gary ziet individuele boekingen | Gary ziet alleen geaggregeerde channel-data |
| Elon pusht data naar productie | Elon werkt alleen op staging (`WP_STAGING_URL`) |
| Muddy lost technisch probleem zelf op | Muddy delegeert altijd aan Elon |

---

## 10. Checklist: nieuwe skill toevoegen

Gebruik deze checklist voor elke nieuwe databron.

```
PRE-IMPLEMENTATIE (Elon analyseert)
─────────────────────────────────────
☐ Databron-type bepaald (WordPress bridge / REST API / MCP)
☐ Endpoint beschikbaar en gedocumenteerd
☐ Authenticatie bekend (credentials in .env?)
☐ Velden geïdentificeerd die opgeslagen worden
☐ SQLite schema ontworpen
☐ Cron-dag bepaald (niet conflicterend met andere crons)
☐ Kosten per API-call geverifieerd

LAB DECISION BOARD
───────────────────
☐ Request aangemaakt via dashboard API (status: proposed)
☐ 🚀 Goedkeuring van Michiel ontvangen

IMPLEMENTATIE (Elon's subagent)
────────────────────────────────
☐ Directory aangemaakt: workspace-elon/skills/<naam>/
☐ fetch_<naam>.py geschreven:
    ☐ Absoluut DB-pad (geen relatief pad!)
    ☐ PRAGMA journal_mode=WAL
    ☐ Credentials uit .env
    ☐ SSL-check uit (ctx.verify_mode = CERT_NONE)
    ☐ Timeout 15 seconden
    ☐ Error handling (sys.exit(1) bij missende credentials)
    ☐ raw_json kolom (volledige API response opslaan)
☐ skill.json aangemaakt (zie schema §5.2)
☐ SKILL.md aangemaakt

VERIFICATIE
─────────────
☐ Script handmatig getest: source .env && python3 fetch_<naam>.py
☐ SQLite database bevat minimaal 1 rij
☐ Alle velden gevuld (geen NULL waar data verwacht wordt)
☐ Cron geregistreerd en actief
☐ Status Lab Decision Board: 'review'

NA MICHIEL'S GOEDKEURING
──────────────────────────
☐ Status: 'done'
☐ PRD-v5 bijgewerkt (of dit document)
☐ Warren/Gary geïnformeerd via C-Suite Chat
☐ SKILL.md gedocumenteerd voor andere agents
```

---

## 11. Implementatie voor nieuwe gebruikers

### 11.1 Vereisten

| Component | Wat je nodig hebt |
|---|---|
| OpenClaw stack | Draaiende Muddy + Elon + Warren + Gary |
| WordPress site | Plugin `open-brain-analytics-bridge` geïnstalleerd en actief |
| WordPress credentials | Application Password voor API-authenticatie |
| Python 3.9+ | In de VM/agent-omgeving beschikbaar |
| SQLite | Standaard beschikbaar in Python (geen installatie) |
| .env bestand | `WP_API_USER`, `WP_API_PASSWORD`, `WP_STAGING_URL` |

### 11.2 Minimale .env configuratie

```bash
# /home/agent/workspace/.env
WP_STAGING_URL=https://www.jouwsite.nl
WP_API_USER=jouw_wp_gebruikersnaam
WP_API_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx  # WordPress Application Password
```

> **Application Password aanmaken:** WordPress Admin → Gebruikers → Profiel → Application Passwords → voeg toe → kopieer (spaties mogen erbij blijven, worden automatisch verwijderd).

### 11.3 WordPress Brain Bridge plugin

De plugin is het fundament van het data-ecosysteem. Zonder werkende endpoints kunnen scripts geen data ophalen.

**Installatie:**
1. Upload `wordpress-open-brain-bridge-production.php` naar `/wp-content/plugins/open-brain-analytics-bridge/`
2. Activeer de plugin in WordPress Admin → Plugins
3. Flush permalinks: Instellingen → Permalinks → Opslaan

**Endpoint testen:**
```bash
curl -s -u "gebruiker:applicatiepassword" \
  "https://jouwsite.nl/wp-json/brain/v1/vikbooking/diagnostic" | python3 -m json.tool
```

Verwacht: JSON met `success: true` en alle `vikbooking_*` tabellen.

**Beschikbare endpoints (versie 1.1.0):**

| Endpoint | Authenticatie | Data |
|---|---|---|
| `/wp-json/brain/v1/vikbooking/summary` | Basic Auth | Site-wide boekingen 30d |
| `/wp-json/brain/v1/vikbooking/rooms/summary` | Basic Auth | Per kamer (optioneel: `?days=N`) |
| `/wp-json/brain/v1/vikbooking/diagnostic` | Basic Auth | Tabelstructuur + samples |
| `/wp-json/brain/v1/matomo/summary` | Basic Auth | Site-wide analytics 30d |
| `/wp-json/brain/v1/matomo/diagnostic` | Basic Auth | Matomo tabelstructuur |

### 11.4 Stap-voor-stap: eerste skill opzetten

**Stap 1: Elon's workspace voorbereiden**

```bash
mkdir -p /home/agent/workspace/.openclaw/workspace-elon/data
mkdir -p /home/agent/workspace/.openclaw/workspace-elon/skills/vikbooking-bookings
```

**Stap 2: fetch_bookings.py plaatsen**

Kopieer het script naar `workspace-elon/skills/vikbooking-bookings/fetch_bookings.py`.
Pas de absolute DB_PATH aan naar jouw omgeving.

**Stap 3: Handmatig testen**

```bash
source /home/agent/workspace/.env
python3 /home/agent/workspace/.openclaw/workspace-elon/skills/vikbooking-bookings/fetch_bookings.py
```

Verwachte output:
```
[vikbooking-bookings] Ophalen van https://jouwsite.nl/wp-json/brain/v1/vikbooking/summary ...
[vikbooking-bookings] ✓ Snapshot opgeslagen:
  fetched_at:  2026-03-29T10:00:00+00:00
  bookings:    26 (afgelopen 30 dagen)
  revenue:     €3055.95
  db_path:     /home/agent/workspace/.openclaw/workspace-elon/data/vikbooking.db
  total_rows:  1 snapshot(s) in database
```

**Stap 4: Cron activeren**

Voeg het cron-object uit `skill.json` toe aan OpenClaw's cron configuratie. De naam (`vikbooking-weekly-sync`) en het schedule (`0 8 * * 1`) zijn al ingesteld.

**Stap 5: Warren's query-script activeren**

```bash
# Test alle commando's
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py summary
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py rooms
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py traffic
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py funnel
```

**Stap 6: Tweede skill toevoegen (Matomo)**

Herhaal stappen 1–5 met `fetch_matomo.py` en cron-dag woensdag. Na twee weken heb je de eerste cross-source vergelijking beschikbaar.

### 11.5 Aanpassen voor jouw databron

| Jouw situatie | Aanpassing |
|---|---|
| Andere WordPress prefix (bijv. `wp_`) | Pas de bridge plugin aan: `$table_prefix` variabele |
| Andere boekingstool (bijv. WooCommerce) | Schrijf nieuwe bridge endpoint + nieuw fetch-script |
| Externe REST API (geen WordPress) | Geen bridge nodig — direct `requests.get()` in fetch-script |
| Andere cron-frequentie | Pas `skill.json → cron → schedule → expr` aan (standaard cron syntax) |
| Meer kamers/producten | Geen aanpassing nodig — `rooms/summary` schalt automatisch |

---

## Appendix A — Databronnen overzicht

| Bron | Status | Methode | Database | Cron | Inhoud |
|---|---|---|---|---|---|
| **VikBooking** | ✅ Actief | WordPress bridge | `vikbooking.db` | Ma 08:00 | Boekingen, omzet, bezetting, conv per kamer |
| **Matomo** | ✅ Actief | WordPress bridge | `matomo.db` | Wo 08:00 | Bezoeken, funnel, herkomst, gedrag |
| **Google Search Console** | 🔜 Gepland | MCP | `gsc.db` | Vr 08:00 | Clicks, impressies, ranking per query |
| **DataforSEO** | 🔜 Gepland | REST API | `dataforseo.db` | Di 08:00 | Zoekvolume, keyword difficulty |

---

## Appendix B — Snel naslagwerk

```bash
# === ELON: script handmatig draaien ===
source /home/agent/workspace/.env
python3 /home/agent/workspace/.openclaw/workspace-elon/skills/vikbooking-bookings/fetch_bookings.py
python3 /home/agent/workspace/.openclaw/workspace-elon/skills/matomo-traffic/fetch_matomo.py

# === WARREN: data opvragen ===
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py summary
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py rooms
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py traffic
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py funnel
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py trend --days 90
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py json

# === GARY: channel-performance ===
python3 /home/agent/workspace/.openclaw/workspace/skills/warren-revenue-analytics/query_revenue.py rooms

# === DIAGNOSE: WordPress endpoints testen ===
curl -s -u "$WP_API_USER:$WP_API_PASSWORD" "$WP_STAGING_URL/wp-json/brain/v1/vikbooking/diagnostic" -k | python3 -m json.tool
curl -s -u "$WP_API_USER:$WP_API_PASSWORD" "$WP_STAGING_URL/wp-json/brain/v1/vikbooking/rooms/summary" -k | python3 -m json.tool
curl -s -u "$WP_API_USER:$WP_API_PASSWORD" "$WP_STAGING_URL/wp-json/brain/v1/matomo/summary" -k | python3 -m json.tool

# === LAB DECISION BOARD: verzoek indienen ===
curl -s -X POST http://127.0.0.1:3333/api/agent-tasks \
  -H "Content-Type: application/json" \
  -d '{"id":"<uuid>","title":"Nieuwe skill: ...","description":"...","plan":"...","agent":"elon","status":"proposed","priority":"medium","category":"technical","createdAt":"<ISO-timestamp>"}'

# === DATABASE: direct inspecteren (Python, geen sqlite3 CLI nodig) ===
python3 -c "
import sqlite3, json
db = '/home/agent/workspace/.openclaw/workspace-elon/data/vikbooking.db'
c = sqlite3.connect(db)
rows = c.execute('SELECT fetched_at, bookings, revenue FROM snapshots ORDER BY fetched_at DESC LIMIT 3').fetchall()
for r in rows: print(r)
c.close()
"
```

---

*Aangemaakt: 2026-03-29*
*Bouwt voort op PRD v5 (data layer, skill automation)*
*Bevat bevindingen uit live implementatie en debugging sessie 2026-03-29*
