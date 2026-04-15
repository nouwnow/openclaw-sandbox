# OpenClaw Multi-Agent Audit — Resultaten
**Datum:** 2026-04-15  
**Workspace:** `/home/michiel/openclaw-workspace`  
**Versie:** OpenClaw 2026.4.9  
**Auditor:** Claude Code (claude-sonnet-4-6)

---

## 1. Orchestrator — Coördinatie

| # | Controlevraag | Status | Bevinding |
|---|---------------|--------|-----------|
| 1.1 | Is er één agent aangewezen als orchestrator die de workflow coördineert? | ✅ | **Muddy** (COO) is de centrale orchestrator, gedefinieerd in `SOUL.md` en `AGENTS.md`. Alle Discord-berichten gaan via binding naar Muddy. |
| 1.2 | Weet de orchestrator welke sub-agent op welk moment geactiveerd moet worden? | ✅ | `AGENTS.md` beschrijft expliciete rolverdeling: Elon=CTO/technical, Dario=analyst, Gary=CMO/content, Warren=CRO/revenue. Handoff via `sessions_spawn` en `sessions_send`. |
| 1.3 | Heeft de orchestrator toegang tot de huidige status van het workflow-proces? | ✅ | Muddy leest `agent-tasks.json` en `status-log.jsonl` via de `task-checker` pipeline (elke 30 min). `heartbeat` cron elke 55 min actief. |
| 1.4 | Is er een duidelijke handoff-logica tussen de orchestrator en de sub-agents? | ⚠️ | Handoff-mechanismen zijn gedocumenteerd (`sessions_spawn`, `sessions_send`, C-Suite Chat). **Maar:** directe activering vanuit de pipeline is uitgeschakeld wegens een WebSocket-deadlock (`task-checker.lobster` stap 2 is een pass-through). Sub-agents draaien nu op eigen cron-schedules. |

---

## 2. Sub-agents & Rolverdeling

| # | Controlevraag | Status | Bevinding |
|---|---------------|--------|-----------|
| 2.1 | Heeft elke sub-agent precies één specifieke taak (single responsibility)? | ✅ | Vijf gespecialiseerde agents met afgebakende rollen: Elon (technical/infra), Dario (analyse/audits), Gary (content/marketing), Warren (revenue/metrics), memory-agent (geheugen). Elk met eigen workspace. |
| 2.2 | Werken sub-agents in isolatie, of is er bewustzijn van elkaars acties? | ⚠️ | Agents werken in isolatie (eigen workspaces, `allowAgents: []`). Coördinatie via gedeeld async kanaal `c-suite-chat.jsonl` — bewustzijn is dus asynchroon en niet real-time. |
| 2.3 | Is er een duidelijke definitie van wat elke sub-agent wel en niet mag doen? | ✅ | `SOUL.md` bevat uitgebreide grenzen voor Muddy (geen scripts aanmaken, nooit publiceren zonder bevestiging, geen autonome acties op zaterdag). Agent-specifieke beperkingen via `subagents.allowAgents`. |

---

## 3. Workflow & Pipeline (Lobster)

| # | Controlevraag | Status | Bevinding |
|---|---------------|--------|-----------|
| 3.1 | Gebruik je Lobster als workflow-laag voor multi-step tool sequences? | ✅ | Lobster is actief als plugin (`plugins.entries.lobster.enabled: true`). 17 `.lobster` pipelines aanwezig, waaronder: `task-checker`, `daily-executive-sync`, `geheugen-extractie`, `matomo-weekly-sync`, `vikbooking-weekly-sync`, `wiki-*`. |
| 3.2 | Is de pipeline deterministisch: levert dezelfde input altijd dezelfde stappenvolgorde op? | ✅ | Pipelines zijn opgebouwd uit deterministische shell-stappen met expliciete `stdin:` ketens. LLM-stappen zijn geïsoleerd via `llm-invoke.py` met strikte prompts en JSON-output schema's. |
| 3.3 | Ondersteunt de pipeline resumable state — kan een gestopte workflow verder gaan waar hij gebleven was? | ❌ | Geen resumable state mechanisme gevonden. Bij een onderbreking herstart de cron job de volledige pipeline vanaf stap 1. Tijdelijke bestanden (bijv. `/tmp/exec-sync-agents.json`) bieden geen duurzame state-opslag. |
| 3.4 | Is er monitoring of logging van pipeline-status tijdens uitvoering? | ✅ | `write-status-log.py` wordt aangeroepen vanuit meerdere pipelines en schrijft naar `status-log.jsonl`. Status-log bevat bron, status (`ok`/`warning`/`error`) en timestamp per run. |

