# OpenClaw Kennissysteem Scanrapport — AUDIT3
**Datum:** 2026-04-23  
**Gegenereerd door:** Claude Code (analyse-agent, sandbox)  
**Gebaseerd op:** Karpathy Wiki-Vault vs Open Brain analyse (AUDIT3-opdracht)

---

## INLEIDING

Dit rapport inventariseert de volledige OpenClaw-omgeving vanuit het perspectief van kennisrouting. Het doel is concreet: welke regels per agent moeten worden toegevoegd aan hun `AGENTS.md` zodat ze weten welke informatie via de **Karpathy Wiki-Vault** en welke via de **OpenBrain MCP server** gezocht moet worden.

---

# DOCUMENT 1 — SCANRAPPORT

## 1. Geïnventariseerde Agent-rollen

OpenClaw draait als NixOS MicroVM op host `~/openclaw-sandbox`. De workspace staat op `~/openclaw-workspace` (gemount als `/home/agent/workspace` in de VM). Wiki-vault staat op `~/wiki-vault` (gemount als `/home/agent/wiki`).

| Agent | Emoji | Rol | Workspace | subagents spawnen? |
|-------|-------|-----|-----------|-------------------|
| **Muddy** | 🐙 | COO / Coordinator / Discord interface | `workspace/` | Ja (elon, dario, gary, warren, memory-agent) |
| **Elon** | ⚡ | CTO / Technical data syncs / infra | `workspace-elon/` | Nee |
| **Dario** | 🔬 | Senior Analyst / Wiki-intelligence / Skill-building | `workspace-dario/` | Nee |
| **Gary** | 🎯 | CMO / Content strategie / marketing | `workspace-gary/` | Nee |
| **Warren** | 📈 | CRO / Revenue analyse / booking analytics | `workspace-warren/` | Nee |
| **Memory Agent** | 🧠 | Langetermijngeheugen opslaan/ophalen | `workspace/` (MEMORY.md) | Nee |

Overige workspace-configuraties aanwezig maar niet actief als volwaardige agents: `workspace-main` (generieke bootstrap template), `codex` (geen agent-instructies), `editor`/`researcher`/`writer` (lege agent-dirs, subagent roles van Gary).

---

## 2. Gekoppelde Tools en MCP-servers

### Globale MCP-servers (`.mcp.json` in workspace root)

| MCP Server | Tool prefix | Functie | Status |
|------------|-------------|---------|--------|
| `llm-wiki` | `llm-wiki__*` | Lezen/schrijven naar wiki-vault (`/home/agent/wiki`) | Actief |
| `qmd` | `qmd__*` | Semantisch zoeken in wiki-vault collections via BM25+vectors (lokaal SQLite) | Actief — 58 bestanden, 229 vectors |
| `context7` | `context7__*` | Externe bibliotheekdocumentatie | Actief |

### OpenBrain MCP (geconfigureerd in `openclaw.json`)

| MCP Server | Transport | Functie | Status |
|------------|-----------|---------|--------|
| `openclaw-reports` | Streamable HTTP (Supabase Edge Function) | Semantisch zoeken in agent-rapporten via pgvector | **Actief** (auth via `OPENBRAIN_KEY`) |

**OpenBrain tools:**
- `search_reports` — semantisch zoeken in alle agent-rapporten
- `list_reports` — filteren op agent/type/datum
- `get_report` — ophalen op ID of wiki_path
- `report_stats` — statistieken per agent/type
- `store_report` — opslaan (wordt door Lobster-pipelines aangeroepen, niet handmatig)

### Plugins (via `openclaw.json`)

| Plugin | Functie |
|--------|---------|
| `lobster` | Deterministisch workflow-execution engine |
| `llm-task` | LLM-aanroepen vanuit Lobster-pipelines |
| `browser` | Headless Chromium (profile: `openclaw`) |
| `ollama` | Lokale LLM via Ollama (Qwen3.5:9b) |

---

## 3. Geïnventariseerde Datatypen per Systeem

### Wiki-Vault (`~/wiki-vault/` = `/home/agent/wiki`)

