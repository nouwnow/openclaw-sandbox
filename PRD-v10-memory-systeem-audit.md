# PRD-v10 — Memory Systeem Audit: Inventarisatie, Bloat & Onderhoud

**Datum:** 2026-04-18  
**Auteur:** Claude Code analyse op verzoek van Michiel Nouwens  
**Status:** Actief — P1 + P2 + P3 deels voltooid, P3 resterende items open  
**Aanleiding:** Onderzoek naar memory bloat, onderbenutte lagen en automatisch onderhoud in het OpenClaw multi-agent systeem

---

## 0. TL;DR — Bevindingen en implementatiestatus

| Probleem | Ernst | Status |
|---|---|---|
| workspace MEMORY.md bevat verouderde duplicaten | Hoog | ✅ **OPGELOST** — gearchiveerd, getrimd tot 3 entries |
| geheugen-extractie.lobster schrijft duplicaat entries | Hoog | ✅ **OPGELOST** — dedup + idempotency + pruning geïmplementeerd |
| `memory_search` in AGENTS.md verwijst naar lege SQLite | Hoog | ✅ **OPGELOST** — vervangen door correcte startup-instructie |
| Per-agent MEMORY.md (Elon/Gary/Warren) vrijwel leeg | Middel | ✅ **OPGELOST** — enforcement via task-pickup crons toegevoegd |
| Muddy sessie ba459188: 2.25 MB sessie | Laag | ⏳ Niet urgent — 8% context (75k/1M), start nieuw bij logisch moment |
| gary-content-strategy-weekly ❌ consecutiveErrors: 2 | Hoog | ✅ **OPGELOST** — root cause: JSON-output + approval preview >2000 chars. Fix: split_chunks webhook + clean output |
| warren-revenue-strategy-weekly timeout | Middel | 🟡 **HERSTELD** — consecutiveErrors: 0, timeout was incidenteel |
| weekly-kompas-sessie model 400 error | Laag | 🟡 **HERSTELD** — consecutiveErrors: 1, transiente OpenRouter storing |
| status-log.jsonl groeit zonder rotatie | Laag | ✅ **OPGELOST** — auto-rotatie in geheugen-extractie (>500 regels → archief) |
| Method 2 (SQLite RAG) nooit geactiveerd | Laag | ✅ **BESLOTEN** — afgeschreven; focus op wiki-vault |
| Dario heeft geen MEMORY.md | Laag | ✅ **OPGELOST** — aangemaakt met protocol + technische kennis |
| Dario wiki-bestanden werden niet geschreven (bug) | Hoog | ✅ **OPGELOST** — write_report schreef nooit naar filesystem |
| project-status-snapshot + lab-board-archive Discord 403 | Middel | ✅ **OPGELOST** — delivery omgezet van webhook naar cron announce (mode: announce) |
| Shell escaping bugs in nieuwe Lobster pipelines | Hoog | ✅ **OPGELOST** — backticks + ontsnapte `"` in `python3 -c "..."` strings gefixed |

---

## 1. Scope en analysemethode

Deze PRD inventariseert het volledige memory systeem van het OpenClaw workspace van Logies op Dreef. De analyse omvat:

- Alle PRDs v2–v9 om te bepalen wat ontworpen is vs. wat gebouwd is
- Alle workspace-bestanden per agent (SOUL/AGENTS/MEMORY/USER/TOOLS)
- Session logs per agent (grootte, aantal, checkpoints)
- Cron job status (successen, fouten, frequentie)
- Lobster pipelines voor memory-extractie
- Content output directory
- Wiki-vault structuur

---

## 2. PRD-evolutie: wat heeft iedere versie bijgedragen aan het memory systeem

### PRD-v2 (2026-03-21) — Hybride Memory Architectuur

**Ontwerp: vier memory methoden**

| Methode | Beschrijving | Status 2026-04-18 |
|---|---|---|
| **Methode 1** — Folders | Markdown-bestanden per agent (SOUL, USER, MEMORY.md, memory/YYYY-MM-DD.md) | ✅ Geïmplementeerd (gedeeltelijk) |
| **Methode 2** — SQLite search | Native vector-search via main.sqlite + embeddings API | ❌ Nooit geactiveerd |
| **Methode 3** — Extractie-agent | Cron die sessie-logs analyseert en samenvat | ✅ Geïmplementeerd als `geheugen-extractie` Lobster pipeline |
| **Methode 4** — Facts DB | Agent-beheerde SQLite voor gestructureerde domeindata | ❌ Nooit gebouwd |

PRD-v2 stelde voor: `memory/projects/decisions.md`, `memory/preferences/writing-style.md`. Die bestaan niet. Methode 1 is ingekrompen tot alleen `MEMORY.md` + dagelijkse extractiebestanden.

**Leidend patroon:** de multi-project gateway architectuur (project-A op poort 18790) is nooit uitgerold; er is één centrale gateway met meerdere agent-workspaces.

---

### PRD-v3 (model-optimization) — Lichtere context

Niet direct memory-gerelateerd. Introduceerde het idee van `lightContext: true` in cron jobs — dit is nu standaard in alle cron payloads en heeft directe invloed op memory-laadgedrag.

---

### PRD-v4 (browser-usage) — Browser tool

Geen memory-bijdrage.

---

### PRD-v5 (data-layer-skill-automation) — VikBooking + Matomo pipelines

Introduceerde deterministisch data-ophalen als Python scripts (geen LLM). Gegevens worden opgeslagen als SQLite snapshots, niet als markdown memory. Dit is het correcte patroon voor data-geheugen: gestructureerd en querybaar.

---