---

## 4. Artifacts & Completion Markers

| # | Controlevraag | Status | Bevinding |
|---|---------------|--------|-----------|
| 4.1 | Schrijft elke sub-agent een completion marker na het afronden van een stap? | ⚠️ | Pipelines als `daily-executive-sync` en `geheugen-extractie` schrijven naar wiki-vault en memory-bestanden als uitvoer. Maar er is geen uniform completion marker-formaat per sub-agent per stap — de ene schrijft naar `status-log.jsonl`, de andere naar Markdown-bestanden, zonder consistente conventie. |
| 4.2 | Leest de orchestrator deze markers vóór het starten van de volgende stap? | ⚠️ | `task-checker` leest `agent-tasks.json` (task-status) maar geen pipeline-specifieke completion markers. Cron jobs starten op tijdschema, niet conditioneel op basis van vorige run-output. |
| 4.3 | Wordt hiermee voorkomen dat dezelfde stap twee keer wordt uitgevoerd? | ⚠️ | `agent-tasks.json` voorkomt dubbele taakuitvoering via `status`-veld (`proposed → in_progress → done`). Pipeline-stappen zelf hebben geen deduplicatiemechanisme — dezelfde cron job kan identieke data ophalen en opslaan als de vorige run al slaagde. |

---

## 5. Approval Gates

| # | Controlevraag | Status | Bevinding |
|---|---------------|--------|-----------|
| 5.1 | Is er een approval gate vóór elke actie met een bijwerking? | ✅ | Lab-approval systeem aanwezig: tasks van type `lab-approval` in `tasks.json` vereisen expliciete goedkeuring door Michiel. Blogposts, WordPress-aanpassingen en skills worden niet uitgerold zonder `✅ goedgekeurd`. `SOUL.md`: "Nooit extern publiceren zonder bevestiging van Michiel." |
| 5.2 | Wordt de 30-seconden-regel gehanteerd? | ⚠️ | Approval gate bestaat voor grote acties. De 30-seconden-regel is echter niet expliciet gedocumenteerd als drempel — het is aan de interpretatie van Muddy of een actie een gate nodig heeft. Geen automatische classificatie van acties met bijwerkingen. |
| 5.3 | Kan een mens een gate-stap goedkeuren of afwijzen zonder de hele pipeline opnieuw te starten? | ✅ | Lab-goedkeuring verloopt via het dashboard of Discord, onafhankelijk van de pipeline. Afwijzing (`cancelled`) stopt alleen de specifieke taak, de pipeline zelf hoeft niet herstart te worden. |

---

## 6. Memory & Continuïteit

| # | Controlevraag | Status | Bevinding |
|---|---------------|--------|-----------|
| 6.1 | Schrijven agents een samenvatting naar persistent geheugen na elke significante actie? | ✅ | `geheugen-extractie` cron (dagelijks 07:00, ma-vr) schrijft naar `workspace-memory-agent/memory/YYYY-MM-DD.md` met beslissingen, patronen, afgeronde taken en strategische inzichten. Memory-flush is geconfigureerd bij compaction (`memoryFlush.enabled: true`). |
| 6.2 | Leest de orchestrator het geheugen aan het begin van elke nieuwe run? | ⚠️ | Memory-search is ingeschakeld (`memorySearch.enabled: true, provider: "gemini"`). Maar er is geen expliciete instructie in cron-payloads of bootstrap om het dagelijkse memory-bestand te lezen bij aanvang van elke run — dit verloopt impliciet via de compaction/memory-search laag. |
| 6.3 | Bevat het geheugen informatie over wat eerder geslaagd en wat mislukt is? | ✅ | Memory-bestanden (bijv. `2026-04-14.md`) bevatten: beslissingen, team-blokkades (P0-classificatie), afgeronde taken per agent, en infrastructuurproblemen met impact-inschatting (bijv. EUR 790/maand). |
| 6.4 | Herhaalt de pipeline werk dat eerder al succesvol afgerond werd? | ⚠️ | `task-checker` voorkomt het opnieuw opstarten van `done`-taken via status-check. Echter: data-sync pipelines (`matomo-weekly-sync`, `vikbooking-weekly-sync`) hebben geen idempotentiemechanisme — ze schrijven de huidige snapshot ongeacht of er al een identieke snapshot bestaat. |