| Map | Datatype | Aantal bestanden (geschat) | Beheerd door |
|-----|----------|---------------------------|--------------|
| `agents/daily/` | Executive sync rapporten (dagelijks) | 7 actief | Muddy via `daily-executive-sync.lobster` |
| `agents/elon/data-syncs/` | Matomo/VikBooking/Reviews sync-logs | ~12 | Elon via Lobster-pipelines |
| `agents/elon/wiki-checks/` | WP freshness, raw-check, wiki-audit logs | ~6 | Elon via Lobster-pipelines |
| `agents/dario/tech-audit/` | Wekelijkse tech audits (8-weeks rotatie) | ~4 | Dario via `dario-tech-audit.lobster` |
| `agents/dario/wiki-ingest/` | Wiki-ingest logs | ~2 | Dario via wiki-ingest skill |
| `agents/dario/skill-building/` | Skill-building rapporten | ~2 | Dario via `skill-building` |
| `agents/warren/booking-analytics/` | Boeking-revenue analyses (8-weeks rotatie) | ~4 | Warren via `warren-booking-analytics.lobster` |
| `agents/warren/revenue-strategy/` | Revenue strategie rapporten | ~4 | Warren via `warren-revenue-strategy.lobster` |
| `agents/warren/review-strategy/` | Review-revenue analyse | ~2 | Warren via skill |
| `agents/gary/booking-insights/` | Marketing booking-insights (8-weeks rotatie) | ~4 | Gary via `gary-booking-insights.lobster` |
| `agents/gary/content-strategy/` | Content strategie rapporten | ~4 | Gary via `gary-content-strategy.lobster` |
| `agents/gary/review-strategy/` | Review strategie | ~2 | Gary via skill |
| `agents/memory/` | Memory-extractie logs | 1 actief | Memory-pipeline |
| `output/` | Ad-hoc rapporten en query-resultaten | ~60 bestanden | Diverse agents, handmatig |
| `wiki/logies-op-dreef/` | Gesynthetiseerde wiki-pagina's over het bedrijf | ~25 bestanden | Dario via wiki-ingest |
| `raw/` | Bronbestanden (nooit door agents gewijzigd) | ~25 bestanden | Michiel handmatig |

**Totaal wiki-vault: ~178 markdown-bestanden**

### OpenBrain (`agent_reports` tabel op Supabase)

| Datatype | Hoe erin? | Beschikbaar via |
|----------|-----------|-----------------|
| Agent-rapporten (pipeline output) | `store_openbrain` stap in Lobster | `search_reports`, `list_reports`, `get_report` |
| Historische rapporten (batch) | `openbrain-sync.lobster` (handmatig, éénmalig) | Zelfde tools |

**Huidige staat OpenBrain:** PRD-v11 volledig geïmplementeerd en operationeel (2026-04-23). 129 rapporten gesynchroniseerd: muddy 63, warren 30, elon 17, gary 14, dario 5. OpenBrain is de actuele bron voor alle historische agent-rapporten.

### Operationele databases (buiten beide kennissystemen)

| Database | Pad | Beheerd door | Gelezen door |
|----------|-----|--------------|--------------|
| `vikbooking.db` | `workspace-elon/data/vikbooking.db` | Elon (wekelijks sync) | Warren (di 09:00), Gary (di 10:30) |
| `reviews.db` | `workspace-elon/data/` (vermoedelijk) | Elon (wekelijks sync) | Warren, Gary |

---

## 4. Bestaande Instructies over Data Zoeken

### Per agent — samenvatting van huidige AGENTS.md-instructies