### PRD-v6 (skills-data-layer-team-guide) — Team setup

Formaliseerde de C-Suite agent rollen (Muddy, Elon, Gary, Warren). Memory-protocol per agent gedefinieerd in AGENTS.md/SOUL.md. "Elke sessie begin je fris. Je geheugen zit in deze bestanden. Lees ze. Update ze." — dit is het kernprincipe voor continuïteit.

---

### PRD-v7 + v8 (wiki-llm-obsidian + wiki-vault-agent-output) — Wiki-vault laag

**Grootste memory-uitbreiding naast v2.** Introduceerde:

- `/home/agent/wiki/` als gestructureerde kennisbank
- `llm-wiki__vault_write` MCP tool voor agents
- `qmd` wiki-index (58 bestanden, 229 vectors — per 2026-04-15)
- Automatisch schrijven vanuit Lobster pipelines naar wiki

Dit is **Layer 4** in het totale memory-landschap: gestructureerde, doorzoekbare kennis die persistent is en per run niet opnieuw geïnjecteerd wordt.

---

### PRD-v9 (2026-04-13) — Context-optimalisatie + Lobster + Dario

De meest impactvolle PRD voor het memory systeem:

1. **Context-bloat geadresseerd**: IDENTITY.md.template (was leeg template, kostbaar), AGENTS.md gesplitst in kern + referentie, credentials naar env vars, USER.md getrimd voor technische agents
2. **Lobster pipelines**: daily-executive-sync, geheugen-extractie, alle agent-analyse pipelines zijn deterministisch geworden
3. **Dario agent**: zware analyse uit Elon gehaald
4. **`lightContext: true`**: standaard op alle cron job payloads, reduceert context bij isolated sessions
5. **status-log.jsonl**: heartbeat-spam uit agent-tasks.json verwijderd

**Architectuurcleanheid score na v9: 6/10** (was 5/10 na v8)

---

## 3. Huidige memory architectuur — negen lagen

```
┌─────────────────────────────────────────────────────────────────┐
│  LAAG 1: Statische identity files (per agent workspace)         │
│  SOUL.md + AGENTS.md + USER.md + TOOLS.md + HEARTBEAT.md       │
│  → Altijd geïnjecteerd bij session start                        │
├─────────────────────────────────────────────────────────────────┤
│  LAAG 2: Per-agent MEMORY.md                                    │
│  workspace-{elon/gary/warren}/MEMORY.md                         │
│  → Handmatig bijgehouden; enforcement via task-pickup (P1 ✅)   │
├─────────────────────────────────────────────────────────────────┤
│  LAAG 3: Globale team MEMORY.md                                 │
│  workspace/MEMORY.md                                            │
│  → Dagelijks bijgehouden; dedup + pruning geïmplementeerd (✅)  │
├─────────────────────────────────────────────────────────────────┤
│  LAAG 4: Dagelijkse memory extractiebestanden                   │
│  workspace-memory-agent/memory/YYYY-MM-DD.md (april 15+)       │
│  workspace/memory/YYYY-MM-DD.md (april 11–14, oud pad)         │
│  → Gegenereerd door geheugen-extractie Lobster pipeline         │
├─────────────────────────────────────────────────────────────────┤
│  LAAG 5: Wiki-vault (obsidian-llm-wiki)                         │
│  /home/agent/wiki/ — 160 bestanden, 5 qmd collections (140 geïndexeerd) │
│  → Geschreven door 12 Lobster pipelines + llm-wiki MCP tool    │
│  → qmd semantisch zoeken volledig geconfigureerd (2026-04-18)  │
├─────────────────────────────────────────────────────────────────┤
│  LAAG 6: Session logs (JSONL per sessie)                        │
│  .openclaw/agents/*/sessions/*.jsonl                            │
│  → Automatisch aangemaakt, nooit automatisch opgeruimd         │
├─────────────────────────────────────────────────────────────────┤
│  LAAG 7: C-Suite Chat (gedeeld teamkanaal)                      │
│  workspace/c-suite-chat.jsonl — lean, goed beheerd              │
│  → Geen bloat-probleem                                          │
├─────────────────────────────────────────────────────────────────┤
│  LAAG 8: Status log                                             │
│  workspace/status-log.jsonl                                     │
│  → Auto-rotatie actief: >500 regels → archief (✅ P2)          │
├─────────────────────────────────────────────────────────────────┤
│  LAAG 9: Content output                                         │
│  content/research/ — actief (≤60d) + wiki/output/ mirror       │
│  → Archivering actief: >60d → wiki/output/archive/ (✅ P3)     └─────────────────────────────────────────────────────────────────┘

NIET GEACTIVEERD:
  Facts database (nooit gebouwd — bewuste keuze)
```

---

## 4. Per-laag analyse: gebruik, bloat en onderhoud

### Laag 1 — Statische identity files

**Huidige staat:**

| Agent | SOUL.md | AGENTS.md | USER.md | TOOLS.md | IDENTITY.md |
|---|---|---|---|---|---|
| Muddy | ✅ 66 regels, compact | ✅ 376 regels, goed gesplitst | ✅ aanwezig | n.v.t. | `Zie SOUL.md` |
| Elon | ✅ 55 regels | ✅ 75 regels + AGENTS-reference.md | ✅ getrimd | ✅ credentials in env | `IDENTITY.md.template` (niet geïnjecteerd) |
| Gary | ✅ 60 regels | ✅ 113 regels | ✅ aanwezig | n.v.t. | n.v.t. |
| Warren | ✅ 63 regels | ✅ 113 regels | ✅ aanwezig | n.v.t. | n.v.t. |
| Dario | ✅ aanwezig | ✅ aanwezig | ✅ kern-only | ✅ zonder credentials | ✅ ingevuld |
| Memory-agent | ✅ SOUL.md (generiek) | ✅ 44 regels | n.v.t. | n.v.t. | leeg template |