---

## Scoreoverzicht

| Onderdeel | Aanwezig (✅) | Ontbreekt (❌) | Gedeeltelijk (⚠️) |
|-----------|:-----------:|:------------:|:----------------:|
| 1. Orchestrator (4 vragen) | 3 | 0 | 1 |
| 2. Sub-agents (3 vragen) | 2 | 0 | 1 |
| 3. Workflow / Lobster (4 vragen) | 2 | 1 | 1 |
| 4. Artifacts (3 vragen) | 0 | 0 | 3 |
| 5. Approval gates (3 vragen) | 2 | 0 | 1 |
| 6. Memory (4 vragen) | 2 | 0 | 2 |
| **Totaal (20 vragen)** | **11** | **1** | **8** |

**Score samenvatting:** 11 ✅ · 8 ⚠️ · 1 ❌ van de 20 controlecriteria.

---

## Verbeterplan

De setup is solide. Onderstaande prioritering weegt praktische impact en implementatie-inspanning — niet de ❌/⚠️-markering uit de audit.

> **Noot over resumable state (❌ 3.3):** Formeel een harde ❌, maar laagste praktische prioriteit. De meeste pipelines draaien < 2 minuten. Langere LLM-analysepipelines pauzeren al bij `approval: required`, wat de facto als checkpoint fungeert. Een volledig checkpoint-mechanisme over 17 pipelines toevoegen is veel werk voor weinig voordeel op deze schaal. Bij onderbreking herstart de pipeline — vervelend, maar niet destructief. Herclassificeerd als **nice-to-have**.

### Prioriteit 1 — Idempotentie data-syncs (⚠️ 4.3)
Kleine fix, concreet datakwaliteitsrisico. Als een cron job twee keer vuurt op dezelfde dag (na failure of timing conflict), schrijven `matomo-weekly-sync` en `vikbooking-weekly-sync` duplicate snapshots. Bij SQLite-inserts betekent dat mogelijk vervuilde tijdreeksen die queries verstoren.

- [ ] Voeg bovenin de fetch-stap van elke data-sync pipeline een guard toe: controleer of er al een record bestaat met de huidige datum — zo ja, sla de insert over en log `already_synced`
- [ ] Pas dit toe op: `matomo-weekly-sync.lobster`, `vikbooking-weekly-sync.lobster`

### Prioriteit 2 — 30-seconden-regel in SOUL.md (⚠️ 5.2)
Governance-win met lage inspanning. Approval gates bestaan en werken, maar het drempelcriterium is impliciet — afhankelijk van interpretatie door Muddy. Een concrete drempel is beter te handhaven dan een vage norm.

- [ ] Voeg aan `SOUL.md` een expliciete beslisregel toe: welke categorieën acties altijd een lab-approval vereisen (externe publicatie, database-mutaties, API-calls met bijwerkingen, acties die > 30 seconden kosten om terug te draaien)

### Prioriteit 3 — Memory bootstrap in cron-payloads (⚠️ 6.2)
Kwaliteitsverbetering voor LLM-analysestappen. Agents die via cron op een cold start draaien laden mogelijk niet de juiste geheugencontext. Memory-search is ingeschakeld maar niet expliciet geactiveerd in de payload.

- [ ] Voeg aan cron-payloads van analysepipelines (`daily-executive-sync`, `geheugen-extractie`, `dario-tech-audit`) een expliciete instructie toe om de meest recente memory-agent daily file te lezen als eerste stap