#### MUDDY (COO)
- **Wiki-Vault:** Sectie aanwezig — 5 collections uitgelegd (`agents-output`, `wiki-output`, `wiki-planning`, `logies-op-dreef`, `logies-op-dreef-wiki`). Gebruik via `qmd__query`. Archief-bewustheid aanwezig (>60 dagen naar archive/).
- **OpenBrain:** Sectie aanwezig (recent toegevoegd PRD-v11). Beschrijft `search_reports`, `list_reports`, `get_report`, `report_stats`. Wanneer te gebruiken: bij vragen naar eerdere analyses, vóór nieuwe analyse starten.
- **Beslisboom:** NIET aanwezig. Geen routing-logica "wanneer wiki vs OpenBrain".
- **Geschatte leesvragen:** Narratief ("wat hebben we geconcludeerd over X"), feitelijk ("rapport van week 15"), historisch ("dario audit laatste 2 weken").

#### ELON (CTO)
- **Wiki-Vault:** Alleen schrijven via Lobster-pipelines beschreven. Lezen: `wiki/agents/elon/` voor historische data. Geen `qmd__query` instructie.
- **OpenBrain:** Geen sectie. Elon's pipelines schrijven naar OpenBrain via `store_openbrain` stap, maar Elon krijgt geen instructie om OpenBrain te raadplegen.
- **Beslisboom:** Afwezig.
- **Opmerking:** Elon is primair schrijver/data-producent, zelden lezer van kennissystemen.

#### DARIO (Senior Analyst)
- **Wiki-Vault:** Schrijven via `llm-wiki__vault_write`. Lezen: `qmd__query` met collection `agents-output` vermeld. 60-dagenarchief bewustheid aanwezig.
- **OpenBrain:** Geen sectie. Dario is de skills-bouwer en wiki-intelligence agent — juist déze agent mist OpenBrain-routing-instructies.
- **Beslisboom:** Afwezig.
- **Kritiek hiaat:** Dario doet wiki-intelligence taken (zoeken, samenvatten, analyseren). De beslisboom "wanneer qmd vs OpenBrain search_reports" ontbreekt volledig.

#### GARY (CMO)
- **Wiki-Vault:** Schrijven via `llm-wiki__vault_write`. Lezen: `qmd__query` collection `agents-output` beschreven. Archief-bewustheid aanwezig.
- **OpenBrain:** Geen sectie.
- **Beslisboom:** Afwezig.

#### WARREN (CRO)
- **Wiki-Vault:** Schrijven via `llm-wiki__vault_write`. Lezen: `qmd__query` collection `agents-output` beschreven. Archief-bewustheid aanwezig.
- **OpenBrain:** Geen sectie.
- **Beslisboom:** Afwezig.

#### MEMORY AGENT
- **Wiki-Vault:** Niet beschreven. Memory Agent schrijft uitsluitend naar `MEMORY.md` en `memory/YYYY-MM-DD.md`.
- **OpenBrain:** Niet beschreven.
- **Opmerking:** Memory Agent heeft geen kennissysteem-routering nodig — zijn rol is beperkt tot flat-file memory.

---

## 5. Geïdentificeerde Hiaten en Conflicten

### Hiaat 1: Dario mist OpenBrain-instructies (KRITIEK)
Dario is de wiki-intelligence agent. Hij doet zoekopdrachten, ingest, analyse. Juist hij heeft de meeste behoefte aan een beslisboom "wanneer qmd (lokale wiki) vs search_reports (OpenBrain cloud)". Deze instructies ontbreken volledig.

### Hiaat 2: Gary en Warren missen OpenBrain-instructies
Gary en Warren raadplegen historische output voor hun analyses. Ze weten alleen van `qmd__query`. Ze weten niet dat `search_reports` bestaat of wanneer het beter is dan qmd.

### ~~Hiaat 3: Batch-ingest OpenBrain~~  ✅ OPGELOST
129 rapporten gesynchroniseerd (2026-04-23). OpenBrain is volledig actueel als bron voor historische agent-rapporten.

### Hiaat 4: Geen routing-beslisboom in enige AGENTS.md
Geen enkele agent heeft een gestructureerde beslisboom die bepaalt wanneer qmd vs OpenBrain vs directe bestandslezing wordt gebruikt. Agents maken ad-hoc keuzes, wat leidt tot inconsistent gebruik.