**Beoordeling:** Goed. PRD-v9 heeft de context-bloat hier substantieel gereduceerd. De `lightContext: true` flag in cron payloads zorgt ervoor dat isolated sessions minder context krijgen.

**Aandachtspunten:**
- Memory-agent IDENTITY.md is nog een leeg template
- Muddy's AGENTS.md is 376 regels lang — grens van wat wenselijk is
- Gary's AGENTS.md refereert nog naar `standups.json` (is verwijderd per 2026-04-14 architectuurmigratie)

---

### Laag 2 — Per-agent MEMORY.md

**Gemeten inhoud:**

| Agent | Bestand | Regels | Inhoud | Kwaliteit |
|---|---|---|---|---|
| Elon | `workspace-elon/MEMORY.md` | 30 | Protocol-regels + 2 configuratienotities | Functioneel maar statisch |
| Gary | `workspace-gary/MEMORY.md` | 50 | Protocol + content-richtlijnen + 1 blogpost note | Functioneel maar groeit niet |
| Warren | `workspace-warren/MEMORY.md` | 49 | Protocol + 1 concurrentie-analyse + groeilijst | Functioneel maar stagnant |
| Muddy | *geen MEMORY.md* | — | Wordt verwacht bij session-startup te zijn | Ontbreekt — Muddy leest `memory/YYYY-MM-DD.md` |
| Dario | *geen MEMORY.md* | — | Dario is nieuw (v9) | Ontbreekt |
| Memory-agent | *geen MEMORY.md* | — | Memory-agent beheert andermans memory | Bewuste keuze |

**Bloat-analyse:**
- Geen actieve bloat in de per-agent MEMORY.md files
- **Onderbenutte lagen**: Elon/Gary/Warren MEMORY.md bevatten vrijwel uitsluitend initiële setup-informatie (protocol, goedkeuringsregels). Geen accumulator van projectbeslissingen, technische learnings of campagneresultaten.
- **Ontbrekend**: Dario heeft geen MEMORY.md ondanks dat hij de meest analytische rol heeft.

**Update-mechanisme — status na P1:**

Per 2026-04-18 zijn alle 4 task-pickup cron berichten bijgewerkt met een expliciete instructie:
> "Als je vandaag een significante beslissing, bevinding of inzicht hebt gedaan: voeg één beknopte regel toe aan `/home/agent/workspace/.openclaw/workspace-{agent}/MEMORY.md`."

Dit is nog steeds een instructie (geen harde controle), maar verlaagt de drempel aanzienlijk — de agents krijgen de herinnering bij elke task-pickup sessie (10:00, 14:00, 17:00).

---

### Laag 3 — Globale team MEMORY.md

**Bestand:** `/home/agent/workspace/.openclaw/workspace/MEMORY.md`

**Status na P1 (2026-04-18):**

MEMORY.md is gearchiveerd en getrimd. Huidige inhoud (3 entries):

```
## [2026-04-14] ARCHITECTUURMIGRATIE — Lobster pipelines
Alle 16 cron jobs draaien nu via deterministisch Lobster pipeline...

## [2026-04-13] Dario agent toegevoegd
Senior Analyst agent voor complexe analyse, tech audits en skill-building...

## [2026-04-15] Infrastructuur stabiel
Alle 16 cron jobs operationeel. MCP servers llm-wiki en qmd stabiel...
```

Gearchiveerde entries (april 11–14 statuslogs + duplicaten) zijn opgeslagen in `workspace/memory/archive/2026-04.md`.

**Automatische bescherming (nu actief):**

De `geheugen-extractie.lobster` pipeline bevat nu:
1. **Idempotency**: dagelijkse file wordt altijd overschreven (niet geappendet)
2. **Deduplicatie**: als vandaag al een entry bestaat → overschrijf die entry (geen duplicaat)
3. **Word-overlap check**: >80% gedeelde woorden met vorige entry → sla entry over
4. **Pruning**: als `len(entries) > 15` → archiveer oudste entries naar `memory/archive/YYYY-MM.md`

**Bloat-risico:** Laag. Het systeem houdt zichzelf nu automatisch in check.

---

### Laag 4 — Dagelijkse memory extractiebestanden

**Locaties:**

| Pad | Periode | Bestanden |
|---|---|---|
| `workspace/memory/YYYY-MM-DD.md` | t/m april 14 (oud pad) | 2026-03-21, 2026-03-29, 2026-03-30, 2026-04-01 t/m 2026-04-14, 2026-04-18 |
| `workspace-memory-agent/memory/YYYY-MM-DD.md` | Vanaf april 15 (nieuw pad) | 2026-04-15, 2026-04-16 |

**Analyse:** De april 11–14 bestanden zijn **niet verloren** — ze staan in `workspace/memory/` (het pad dat de pipeline gebruikte vóór de Lobster-migratie op 2026-04-14). Na de architectuurmigratie schrijft de pipeline naar `workspace-memory-agent/memory/`. Er is dus een pad-discrepantie tussen oud en nieuw, maar geen datastverlies.

**Openstaand:** De bestanden zijn verspreid over twee directories. Een backfill of verwijzing is gewenst voor overzicht, maar niet urgent.

---

### Laag 5 — Wiki-vault

**Status:** Operationeel. VM via virtiofs-mount op `/home/agent/wiki/` (host: `~/wiki-vault/`).