### Prioriteit 4 — WebSocket-deadlock documenteren (⚠️ 1.4)
Workaround vastleggen als dat voldoende is. De oorzaak is onvoldoende gedocumenteerd om een code-fix te rechtvaardigen — behandel dit als documentatietaak.

- [ ] Leg de workaround vast in de comments van `task-checker.lobster` stap 2 (huidige inline comment is voldoende basis, uitbreiden met datum en gateway-ticket-referentie)
- [ ] Voeg in `AGENTS.md` een notitie toe: sub-agents activeren op eigen cron-schedule, niet via pipeline-trigger — met de reden

### Prioriteit 5 — Resumable State (❌ 3.3) — nice-to-have
Pas relevant als pipelines structureel > 10 minuten gaan draaien of als LLM-kosten per run significant worden.

- [ ] Heroverwegen zodra er pipelines zijn die > 10 minuten duren of waarbij een herstart aantoonbare kosten of dataverlies veroorzaakt

---

## Sterke punten (niet in het verbeterplan)

- **Duidelijke agent-architectuur**: Muddy als COO met vijf gespecialiseerde sub-agents is goed uitgewerkt en gedocumenteerd
- **Lobster-adoptie**: 17 pipelines, volledig deterministisch, LLM-stappen via `llm-invoke.py` geïsoleerd
- **Approval gates**: Lab-approval systeem is functioneel en bewezen (blogposts, skills, API-taken)
- **Memory-kwaliteit**: Dagelijkse memory-bestanden zijn inhoudelijk rijk (P0-blokkades, EUR-impact, beslissingen)
- **Cron-rijkdom**: 21 cron jobs actief, goede coverage van dagelijkse en wekelijkse operaties
- **Status-logging**: `status-log.jsonl` biedt audit trail voor alle pipeline-runs

---

*Gebaseerd op het WRAM-framework: Workflow · Roles · Artifacts · Rules · Memory*

---

## Uitgevoerde verbeteringen — 2026-04-15

Alle vier uitvoerbare prioriteiten uit het verbeterplan zijn dezelfde dag geïmplementeerd.

### Prio 1 — Idempotentie data-syncs ✅
**Bestanden:** `matomo-weekly-sync.lobster`, `vikbooking-weekly-sync.lobster`

Beide `fetch_data`-stappen hebben een shell-level guard gekregen. Python controleert of `snapshots` al een record bevat met `fetched_at LIKE 'YYYY-MM-DD%'`. Bij match stopt de fetch-stap met `{"fetched": false, "reason": "already_synced"}`. Stappen 2 en 3 draaien gewoon door — zij lezen bestaande SQLite-data en overschrijven het wiki-bestand op hetzelfde pad (was al idempotent).

### Prio 2 — 30-seconden-regel in SOUL.md ✅
**Bestand:** `.openclaw/workspace/SOUL.md`

Nieuwe sectie "Wat altijd goedkeuring vereist" toegevoegd vóór het Escalatieprotocol. Vijf categorieën met concrete voorbeelden plus expliciete twijfelregel: bij twijfel → stop, board-entry, informeer Michiel.

### Prio 3 — Memory bootstrap in cron-payloads ✅
**Bestanden:** `jobs.json` (payloads van `daily-executive-sync`, `geheugen-extractie`, `dario-tech-audit-weekly`)

Drie cron-payloads bijgewerkt: elk begint nu met de instructie om het meest recente bestand in `workspace-memory-agent/memory/` te lezen vóór de pipeline start. JSON-structuur intact, 21 jobs operationeel.

### Prio 4 — WebSocket-deadlock documenteren ✅
**Bestanden:** `task-checker.lobster`, `AGENTS.md`

- `task-checker.lobster` stap 2: comment uitgebreid met oorzaak (gateway-sessie bezet WebSocket), datum (2026-04-15) en bijgewerkt cron-schema (10/14/17u).
- `AGENTS.md`: nieuwe sectie toegevoegd vóór `sessions_send` met de regel, de oorzaak en de correcte aanpak: board-entry → task-pickup cron.

### Prio 5 — Resumable State
Niet geïmplementeerd. Herclassificeerd als nice-to-have — zie toelichting in verbeterplan.