### Hiaat 5: qmd-collections niet duidelijk gedocumenteerd bij alle agents
Alleen Muddy heeft een volledige tabel met alle 5 qmd-collections. Dario, Gary en Warren hebben alleen `agents-output` vermeld. De andere collections (`wiki-output`, `wiki-planning`, `logies-op-dreef`, `logies-op-dreef-wiki`) zijn niet beschreven voor deze agents.

### Hiaat 6: Elon's pipelines schrijven naar OpenBrain, maar Elon weet het niet
`daily-executive-sync.lobster`, `warren-revenue-strategy.lobster`, etc. bevatten `store_openbrain` stap. Elon's eigen pipelines (matomo-sync, vikbooking-sync, reviews-sync) hebben geen `store_openbrain` stap — terwijl die output ook historisch doorzoekbaar zou moeten zijn.

### Conflict 1: Wiki-vault als "enige bron van waarheid" vs OpenBrain
Historisch was de wiki-vault de enige kennisbank. Nu is OpenBrain geïntroduceerd als "enige bron van waarheid" (PRD-v11). Maar de wiki-vault is nog steeds de primaire schrijfbestemming voor alle pipelines. De omgekeerde stelling (OpenBrain is de database, wiki is een gegenereerde view) is nog niet operationeel — de wiki wordt nog handmatig geschreven en is niet puur gegenereerd.

### Conflict 2: Memory Agent leest MEMORY.md, niet OpenBrain
Duurzame beslissingen staan in `MEMORY.md` (plat bestand). Dezelfde informatie zou ook in OpenBrain kunnen staan voor semantisch zoeken. Nu zijn er twee ongerelateerde langetermijngeheugen-systemen: `MEMORY.md` en `agent_reports` in OpenBrain.

---

## 6. Volumeschattingen

| Systeem | Categorie | Geschat volume | Groeisnelheid |
|---------|-----------|----------------|---------------|
| Wiki-Vault agents/ | Periodieke pipeline-output | 68 bestanden | ~7/week |
| Wiki-Vault output/ | Ad-hoc rapporten | 60 bestanden | ~3/week |
| Wiki-Vault wiki/ | Gesynthetiseerde wiki-pagina's | 25 bestanden | ~1/week |
| Wiki-Vault raw/ | Bronbestanden (onbewerkt) | 25 bestanden | sporadisch |
| OpenBrain agent_reports | Gesynchroniseerde rapporten | 2 (+ batch openstaand: ~60) | ~5/week na sync |
| Operationele DB vikbooking.db | Individuele boekingen | 1016 boekingen | ~5/week |
| MEMORY.md | Duurzame beslissingen | 1 bestand (~80 regels) | ~2 items/week |

---

# DOCUMENT 2 — ROUTERINGSREGELS (DIRECT INZETBAAR)