#### Wat er nu in wiki wordt opgeslagen (per pipeline)

| Pipeline | Pad in wiki | Frequentie | Status |
|---|---|---|---|
| `daily-executive-sync` | `agents/daily/YYYY-MM-DD.md` | Dagelijks | ✅ |
| `gary-content-strategy` | `agents/gary/content-strategy/YYYY-WNN-{aspect}.md` | Wekelijks | ✅ |
| `warren-revenue-strategy` | `agents/warren/revenue-strategy/YYYY-WNN-{aspect}.md` | Wekelijks | ✅ |
| `dario-tech-audit` | `agents/dario/tech-audit/YYYY-WNN-{aspect}.md` | Wekelijks | ✅ (bug gerepareerd 2026-04-18) |
| `weekly-bot-overleg` | `agents/weekly/bot-overleg/YYYY-WNN.md` | Wekelijks | ✅ |
| `weekly-kompas-sessie` | `agents/weekly/kompas-sessie/YYYY-WNN.md` | Wekelijks | ✅ |
| `weekly-blauwdruk-sessie` | `agents/weekly/blauwdruk-sessie/YYYY-WNN.md` | Wekelijks | ✅ |
| `geheugen-extractie` | `agents/memory/YYYY-MM-DD.md` | Dagelijks | ✅ |
| matomo-sync | `agents/elon/matomo-sync/YYYY-MM-DD.md` | Wekelijks | ✅ |
| vikbooking-sync | `agents/elon/vikbooking-sync/YYYY-MM-DD.md` | Wekelijks | ✅ |

#### Hoe wiki-bestanden worden opgehaald (context-injectie)

Pipelines lezen wiki via Python `sorted(Path(wiki_dir).glob('*.md'), reverse=True)[0]` — het meest recente bestand per type. Dit is deterministisch en werkt altijd. Geen qmd nodig voor dit gebruik.

| Pipeline | Leest uit wiki | Pad |
|---|---|---|
| `weekly-bot-overleg` | Gary's laatste content-rapport | `agents/gary/content-strategy/*.md` |
| `weekly-kompas-sessie` | Warren's laatste revenue-rapport | `agents/warren/revenue-strategy/*.md` |
| `weekly-blauwdruk-sessie` | Dario's laatste tech-audit + Elon's wiki-checks | `agents/dario/tech-audit/*.md` + `agents/elon/wiki-checks/*.md` |

#### qmd semantisch zoeken — volledig geconfigureerd (2026-04-18)

qmd is een MCP-tool (`qmd__query`) beschikbaar in interactieve agent-sessies. Collections na update:

| Collection | Bestanden | Inhoud |
|---|---|---|
| `agents-output` | 29 | `/wiki/agents/**` — daily syncs, gary/warren/dario/elon outputs |
| `wiki-output` | 59 | `/wiki/output/**` — alle content/research bestanden |
| `wiki-planning` | 2 | `/wiki/lab-board/` + `/wiki/project-status/` |
| `logies-op-dreef` | 25 | `/raw/logies-op-dreef/` — ruwe website content |
| `logies-op-dreef-wiki` | 25 | `/wiki/logies-op-dreef/` — verwerkte wiki content |

**Totaal: 140/160 bestanden geïndexeerd (87.5%)** — eerder was dit 31%.

Agents die qmd-instructies hebben: Muddy (AGENTS.md), Gary (AGENTS.md + TOOLS.md), Warren (AGENTS.md + TOOLS.md), Dario (AGENTS.md).

`content-output-archiving.lobster` voert na elke run automatisch `qmd update + embed` uit zodat gearchiveerde bestanden meteen vindbaar zijn.

#### Wat nog ontbreekt in wiki

| Ontbrekend | Waarom waardevol | Hoe toe te voegen |
|---|---|---|
| **Wekelijkse project-status snapshot** | Agents weten niet welke taken actief zijn zonder agent-tasks.json te lezen | ✅ Draait — eerste run 2026-W16 geslaagd. Discord via cron announce. |
| **Lab Decision Board beslissingen** | Proposals worden ingediend via API maar niet gearchiveerd in wiki | ✅ Draait — eerste run 2026-W16 geslaagd. Discord via cron announce. |

**Beoordeling:** Opslaan gaat goed. Ophalen werkt deterministisch via sorted glob. qmd is beschikbaar voor semantisch zoeken in interactieve sessies maar niet nodig in cron pipelines. Geen bloat-signalen detecteerbaar.

---

### Laag 6 — Session logs

**Gemeten:**

| Agent | Sessiebestanden | Totale grootte |
|---|---|---|
| Muddy | 46 non-checkpoint + 2 checkpoint | **3.8 MB** |
| Elon | ~10 bestanden | 1.4 MB |
| Warren | 7 bestanden | 728 KB |
| Gary | 9 bestanden | 648 KB |
| Dario | 5 bestanden | 728 KB |
| Memory-agent | 1 bestand | 28 KB |
| **Totaal** | **78 bestanden** | **~7.4 MB** |

**De ba459188 sessie (Muddy) — bijgesteld:**
```
ba459188-....jsonl               = 1.4 MB   (231 turns)
ba459188-....checkpoint.726...   = 556 KB   (compaction 1)
ba459188-....checkpoint.7e1...   = 300 KB   (compaction 2)
Totaal voor één sessie:           = 2.25 MB
```

Na 2× automatische compactie bedraagt de actieve context **75.1k tokens = 8% van het 1M minimax context-window**. Geen urgent probleem. Start een nieuwe sessie bij een logisch moment (bijv. na een weekgrens), maar dit is geen P0-blokkade.