```
## KENNISROUTING REGELS — OpenClaw (Logies op Dreef)
## Gegenereerd door: Scan-agent op 2026-04-23
## Gebaseerd op: Karpathy Wiki-Vault vs OpenBrain analyse (AUDIT3)

---

### BESLISBOOM — gebruik dit bij ELKE informatiebehoefte

VRAAG 1: Is de gevraagde informatie een agent-RAPPORT
         (analyse, audit, strategie, sync-log, booking-inzicht)?
  → JA  → Ga naar vraag 1a
  → NEE → Ga naar vraag 2

VRAAG 1a: Wil je SEMANTISCH zoeken in meerdere rapporten op betekenis?
  → JA  → Gebruik OPENBRAIN (search_reports)
  → NEE → Weet je het exacte bestandspad? Lees het bestand direct.
           Weet je alleen de agent en datum? Gebruik qmd agents-output.

VRAAG 2: Gaat de vraag over BEDRIJFSFEITEN of STRATEGISCH BELEID
         (beslissingen Michiel, kanaalstrategie, merkwaarden)?
  → JA  → Lees MEMORY.md (duurzame beslissingen) en SOUL.md (identiteit)
  → NEE → Ga naar vraag 3

VRAAG 3: Gaat de vraag over BOEKINGEN, OMZET, BEZETTING of
         WEBSITE-DATA met specifieke filters (datum, bedrag, kanaal)?
  → JA  → Gebruik de operationele databases direct:
           vikbooking.db (SQLite), Matomo API, WordPress REST API
  → NEE → Ga naar vraag 4

VRAAG 4: Gaat de vraag over INHOUD VAN DE WEBSITE of
         INFORMATIE OVER DE ACCOMMODATIE (kamers, ervaringen, locatie)?
  → JA  → Gebruik qmd: collection "logies-op-dreef" of "logies-op-dreef-wiki"
  → NEE → Ga naar vraag 5

VRAAG 5: Gaat de vraag over PROJECTSTATUS, OPEN TAKEN of
         LAB DECISION BOARD beslissingen?
  → JA  → Gebruik qmd: collection "wiki-planning"
           OF lees agent-tasks.json direct
  → NEE → Ga naar vraag 6

VRAAG 6: Wil je CONCEPTUELE SYNTHESE of NARRATIEF BEGRIP
         (verbanden, thematische overzichten, evolutie van inzichten)?
  → JA  → Gebruik qmd: collection "wiki-output" of "agents-output"
           voor pre-gebouwde syntheses. OpenBrain voor semantisch zoeken.
  → NEE → Vraag Dario om de analyse te doen.

---

### GEBRUIK DE OPENBRAIN MCP (search_reports, list_reports) VOOR:

1. **Semantisch zoeken in agent-rapporten**
   Voorbeeld: "Zoek alle rapporten over Google Conversie"
   Voorbeeld: "Wat heeft Warren geconcludeerd over Booking.com in Q1?"
   Voorbeeld: "Vind Dario's bevindingen over WordPress security"

2. **Filteren van rapporten op agent/type/datum**
   Voorbeeld: "Geef alle Warren-rapporten van de laatste 4 weken"
   Voorbeeld: "Toon alle tech-audit rapporten van Dario"
   Voorbeeld: "Welke booking-analytics heeft Gary deze maand gedaan?"

3. **Ophalen van een specifiek rapport op ID of wiki_path**
   Voorbeeld: "Toon het revenue rapport van week 15"
   Voorbeeld: get_report met wiki_path "agents/warren/revenue-strategy/2026-W15-..."

4. **Statistieken over rapport-productie**
   Voorbeeld: report_stats — hoeveel rapporten per agent dit kwartaal?

**Status:** OpenBrain volledig gesynchroniseerd (129 rapporten). `search_reports` geeft representatieve resultaten voor alle historische rapporten.

---

### GEBRUIK QMD (semantisch zoeken — lokale wiki-index) VOOR:

Beschikbare collections en hun inhoud:

| Collection | Inhoud | Gebruik voor |
|---|---|---|
| `agents-output` | Alle agent-rapporten: daily syncs, gary/warren/dario/elon outputs, memory logs, weekly sessies (actief ≤60 dagen) | Historische agent-output, vorige analyses, beslissingen van specifieke agents |
| `wiki-output` | Ad-hoc rapporten, query-resultaten, onderzoeksartikelen (actief ≤60 dagen) | Eerder gegenereerde content, onderzoeksresultaten |
| `wiki-planning` | Lab Decision Board + project-status snapshots | Huidige projectstatus, open proposals, lab-beslissingen |
| `logies-op-dreef` | Ruwe website content (raw/) | Informatie over accommodaties, locaties, ervaringen (onbewerkt) |
| `logies-op-dreef-wiki` | Verwerkte wiki content (wiki/) | Doorzoekbare kennisbank over logies-op-dreef |

**Gebruik:**
```
qmd__query
  collection: "agents-output"
  searches: [{type: "vec", query: "warren revenue analyse boeking lead time"}]
```

**Archief:** Bestanden ouder dan 60 dagen zijn gearchiveerd naar
`/home/agent/wiki/output/archive/YYYY-MM/` — deze zijn ook geïndexeerd in qmd.

---

### GEBRUIK DIRECTE BESTANDSLEZING VOOR:

1. **MEMORY.md** — Duurzame beslissingen en architectuurkeuzes van Michiel
   Pad: `/home/agent/workspace/.openclaw/workspace/MEMORY.md`
   Gebruik: bij session startup, bij vragen over beleid

2. **C-Suite Chat** — Recente inter-agent communicatie
   Pad: `/home/agent/workspace/.openclaw/workspace/c-suite-chat.jsonl`
   Gebruik: bij session startup, voor teamcontext

3. **agent-tasks.json** — Lab Decision Board (open taken, proposals, status)
   Pad: `/home/agent/workspace/.openclaw/agent-tasks.json`
   Gebruik: bij vragen over taakverdeling, openstaande goedkeuringen

4. **Specifieke wiki-bestanden** — Als je het exacte pad kent
   Bijv.: `/home/agent/wiki/agents/daily/2026-04-23.md`
   Gebruik: als je weet wat je zoekt, lees dan direct (sneller dan qmd)

---

### SCHRIJVEN — ALTIJD IN DEZE VOLGORDE:

1. **Operationele data** (boekingen, matomo, reviews) → SQLite databases via Elon's scripts
2. **Agent-rapporten na taakafronding** → Wiki-vault via `llm-wiki__vault_write`
   Daarna automatisch naar OpenBrain via `store_openbrain` Lobster-stap
3. **Duurzame beslissingen** → `MEMORY.md` via memory-agent
4. **`store_report` nooit handmatig aanroepen** — de Lobster-pipeline roept dit automatisch aan via de `store_openbrain` stap. Agents schrijven naar de wiki-vault; de pipeline zorgt voor de OpenBrain-sync.
5. **NOOIT wiki-bestanden handmatig bewerken** als een pipeline ze beheert

---

### HYBRIDE AANPAK — beide systemen:

Als je zowel snelle semantische zoek ALS gedetailleerde bestandsinhoud nodig hebt:
  Stap 1 → search_reports of qmd__query voor oriëntatie (welke rapporten zijn relevant?)
  Stap 2 → Lees de gevonden bestanden direct voor de volledige inhoud
  Stap 3 → Combineer in je analyse
  Stap 4 → Bij tegenstrijdigheden: vertrouw het meest recente bestand, niet de samenvatting
```

---

# DOCUMENT 3 — IMPLEMENTATIEPLAN

## Per agent: welke wijzigingen zijn nodig in AGENTS.md?

### Muddy — Wijzigingen

**Huidige staat:** OpenBrain sectie aanwezig (PRD-v11). qmd-sectie aanwezig.

**Toe te voegen:**
1. **Beslisboom** bovenaan de kennissectie (zie routeringsregels hierboven)
2. **OpenBrain-waarschuwing** dat batch-ingest nog openstaand is — tot die compleet is, qmd gebruiken voor pre-2026-04-23 rapporten
3. **qmd-collecties tabel uitbreiden** met expliciete "wanneer welke collection" beschrijving (al gedeeltelijk aanwezig, aanvullen)

**Prioriteit:** Hoog — Muddy is de meest actieve agent die kennissystemen raadpleegt.

---

### Elon — Wijzigingen

**Huidige staat:** Geen OpenBrain sectie. Alleen schrijfinstructies.