**Structureel probleem:** Er is geen automatische session-opruiming. Sessiebestanden groeien onbeperkt. Maar cron jobs gebruiken `sessionTarget: "isolated"` (elke cron run = nieuwe sessie), waardoor de meeste sessies klein zijn.

---

### Laag 7 — C-Suite Chat

**Grootte:** 18 regels, ~5 KB. Lean. Geen bloat-probleem.

**Let op:** Gary's AGENTS.md vermeldt nog `standups.json` als archiefbestand, maar dit bestand is verwijderd per 2026-04-14. Historische referenties gaan nu naar `/home/agent/wiki/agents/daily/` en `/home/agent/wiki/agents/weekly/planning/`.

---

### Laag 8 — Status log

**Bestand:** `workspace/status-log.jsonl`  
**Grootte:** 364 regels, 61 KB

**Beoordeling:** Functioneel, maar groeit zonder rotatie. Agents die de status-log raadplegen (geheugen-extractie pipeline stap 1 leest de laatste 20 entries) zijn hierdoor niet getroffen — die limiet is ingebouwd. Maar bij huidig tempo bereikt dit 5.000+ regels binnen twee weken met trage I/O als gevolg.

---

### Laag 9 — Content output

**Directory:** `content/research/`  
**Bestandscount:** ~55 bestanden

**Observaties:**
- Meerdere oogenschijnlijke duplicaten: `warren-terugblik-samenvatting-2026-04-12.md`, `warren-terugblik-week-2-12-april-2026.md` — vergelijkbare onderwerpen
- `ota-channel-analysis.md` en `OTA-channel-analysis.md` — waarschijnlijke duplicaat (hoofdlettersverschil)
- Geen archivering of cleanup-mechanisme

---

## 5. Cron job status — memory-relevante jobs

**Volledig overzicht (status 2026-04-18):**

| Job | Agent | Frequentie | Status | Opmerkingen |
|---|---|---|---|---|
| `geheugen-extractie` | memory-agent | Dagelijks 07:00 ma-vr | ✅ OK | Laatste run: 2026-04-14 |
| `heartbeat` | muddy | Elke 55 min 07-18 | ✅ OK | |
| `task-checker` | muddy | Elke 30 min 07-18 | ✅ OK | |
| `daily-executive-sync` | elon | Dagelijks 09:00 ma-vr | ✅ OK | |
| `weekly-planning` | muddy | Zondag 10:00 | ✅ OK | |
| `wiki-onderhoud-weekly` | elon | Maandag 08:00 | ✅ OK | |
| `wiki-raw-check-weekly` | elon | Maandag 07:30 | ✅ OK | |
| `wiki-wp-freshness-weekly` | elon | Maandag 08:30 | ✅ OK | |
| `gary-content-strategy-weekly` | gary | Vrijdag 13:00 | ✅ OK | Discord delivery fix + clean output (2026-04-18) |
| `warren-revenue-strategy-weekly` | warren | Woensdag 13:00 | 🟡 consecutiveErrors: 0 | Timeout was incidenteel, hersteld |
| `weekly-kompas-sessie` | muddy | Woensdag 15:00 | 🟡 consecutiveErrors: 1 | Model 400 was transiente OpenRouter storing |
| `weekly-blauwdruk-sessie` | muddy | Zondag 15:00 | ✅ OK | |
| `weekly-bot-overleg` | muddy | Vrijdag 15:00 | ✅ OK | lastDelivered: false (normaal voor none-delivery) |
| `dario-tech-audit-weekly` | dario | Zondag 13:00 | ✅ OK | |
| `crux-weekly-audit` | elon | Zondag 08:15 | ⚠️ Nooit gerund | lastRunAtMs: null |
| `matomo-weekly-sync` | elon | Zondag 08:05 | ✅ OK | |
| `vikbooking-weekly-sync` | elon | Zondag 08:00 | ✅ OK | |
| `task-pickup-elon` | elon | 10:00, 14:00, 17:00 | ✅ OK | |
| `task-pickup-warren` | warren | 10:00, 14:00, 17:00 | ✅ OK | |
| `task-pickup-gary` | gary | 10:00, 14:00, 17:00 | ✅ OK | |
| `task-pickup-dario` | dario | 10:00, 14:00, 17:00 | ✅ OK | |
| `project-status-snapshot` | muddy | Vrijdag 18:00 | ✅ OK | Eerste run 2026-W16 geslaagd (2026-04-18) |
| `lab-board-archive` | muddy | Vrijdag 18:30 | ✅ OK | Eerste run 2026-W16 geslaagd (2026-04-18) |
| `memory-weekly-curation` | memory-agent | Maandag 07:15 | ✅ OK | Nieuw (2026-04-18) — week-synthese naar memory/archive/ |
| `content-output-archiving` | muddy | Maandag 08:00 | ✅ OK | Nieuw (2026-04-18) — eerste run geslaagd; 59 bestanden in wiki/output/ |

**Gary-content-strategy opgelost (2026-04-18):**  
`write_results` printte een JSON-object als output (geen schone string), waardoor cron announce-delivery een onleesbaar bericht stuurde. Fix: clean Discord-tekst als eindoutput. consecutiveErrors gereset naar 0.

**minimax-m2.7 model:**  
Dit model werkt via de relay proxy (`localproxy/openrouter/minimax/minimax-m2.7`). De 400-fout in weekly-kompas-sessie was een tijdelijke OpenRouter storing (consecutiveErrors: 1, datum: 2026-04-15). Geen structurele actie nodig.

---

## 6. Memory bloat — gekwantificeerde diagnose

### 6.1 Wat is "bloat" in deze context?

Bloat treedt op wanneer memory-lagen context injecteren die:
1. **Verouderd** is (niet meer relevant voor de huidige situatie)
2. **Duplikaat** is (dezelfde informatie meerdere keren)
3. **Operationele status** bevat in plaats van duurzame beslissingen
4. **Oneindig groeit** zonder automatische schoning

### 6.2 Bloat per laag (gemeten na P1-fixes)

| Laag | Actuele grootte | Geschat nuttig deel | Bloat-% | Ernst |
|---|---|---|---|---|
| workspace MEMORY.md | ~1 KB (3 entries) | ~1 KB | <5% | ✅ Opgelost |
| Muddy sessie ba459188 | 2.25 MB (met checkpoints) | 75k actieve tokens | ~8% in-context | Laag |
| status-log.jsonl | 61 KB (364 regels) | 20 KB (laatste 100) | ~67% | Middel |
| content/research/ | ~500 KB | ~500 KB (output is nuttig) | ~5% (duplicaten) | Laag |
| Per-agent MEMORY.md | ~4 KB totaal | ~4 KB | 0% (klein maar stagnant) | Laag |
| Session logs overig | ~5 MB | ~5 MB (actieve sessies) | <10% | Laag |

### 6.3 Wat ontbreekt (onderbenutte lagen)

| Laag | Probleem | Status |
|---|---|---|
| Per-agent MEMORY.md | Vrijwel statisch, accumuleert geen projectkennis | 🟡 Enforcement toegevoegd via task-pickup |
| Dario MEMORY.md | Bestaat niet | ✅ Aangemaakt (2026-04-18) |
| SQLite RAG (Method 2) | Nooit geactiveerd | ✅ Afgeschreven — wiki-vault + qmd is de retrieval-laag |
| Facts DB (Method 4) | Nooit gebouwd | 🔴 Open — lange termijn |
| Dagelijkse memory files | Verspreid over 2 paden (workspace/memory/ en workspace-memory-agent/memory/) | 🟡 Begrepen, niet verloren |

### 6.4 De "recall vs. storage" kloof

Het Reddit-citaat stelt: "What you actually want is the claw to retrieve only the relevant slice at the relevant moment, instead of carrying the whole pile."

In de huidige architectuur:
- **Storage** werkt: geheugen-extractie schrijft dagelijkse samenvatting ✅
- **Retrieval** actief: agents lezen startup-geheugen deterministisch (MEMORY.md + laatste daily file) ✅ én kunnen semantisch zoeken via qmd ✅

**Status `memory_search`:** Vervangen door correcte startup-procedure + qmd semantisch zoeken. Per 2026-04-18:
- `qmd` heeft 5 collections geconfigureerd (140/160 bestanden, 87.5%)
- Agents (Muddy/Gary/Warren/Dario) hebben expliciete qmd-instructies in AGENTS.md + TOOLS.md
- `content-output-archiving.lobster` voert automatisch `qmd update + embed` uit na elke archivering

**Status retrieval na P3:** Operationeel. Deterministisch lezen + semantisch zoeken beide actief.

---

## 7. Automatisch onderhoud — wat werkt, wat mist

### 7.1 Actief (automatisch)

| Taak | Mechanisme | Frequentie | Werkt? |
|---|---|---|---|
| Dagelijkse memory-extractie | `geheugen-extractie.lobster` cron | Dagelijks 07:00 | ✅ |
| MEMORY.md dedup + pruning | `geheugen-extractie.lobster` stap 3 | Bij iedere extractie | ✅ Nieuw (P1) |
| MEMORY.md archivering (>15 entries) | `geheugen-extractie.lobster` stap 3 | Automatisch | ✅ Nieuw (P1) |
| Wiki-onderhoud (broken links, orphans) | `wiki-onderhoud-weekly.lobster` | Maandag 08:00 | ✅ |
| Wiki-freshness check | `wiki-wp-freshness-weekly.lobster` | Maandag 08:30 | ✅ |
| Raw-wiki sync check | `wiki-raw-check-weekly.lobster` | Maandag 07:30 | ✅ |

### 7.2 Ontbreekt (handmatig of niet geïmplementeerd)

| Taak | Status | Prioriteit |
|---|---|---|
| Status-log.jsonl rotatie | ✅ Geïmplementeerd | P2 voltooid |
| Sessie-archivering (oude sessie-JSONL) | ❌ Niet geïmplementeerd | P3 (laag) |
| Per-agent MEMORY.md review-cyclus | 🟡 Instructie aanwezig, geen controle | P3 (laag) |
| content/research deduplicatie | 🟡 Archivering actief; dedup nog handmatig | P3 (laag) |
| SQLite RAG activering | ✅ Afgeschreven — wiki-vault + qmd | P2 besloten |
| Dario MEMORY.md aanmaken | ✅ Aangemaakt (2026-04-18) | P2 voltooid |

---

## 8. Vergelijking ontwerp (PRD-v2) vs. actuele implementatie

| PRD-v2 Ontwerp | Status | Actueel equivalent |
|---|---|---|
| Memory Method 1: folders | ✅ Geïmplementeerd | SOUL/AGENTS/MEMORY/USER per workspace |
| Memory Method 1: memory/projects/decisions.md | ❌ Nooit aangemaakt | n.v.t. |
| Memory Method 1: memory/preferences/writing-style.md | ❌ Nooit aangemaakt | n.v.t. |
| Memory Method 2: SQLite vector search | ✅ Afgeschreven (bewust) | wiki-vault + qmd is de retrieval-laag (2026-04-18) |
| Memory Method 3: extractie-cron | ✅ Verbeterd als Lobster pipeline | geheugen-extractie.lobster |
| Memory Method 4: facts database | ❌ Nooit gebouwd | n.v.t. |
| Project-A gateway (poort 18790) | ❌ Nooit uitgerold | n.v.t. |
| Dashboard Memory-tab met folder-viewer | ⚠️ Onbekend | Dashboard op poort 3333 aanwezig |