**Toe te voegen:**
1. **OpenBrain sectie** — Elon hoeft niet te zoeken in rapporten (dat is Dario's taak), maar moet weten:
   - Dat zijn Lobster-pipelines automatisch naar OpenBrain schrijven
   - Dat hij historische sync-logs kan terugvinden via `list_reports(agent: "elon")`
2. **qmd sectie** — uitbreiden met alle 5 collections (nu ontbreekt dit volledig)

**Prioriteit:** Laag — Elon is primair producent, zelden lezer van kennissystemen.

---

### Dario — Wijzigingen (MEEST URGENT)

**Huidige staat:** qmd `agents-output` vermeld. OpenBrain volledig afwezig.

**Toe te voegen:**
1. **OpenBrain sectie** — volledig, met alle 5 tools
2. **Beslisboom** voor wiki-intelligence taken:
   - qmd → voor lokale semantische zoekopdrachten op recente bestanden
   - OpenBrain search_reports → voor cross-agent semantisch zoeken, wanneer je wilt weten wat het team als geheel heeft geconcludeerd
3. **qmd-collecties tabel** — alle 5 collections uitleggen (nu alleen `agents-output` aanwezig)
4. **Batch-ingest waarschuwing** — tot stap C compleet is, qmd is completere bron

**Prioriteit:** Kritiek — Dario doet wiki-intelligence en zoekopdrachten, juist deze agent heeft de meeste behoefte aan goede routing.

---

### Gary — Wijzigingen

**Huidige staat:** qmd `agents-output` vermeld. OpenBrain afwezig.

**Toe te voegen:**
1. **OpenBrain sectie** — primair gebruik: "vind eerdere Warren-rapporten vóór je eigen analyse start"
2. **qmd-collecties tabel** — alle 5 collections met "wanneer welke" uitleg
3. **Praktische voorbeelden** per collection die aansluiten bij Gary's werkzaamheden:
   - `agents-output`: "Zoek Warren's meest recente revenue-context vóór je booking-inzichten schrijft"
   - `wiki-planning`: "Check of er openstaande content-proposals zijn"
   - `logies-op-dreef-wiki`: "Haal merkwaarden en accommodatie-informatie op"

**Prioriteit:** Hoog — Gary's analyses worden versterkt door historische context van andere agents.

---

### Warren — Wijzigingen

**Huidige staat:** qmd `agents-output` vermeld. OpenBrain afwezig.

**Toe te voegen:**
1. **OpenBrain sectie** — primair gebruik: historische revenue-rapporten zoeken, vergelijken van kwartalen
2. **qmd-collecties tabel** — alle 5 collections
3. **Praktische voorbeelden** voor Warren's use case:
   - `agents-output`: "Zoek Gary's meest recente content-inzichten als achtergrondinformatie bij revenue-analyse"
   - `wiki-planning`: "Controleer of er Lab Board items zijn die revenue-impact hebben"

**Prioriteit:** Hoog.

---

### Memory Agent — Geen wijzigingen nodig

De Memory Agent heeft een beperkte enkelvoudige taak (schrijven naar MEMORY.md). Kennissysteem-routing is niet relevant voor deze agent.

---

## Technische Acties Vereist

### Actie 1: Batch-ingest uitvoeren (PRD-v11 stap C) — VEREIST VAN MICHIEL

Zonder de batch-ingest zijn OpenBrain-instructies misleidend. Voer eerst uit:

```bash
# Op de host, vanuit ~/openclaw-workspace:
cd ~/openclaw-workspace
export $(grep -v '^#' .env | xargs)
find ~/wiki-vault/output ~/wiki-vault/agents -name "*.md" | \
  python3 -c "
import json, sys
files = [l.strip() for l in sys.stdin if l.strip()]
print(json.dumps({'files': files, 'total': len(files)}))
" | python3 .openclaw/workspace/scripts/openbrain-batch-ingest.py
```

### ~~Actie 2: Elon's sync-pipelines uitbreiden~~ — vervalt

Elon's sync-output (Matomo-cijfers, VikBooking-snapshots, review-scores) zijn pure meetdata zonder redenering. OpenBrain is uitsluitend voor agent-rapportages en conclusies na pipeline-runs. Meetdata hoort in de operationele SQLite-databases, niet in OpenBrain.

### Actie 3: AGENTS.md bijwerken per agent

Volgorde op basis van prioriteit:
1. **Dario** — urgentst, wiki-intelligence agent zonder OpenBrain-routing
2. **Muddy** — beslisboom toevoegen, OpenBrain-waarschuwing (batch-ingest openstaand)
3. **Gary** — OpenBrain sectie + qmd-collecties tabel
4. **Warren** — OpenBrain sectie + qmd-collecties tabel
5. **Elon** — laagste prioriteit, minimale toevoeging

### Actie 4: Naleving monitoren

Na implementatie: controleer bij elke wekelijkse tech-audit (Dario) of agents qmd en OpenBrain correct gebruiken. Signalen van incorrect gebruik:
- Agent leest willekeurige wiki-bestanden handmatig terwijl qmd sneller is
- Agent roept `search_reports` aan maar stelt vast dat resultaten incompleet zijn (batch-ingest probleem)
- Agent schrijft handmatig naar wiki-bestanden die door een pipeline beheerd worden

---

## Samenvatting Hiaten & Aanbevelingen

| Hiaat | Ernst | Aanbeveling |
|-------|-------|-------------|
| ~~Batch-ingest OpenBrain openstaand~~ | ~~Kritiek~~ | ✅ Opgelost — 129 rapporten gesynchroniseerd |
| Dario mist volledige kennisrouting | Kritiek | AGENTS.md Dario bijwerken (deze sessie) |
| Gary + Warren missen OpenBrain | Hoog | AGENTS.md bijwerken na batch-ingest |
| Muddy mist beslisboom | Hoog | Beslisboom toevoegen aan AGENTS.md |
| Geen routing in Elon's AGENTS.md | Laag | Minimale sectie toevoegen |
| Elon's sync-logs in OpenBrain | Laag | ✅ Vervalt — pure meetdata (Matomo/VikBooking-cijfers) hoort niet in OpenBrain; OpenBrain is uitsluitend voor agent-rapportages en conclusies na pipeline-runs |
| ~~Dubbel langetermijngeheugen (MEMORY.md vs OpenBrain)~~ | ~~Laag~~ | ✅ Opgelost — expliciete scope-afbakening toegevoegd aan alle AGENTS.md's; taak-check ruis verwijderd uit Gary en Warren MEMORY.md |

---

## Concrete Tekst voor AGENTS.md — Herbruikbaar Blok

Het volgende blok kan direct worden ingevoegd in de AGENTS.md van Dario, Gary en Warren (na de bestaande qmd-sectie). Voor Muddy: aanvullen en beslisboom toevoegen.

```markdown
## Kennisrouting — qmd vs OpenBrain

### Wanneer qmd__query gebruiken

Gebruik qmd voor semantisch zoeken in de **lokale wiki-vault** (compleet, ook historisch).

| Collection | Gebruik voor |
|---|---|
| `agents-output` | Historische agent-rapporten, analyses, vorige beslissingen (actief ≤60d + archief) |
| `wiki-output` | Ad-hoc rapporten, onderzoeksresultaten, content analyses |
| `wiki-planning` | Lab Decision Board, projectstatus, open proposals |
| `logies-op-dreef` | Ruwe website-content, accommodatie-informatie, ervaringen |
| `logies-op-dreef-wiki` | Verwerkte kennisbank over het bedrijf |

```
qmd__query
  collection: "agents-output"
  searches: [{type: "vec", query: "jouw zoekvraag hier"}]
```

### Wanneer OpenBrain (openclaw-reports MCP) gebruiken

Gebruik OpenBrain voor **semantisch zoeken in agent-rapporten via cloud pgvector**.
Voordeel boven qmd: beter bij cross-agent vragen en grotere hoeveelheid rapporten.

| Tool | Gebruik |
|------|---------|
| `search_reports` | Semantisch zoeken: "warren revenue google conversie" |
| `list_reports` | Filteren op agent/type/datum: alle Dario audits Q1 |
| `get_report` | Ophalen op ID of wiki_path |
| `report_stats` | Statistieken per agent/type |

**Status:** OpenBrain volledig gesynchroniseerd — 129 rapporten beschikbaar via `search_reports`.

### Beslisregel

→ Weet je welke collection in qmd? → gebruik qmd (snelst, meest compleet)
→ Cross-agent semantische zoekvraag? → probeer ook search_reports
→ Rapport vóór 2026-04-23? → gebruik qmd of directe bestandslezing
→ Schrijven? → altijd via llm-wiki__vault_write (nooit direct naar OpenBrain)
```

---

*Rapport gegenereerd: 2026-04-23 | Analyse-agent: Claude Code sandbox | Omgeving: OpenClaw v2026.4.9*