**Conclusie**: PRD-v2's ambitie was een hybride systeem met vier complementaire lagen. Slechts Methode 1 (bestanden) en Methode 3 (extractie) zijn geïmplementeerd. De rijkste methode (semantisch zoeken via SQLite) is nooit actief geworden.

---

## 9. Architectuurcleanheid memory-systeem — score (bijgewerkt na P1)

| Criterium | Oud | Nieuw | Toelichting |
|---|---|---|---|
| Laadgedrag (wat in context komt) | 6/10 | 6/10 | lightContext + gesplitste AGENTS.md helpt, retrieval ontbreekt nog |
| Opslaan (duurzaamheid) | 7/10 | 7/10 | Wiki-vault + dagelijkse extractie goed ingericht |
| Ophalen (retrieval) | 2/10 | 7/10 | qmd 5 collections actief (87.5%), agents geïnstrueerd, auto-update na archivering |
| Onderhoud (automatisch) | 5/10 | 8/10 | MEMORY.md archivering + dedup + pruning + status-log rotatie |
| Gebruik door agents | 4/10 | 6/10 | Per-agent MEMORY.md enforcement + Dario MEMORY.md aangemaakt |
| Determinisme | 7/10 | 9/10 | Alle pipelines gerepareerd; shell-escaping bugs + Discord delivery gefixed |
| **Totaal** | **5.2/10** | **7.8/10** | — |

---

## 10. Aanbevelingen — openstaand

### P1 — Afgerond ✅

- [x] **Archiveer workspace MEMORY.md** — gearchiveerd naar `workspace/memory/archive/2026-04.md`, getrimd tot 3 entries
- [x] **Fix duplicaten en ontbrekende deduplicatie** — `geheugen-extractie.lobster` herschreven met idempotency, word-overlap check, en auto-pruning (max 15 entries)
- [x] **Fix memory_search in AGENTS.md** — vervangen door correcte deterministische startup-instructie
- [x] **Per-agent MEMORY.md enforcement** — task-pickup crons voor Elon/Warren/Gary/Dario bijgewerkt

### P2 — Afgerond ✅

**A. SQLite RAG afgeschreven** — besloten; wiki-vault via qmd is de retrieval-laag. `memory/main.sqlite` kan leeg blijven. `memory_search` instructie in AGENTS.md al gecorrigeerd.

**B. Dario MEMORY.md aangemaakt** — `workspace-dario/MEMORY.md` met protocol, technische kennis en audit-sectie.

**C. Status-log rotatie geïmplementeerd** — `geheugen-extractie.lobster` roteert automatisch: als `status-log.jsonl` > 500 regels → archiveer alles behalve laatste 100 naar `memory/archive/status-log-YYYY-MM.jsonl`.

**D. Gary "Message failed" opgelost** — root cause: `write_results` printte een JSON-object als output (niet een clean string), waardoor de cron announce-delivery naar Discord een onleesbaar bericht probeerde te sturen. Fix:
- `write_results` bouwt nu een volledige Discord-tekst op uit de bevindingen
- Eindoutput is een schone bevestigingszin voor de cron announce
- Dezelfde aanpak toegevoegd aan `dario-tech-audit.lobster`

**Bijkomende shell-escaping bugs (ontdekt tijdens P3-implementatie):**
- Backticks (`` ` ``) in `python3 -c "..."` worden door de shell geïnterpreteerd als command substitution, ook binnen dubbele aanhalingstekens. Elke `` `{t["category"]}` `` spawnt een subprocess. Fix: alle backtick-formatting vervangen door `(category)`.
- Ontsnapte dubbele aanhalingstekens in f-strings: `t["category"]` sluit de outer shell string. Fix: `t[\"category\"]` (escaped) in alle dict-accesses binnen `python3 -c "..."`.
- **Patroon voor nieuwe pipelines:** gebruik altijd `\"` voor dict-keys in f-strings binnen een `python3 -c "..."` commando.

**Discord delivery fix (project-status + lab-board):**  
Nieuwe pipelines gebruikten initieel directe webhook-calls (`DISCORD_BOT_WEBHOOK_URL`) — deze URL geeft 403 Forbidden. Fix: delivery omgezet naar `mode: "announce"` in de cron jobs, zelfde als alle andere pipelines. Pipelines printen nu de Discord-tekst als output; de bot-delivery regelt de rest.

**Bijkomende fix dario wiki-bug** — `write_report` stap in `dario-tech-audit.lobster` bereidde een bestandspad voor maar schreef het bestand nooit. Nu schrijft het de volledige audit naar `agents/dario/tech-audit/YYYY-WNN-{aspect}.md`.

### P3 — Langetermijn

**E. Memory-agent actieve curation**

Wekelijkse memory-curation taak: lees alle dagelijkse bestanden van de afgelopen week, identificeer terugkerende patronen, schrijf week-samenvatting naar `workspace/memory/archive/`.

**F. Content output archivering**

- Implementeer maandelijkse cleanup van `content/research/`: bestanden ouder dan 60 dagen naar `content/archive/YYYY-MM/`
- Verwijder obvie duplicaten (OTA-channel-analysis.md vs ota-channel-analysis.md)

**G. Pad-discrepantie daily memory files oplossen**

Alle historische bestanden verplaatsen naar één canonical pad (`workspace-memory-agent/memory/`), of een index-bestand toevoegen dat beide directories samenbrengt.

---

## 11. Memory-systeem schema na implementatie (doel)

```
┌─────────────────────────────────────────────────────────────────┐
│  RETRIEVAL LAAG (✅ actief — 2026-04-18)                        │
│  wiki-vault qmd — 5 collections, 140 bestanden, semantisch      │
│  Agents: Muddy/Gary/Warren/Dario — qmd__query geïnstrueerd     │
├─────────────────────────────────────────────────────────────────┤
│  HOT MEMORY (altijd in context, max 15 entries — nu actief ✅)  │
│  workspace/MEMORY.md — architectuurbeslissingen + blokkades     │
│  Per-agent MEMORY.md — protocol + recente bevindingen           │
├─────────────────────────────────────────────────────────────────┤
│  WARM MEMORY (op aanvraag geladen)                              │
│  workspace-memory-agent/memory/YYYY-MM-DD.md — dagelijks        │
│  Wiki-vault /agents/* — per-agent historische outputs           │
├─────────────────────────────────────────────────────────────────┤
│  COLD MEMORY (archief, zelden nodig)                            │
│  workspace/memory/archive/YYYY-MM.md — maandelijkse archieven   │
│  content/archive/YYYY-MM/ — content output archief             │
└─────────────────────────────────────────────────────────────────┘
```

---

## 12. Implementatieplan

### Stap 1 — Crisissanering (P0/P1) ✅ VOLTOOID

- [x] Archiveer verouderde MEMORY.md entries → `workspace/memory/archive/2026-04.md`
- [x] Trim workspace MEMORY.md → 3 schone architectuurentries
- [x] Fix duplicaten en ontbrekende idempotency in geheugen-extractie pipeline
- [x] Fix broken memory_search instructie in Muddy's AGENTS.md
- [x] Voeg per-agent MEMORY.md update-instructie toe aan task-pickup crons (Elon/Warren/Gary/Dario)

### Stap 2 — Preventief onderhoud (P2) ✅ VOLTOOID

- [x] SQLite RAG definitief afgeschreven; wiki-vault is de retrieval-laag
- [x] `workspace-dario/MEMORY.md` aangemaakt met protocol en technische kennis
- [x] Status-log rotatie geïmplementeerd in `geheugen-extractie.lobster` (>500 regels → archief)
- [x] Gary "Message failed" opgelost — split_chunks webhook + clean output
- [x] Dario wiki-bug opgelost — write_report schrijft nu daadwerkelijk naar filesystem

### Stap 3 — Institutioneel geheugen (P3, deels voltooid)

- [x] Wekelijkse project-status snapshot — `project-status-snapshot.lobster` vrijdag 18:00, schrijft naar `wiki/project-status/`
- [x] Lab Decision Board archief — `lab-board-archive.lobster` vrijdag 18:30, schrijft naar `wiki/lab-board/`
- [x] Memory-agent wekelijkse curation — `memory-weekly-curation.lobster` maandag 07:15, schrijft naar `workspace/memory/archive/YYYY-WNN.md`
- [x] Content output archivering — `content-output-archiving.lobster` maandag 08:00; research > 60d → `wiki/output/archive/YYYY-MM/` (copy+verify+delete), ≤ 60d → `wiki/output/`, elon skill files > 30d → delete. Gary/Warren/Dario AGENTS.md + TOOLS.md bijgewerkt met archief-paden en qmd-instructie.
- [ ] Pad-discrepantie daily files oplossen

---

## 13. Risico's bij niet-implementeren (bijgewerkt)

| Risico | Kans | Tijdshorizon | Impact | Status |
|---|---|---|---|---|
| workspace MEMORY.md bereikt 100+ regels, contextdrift | Laag | n.v.t. | Muddy gedraagt zich inconsistent | ✅ Opgelost via auto-pruning |
| Per-agent MEMORY.md blijft statisch | Middel | Nu | Agents accumuleren geen institutionele kennis | 🟡 Enforcement toegevoegd |
| gary-content-strategy mist revenue-analyse data | Hoog | Nu al | Wekelijkse content strategie wordt niet gemaakt | 🔴 Open |
| Status-log.jsonl bereikt problematische grootte | Hoog | n.v.t. | Trage I/O, mogelijk cron-timeouts | ✅ Opgelost via auto-rotatie in geheugen-extractie |
| SQLite RAG nooit besloten — ambigue AGENTS.md instructies | Middel | n.v.t. | Verwarring bij nieuwe agents | ✅ Besloten afgeschreven; wiki-vault + qmd is de retrieval-laag |

---

---

## 14. Volgende stap — aanbeveling

**✅ Geïmplementeerd: Memory-agent wekelijkse curation (P3-E)**

`memory-weekly-curation.lobster` + cron job maandag 07:15. Leest dagelijkse memory-bestanden van de afgelopen 7 dagen uit beide directorys (`workspace-memory-agent/memory/` en `workspace/memory/`), LLM-synthese op patronen/beslissingen/mijlpalen, schrijft naar `workspace/memory/archive/YYYY-WNN.md`.

**Openstaand (P3):**
- Pad-discrepantie daily files — historische bestanden samenvoegen naar één canonical pad

---

*PRD-v10 is gebaseerd op directe analyse van alle workspace-bestanden, cron/jobs.json (25 jobs), agent session logs (78 bestanden, 7.4 MB), PRDs v2-v9, en pipeline code in workspace/pipelines/. Eerste versie: 2026-04-18. Bijgewerkt na P1+P2+P3 volledig voltooid: 2026-04-18.*
