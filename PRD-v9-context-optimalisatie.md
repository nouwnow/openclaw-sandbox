# PRD-v9 — Context Optimalisatie + Dario Agent

**Datum:** 2026-04-13  
**Auteur:** Claude Code analyse op verzoek van Michiel Nouwens  
**Status:** ✅ Geïmplementeerd (2026-04-13)

---

## 1. Probleemstelling

Elke OpenClaw agent-sessie (inclusief subagents) ontvangt een systemprompt die statisch is samengesteld uit alle workspace-bestanden van die agent. De inhoud staat altijd volledig in context, ongeacht wat de taak is.

Dit leidt tot drie aantoonbare problemen:

1. **Context-bloat:** Een simpele taak als "geef een status update" ontvangt ~6.000–8.000 tokens aan ongerelateerde informatie.
2. **Tool distraction:** De aanwezigheid van WordPress-tools, login-procedures en curl-voorbeelden in de context activeert de agent om tools te gebruiken die niet nodig zijn (zie: subagent die een Core Web Vitals audit gaat uitvoeren terwijl de taak een statusrapportage was).
3. **Security-lek:** WordPress-inlogcredentials staan hardcoded in `TOOLS.md` en worden daarmee bij elke API-call meegestuurd, inclusief subagent-spawns voor taken die niks met WordPress te maken hebben.

---

## 2. Hoe de systemprompt is opgebouwd

### 2.1 Bronbestanden per agent (elon als voorbeeld)

Pad: `/home/michiel/openclaw-workspace/.openclaw/workspace-elon/`

| Bestand | Grootte | Inhoud | Altijd nodig? |
|---|---|---|---|
| `SOUL.md` | 1.447 bytes (~360 tokens) | Persona, karakter, communicatiestijl | Ja — stuur altijd mee |
| `AGENTS.md` | 5.243 bytes (~1.310 tokens) | Rol, communicatieprotocol, wiki-logging templates, Lab Decision Board JSON-schema, curl-voorbeelden | Deels — zie §3 |
| `TOOLS.md` | 2.413 bytes (~600 tokens) | Paden, WordPress-credentials in plaintext, login-procedure stap-voor-stap | Nee — credentials horen hier niet in |
| `USER.md` | 1.908 bytes (~475 tokens) | Gebruikersprofiel + accommodatietypes + merkwaarden | Deels — alleen profiel nodig |
| `IDENTITY.md` | 636 bytes (~160 tokens) | **Leeg template, nooit ingevuld** | Nooit |

**Subtotaal workspace-bestanden: ~2.905 tokens**

### 2.2 Skills-sectie in systemprompt

OpenClaw injecteert automatisch een `<available_skills>` XML-blok met alle skills uit de `skills/`-directory. Per skill worden `name`, `description` en `location` meegestuurd. De volledige `SKILL.md` bestanden worden **niet** geïnjecteerd — die laadt de agent on-demand via `read`.

| Skills in elon workspace | SKILL.md grootte |
|---|---|
| elon-tech-audit | 11.429 bytes |
| matomo-traffic | 11.467 bytes |
| skill-building | 16.660 bytes |
| vikbooking-bookings | 11.082 bytes |
| warren-revenue-analytics | 8.012 bytes |
| wiki-ingest | 2.861 bytes |
| wiki-onderhoud | 2.934 bytes |
| wiki-raw-check | 1.585 bytes |
| wiki-raw-transformeren | 3.501 bytes |
| wiki-wp-freshness | 1.417 bytes |
| wiki-zoek-antwoord | 2.911 bytes |

De `available_skills` metadata (name + description + location × 11 skills) bedraagt ~1.400 tokens.

### 2.3 OpenClaw systeem-header

De OpenClaw gateway injecteert zijn eigen system-instructies (tooling, safety, CLI-referentie, skills-instructie) bovenaan elke prompt. Geschatte omvang: ~1.500–2.000 tokens. Dit is niet configureerbaar via workspace-bestanden.

### 2.4 Subagent context

Bij spawning van een subagent voegt OpenClaw een `## Subagent Context`-blok toe met: subagent-regels, session keys, runtime-info. Geschat: ~500 tokens. De **taakbeschrijving staat hier dubbel** — zowel in dit blok als in het user message.

### 2.5 Totaal opgebouwde systemprompt (elon subagent)

| Component | Geschat tokens |
|---|---|
| OpenClaw gateway header | ~1.800 |
| SOUL.md | ~360 |
| AGENTS.md | ~1.310 |
| TOOLS.md (incl. credentials) | ~600 |
| USER.md | ~475 |
| IDENTITY.md (leeg) | ~160 |
| available_skills metadata (11 skills) | ~1.400 |
| Subagent context block | ~500 |
| **Totaal** | **~6.605 tokens** |

Voor een status-update taak is het minimaal benodigde: SOUL.md + subset van AGENTS.md = ~600 tokens.

**Overhead-ratio: ~11×** (6.600 / 600)

---

## 3. Analyse per bestand — wat kan weg of kleiner

### 3.1 IDENTITY.md — volledig verwijderen uit injectie

Het bestand is een leeg template met instructies voor de agent om het zelf in te vullen. Het is nooit ingevuld. Injectie hiervan geeft geen waarde en kost 160 tokens per sessie.

**Aanbeveling:** Sluit `IDENTITY.md` uit van workspace-injectie totdat het is ingevuld. Alternatief: verwijder het bestand; de persona zit al in `SOUL.md`.

### 3.2 USER.md — split in twee niveaus

`USER.md` bevat twee soorten informatie:
- **Werkstijl-instructies** (directe antwoorden, Zaterdag vrij, bevestiging vereist) — altijd relevant
- **Bedrijfsprofiel** (accommodatietypes, merkwaarden, adres) — alleen relevant voor content-agents (Gary), niet voor technische agents (Elon)

**Aanbeveling:** Maak `USER-core.md` (werkstijl, ~100 tokens) en `USER-business.md` (bedrijfsprofiel). Injecteer voor Elon alleen `USER-core.md`.

### 3.3 TOOLS.md — credentials eruit, bestand herstructureren

Twee problemen:
1. WordPress-credentials staan in plaintext in de context. Dit is een security-risico: elke LLM-call die gelogd of gecached wordt bevat het wachtwoord.
2. De gedetailleerde login-procedure (6 stappen) en curl-voorbeelden zijn alleen nodig wanneer de agent daadwerkelijk WordPress-tools gebruikt.

**Aanbeveling:**
- Verplaats credentials naar omgevingsvariabelen (`.env`-bestand of gateway-secrets). In `TOOLS.md` staat dan alleen: `WP_API_PASSWORD: zie $WP_API_PASSWORD env var`.
- Splits `TOOLS.md` in `TOOLS-paths.md` (altijd injecteren, ~100 tokens) en `TOOLS-wordpress.md` (alleen injecteren bij WordPress-gerelateerde taken).

### 3.4 AGENTS.md — herfactor naar kern + referentie

`AGENTS.md` bevat twee typen inhoud:
- **Gedragsregels** (harde regels, communicatieprotocol) — altijd nodig, ~400 tokens
- **Referentiedocumentatie** (Lab Decision Board JSON-schema, wiki-logging tabel, curl-voorbeelden) — alleen nodig bij die specifieke acties

Het Lab Decision Board JSON-schema wordt alleen gebruikt als de agent een nieuwe taak wil aanmaken. Het schema staat nu altijd in context, wat de agent aanmoedigt om het te gebruiken ook als de taak dat niet vereist.

**Aanbeveling:** Splits in `AGENTS-core.md` (gedrag + communicatie, ~400 tokens) en `AGENTS-reference.md` (schema's, voorbeelden). Injecteer alleen de core; de agent laadt de reference on-demand.

### 3.5 SOUL.md — prima, geen aanpassing nodig

Compact, functioneel, altijd relevant. Laat staan.

### 3.6 available_skills — reduceer beschrijvingen

De `description`-velden in de skill-metadata zijn soms lang (tot 200+ tekens per skill). Voor de skill-selector heeft de agent alleen nodig: naam + korte één-zin beschrijving. Langere beschrijvingen zijn nuttig in de SKILL.md zelf, niet in de selector.

**Aanbeveling:** Voeg een `short_description`-veld toe aan SKILL.md frontmatter (max 60 tekens), gebruik dat voor de `available_skills` injectie. Schatting: reductie van ~1.400 naar ~600 tokens voor de skills-sectie.

---

## 4. Structureel verbetervoorstel

### 4.1 Kortetermijn — bestandswijzigingen (vandaag uitvoerbaar)

**Actie 1: Verwijder IDENTITY.md uit workspace of vul het in**
- Bestand: `workspace-elon/IDENTITY.md`
- Als het platform geen optie biedt om bestanden uit te sluiten: hernoem naar `IDENTITY.md.template` zodat OpenClaw het niet oppikt

**Actie 2: Haal credentials uit TOOLS.md**
- Vervang plaintext wachtwoord in `TOOLS.md` door: `$WP_API_PASSWORD` (env var verwijzing)
- Sla het wachtwoord op via `openclaw gateway secrets` of `.env`-bestand
- **Dit is de meest urgente actie vanuit security-perspectief**

**Actie 3: Trim USER.md voor technische agents**
- Verwijder de "Accommodatievormen" tabel en "Merkwaarden" sectie uit `workspace-elon/USER.md`
- Die informatie is relevant voor Gary (content) en Warren (revenue), niet voor Elon (technisch)

**Actie 4: Verplaats Lab Decision Board schema uit AGENTS.md**
- Maak `workspace-elon/AGENTS-reference.md` aan met het JSON-schema en de werkwijze
- Voeg in `AGENTS.md` een verwijzing toe: "Zie AGENTS-reference.md voor het schema"
- Reductie: ~600 tokens minder altijd in context

### 4.2 Middellange termijn — OpenClaw configuratie

Controleer of OpenClaw `bootstrapMaxChars` of vergelijkbare opties ondersteunt om workspace-bestanden selectief in te sluiten. In `openclaw.json` staat al:

```json
"bootstrapMaxChars": 20000,
"bootstrapTotalMaxChars": 80000
```

Dit is een karakter-cap, geen selectie-mechanisme. Mogelijke uitbreidingen via issue/feature request bij OpenClaw:

1. **`workspace.include` list** — whitelist van bestanden per agent die altijd geladen worden
2. **`workspace.taskContext`** — optionele bestanden die alleen geladen worden bij specifieke taaktypen
3. **Skill-level `contextFiles`** — SKILL.md kan aangeven welke bestanden het nodig heeft; die worden geladen bij skill-activatie

### 4.3 Langetermijn — taak-gebaseerde context injectie

Het fundamentele probleem is dat context statisch is. De optimale architectuur is:

```
Basis-context (altijd):
  SOUL.md + AGENTS-core.md + USER-core.md + TOOLS-paths.md
  = ~800 tokens

Taak-context (op basis van taaktype):
  Status update → geen extra bestanden
  WordPress audit → TOOLS-wordpress.md + skills/elon-tech-audit/
  Analytics query → skills/matomo-traffic/ of skills/vikbooking-bookings/
  Wiki-taken → skills/wiki-*/
  Nieuwe taak aanmaken → AGENTS-reference.md
```

Dit vereist dat de task description of subagent-spawn metadata een `taskType`-veld meekrijgt dat OpenClaw gebruikt om context te selecteren.

---

## 5. Prioriteitenmatrix

| Actie | Impact | Effort | Prioriteit |
|---|---|---|---|
| Credentials uit TOOLS.md halen | Hoog (security) | Laag | **P0 — direct** |
| IDENTITY.md uitsluiten/verwijderen | Laag | Laag | P2 |
| USER.md trimmen voor Elon | Middel | Laag | P1 |
| AGENTS.md splitsen (kern + reference) | Middel | Laag | P1 |
| Skill-descriptions inkorten in frontmatter | Laag | Middel | P2 |
| Taak-gebaseerde context injectie (platform) | Hoog | Hoog | P3 — langetermijn |

---

## 6. Verwachte resultaten na P0+P1 acties

| Component | Huidig | Na optimalisatie |
|---|---|---|
| SOUL.md | ~360 tokens | ~360 tokens (ongewijzigd) |
| AGENTS.md → core only | ~1.310 tokens | ~400 tokens |
| TOOLS.md → paths only | ~600 tokens | ~100 tokens |
| USER.md → core only | ~475 tokens | ~120 tokens |
| IDENTITY.md | ~160 tokens | **0 tokens** |
| available_skills | ~1.400 tokens | ~1.400 tokens (ongewijzigd voor nu) |
| OpenClaw header | ~1.800 tokens | ~1.800 tokens (niet configureerbaar) |
| Subagent context | ~500 tokens | ~500 tokens (niet configureerbaar) |
| **Totaal** | **~6.605 tokens** | **~4.680 tokens** |

Reductie: **~30% minder tokens** met alleen de bestandswijzigingen. Zonder IDENTITY en met getrimde AGENTS/TOOLS/USER.

De werkelijke reductie in "nutteloze" context is groter: de verwijderde inhoud (schema's, credentials, accommodatietypes) had de laagste relevantie voor de meeste taken.

---

## 7. Implementatiestappen (concreet)

### Stap 1 — Credential-isolatie (P0)

```bash
# In TOOLS.md — vervang:
# | Wachtwoord | `JO&uHSGyOVs(d&#W` |
# Door:
# | Wachtwoord | zie env var $WP_API_PASSWORD |

# Sla het wachtwoord op buiten de workspace (bijv. via openclaw gateway config of .env)
```

Bestand aanpassen: `workspace-elon/TOOLS.md` — verwijder de plaintext `JO&uHSGyOVs(d&#W)` uit de tabel.

### Stap 2 — IDENTITY.md deactiveren (P2)

```bash
# Optie A: hernoem het bestand (als OpenClaw niet pikt op .template extensie)
mv workspace-elon/IDENTITY.md workspace-elon/IDENTITY.md.template

# Optie B: vul het in zodat het minimale maar geldige inhoud heeft
```

### Stap 3 — USER.md trimmen voor Elon (P1)

Verwijder uit `workspace-elon/USER.md`:
- De "Accommodatievormen" tabel
- De "Merkwaarden" sectie
- Bewaar: naam, rol, werkstijl, tijdzone, talen

### Stap 4 — AGENTS.md schema's verplaatsen (P1)

Maak `workspace-elon/AGENTS-reference.md` aan met:
- Lab Decision Board JSON-schema
- Wiki-vault logging tabel
- Status-update workflow

Vervang in `AGENTS.md` die secties door een verwijzing: "Zie AGENTS-reference.md voor schema's en procedures."

---

## 8. Risico's en mitigaties

| Risico | Kans | Impact | Mitigatie |
|---|---|---|---|
| Agent vindt credentials niet meer | Middel | Middel | Test na migratie; zorg dat env var beschikbaar is in agent container |
| Agent mist schema en maakt verkeerde task JSON | Laag | Laag | Schema staat nog in reference-bestand, agent kan het on-demand lezen |
| Andere agents (Gary/Warren) ook getrimd nodig | Middel | Laag | USER.md aanpassing is agent-specifiek; Gary behoudt bedrijfsprofiel |
| OpenClaw pikt IDENTITY.md.template toch op | Laag | Laag | In dat geval bestand volledig verwijderen |

---

*Deel 1 gebaseerd op directe inspectie van `/home/michiel/openclaw-workspace/.openclaw/workspace-elon/` en de systemprompt zoals geobserveerd in een actieve subagent-sessie van 2026-04-13.*

---

## Implementatielogboek (2026-04-13)

### Uitgevoerde wijzigingen

#### Nieuwe agent: Dario (Senior Analyst)

| Bestand | Actie |
|---|---|
| `.openclaw/workspace-dario/SOUL.md` | Aangemaakt — Dario's persona, methodisch + grondig |
| `.openclaw/workspace-dario/IDENTITY.md` | Aangemaakt — ingevuld (niet leeg template) |
| `.openclaw/workspace-dario/AGENTS.md` | Aangemaakt — scope: audit, wiki-intelligence, skill-building |
| `.openclaw/workspace-dario/AGENTS-reference.md` | Aangemaakt — Lab Decision Board schema |
| `.openclaw/workspace-dario/TOOLS.md` | Aangemaakt — zonder plaintext credentials |
| `.openclaw/workspace-dario/USER.md` | Aangemaakt — core-only (werkstijl, geen bedrijfsprofiel) |
| `.openclaw/workspace-dario/.openclaw/workspace-state.json` | Aangemaakt |
| `.openclaw/agents/dario/agent/models.json` | Aangemaakt — claude-sonnet-4-6 via localproxy |
| `.openclaw/agents/dario/sessions/sessions.json` | Aangemaakt |

**Dario's skills (verplaatst van Elon):**

| Skill | Van | Naar |
|---|---|---|
| elon-tech-audit | `workspace-elon/skills/` | `workspace-dario/skills/dario-tech-audit/` |
| skill-building | `workspace-elon/skills/` | `workspace-dario/skills/skill-building/` |
| wiki-raw-transformeren | `workspace-elon/skills/` | `workspace-dario/skills/wiki-raw-transformeren/` |
| wiki-zoek-antwoord | `workspace-elon/skills/` | `workspace-dario/skills/wiki-zoek-antwoord/` |
| wiki-ingest | `workspace-elon/skills/` | `workspace-dario/skills/wiki-ingest/` |

#### Elon opgeschoond (Deel 1 fixes)

| Bestand | Wijziging |
|---|---|
| `workspace-elon/TOOLS.md` | Plaintext wachtwoord verwijderd → verwijst naar `$WP_API_PASSWORD` env var |
| `workspace-elon/USER.md` | Accommodatietypes en merkwaarden verwijderd — alleen werkstijl |
| `workspace-elon/AGENTS.md` | Lab Decision Board schema verwijderd → AGENTS-reference.md |
| `workspace-elon/AGENTS-reference.md` | Nieuw — schema + wiki-logging tabel |
| `workspace-elon/IDENTITY.md` | Hernoemd naar `IDENTITY.md.template` (niet meer geïnjecteerd) |

**Elon's skills na opschoning (6 → 6, maar de juiste 6):**
matomo-traffic, vikbooking-bookings, warren-revenue-analytics, wiki-onderhoud, wiki-raw-check, wiki-wp-freshness

#### openclaw.json

| Wijziging | Detail |
|---|---|
| Model toegevoegd | `localproxy/claude-sonnet-4-6` in providers.localproxy.models |
| Agent toegevoegd | `dario` met `model: localproxy/claude-sonnet-4-6`, `thinking: medium`, `timeoutSeconds: 600` |
| Muddy allowAgents | `dario` toegevoegd |
| Elon allowAgents | `skill-manager` (niet-bestaand) verwijderd |

#### cron/jobs.json

| Job | Wijziging |
|---|---|
| `elon-tech-audit-weekly` | agentId: elon → dario; skill: elon-tech-audit → dario-tech-audit; model: localproxy/claude-sonnet-4-6; timeout: 600s |

---

### Context-grootte Elon voor/na

| Component | Voor | Na |
|---|---|---|
| AGENTS.md | ~1.310 tokens | ~650 tokens |
| TOOLS.md (incl. credentials) | ~600 tokens | ~400 tokens |
| USER.md | ~475 tokens | ~100 tokens |
| IDENTITY.md (leeg template) | ~160 tokens | **0 tokens** |
| available_skills (11 skills) | ~1.400 tokens | ~600 tokens (6 skills) |
| **Subtotaal workspace** | **~3.945 tokens** | **~1.750 tokens** |
| **Reductie** | | **~56%** |

### Beantwoording vraag: helpt Dario?

Ja, op drie manieren:

1. **Kwaliteit van analyse**: Sonnet 4.6 heeft betere redeneervaardigheden dan de `auto`-proxy voor de tech audit (wekelijkse rotaterende analyse van WordPress, security headers, database-integriteit). De audit kost momenteel 278 seconden bij Elon — met Dario's 600s timeout en betere context is er ruimte voor grondiger werk.

2. **Elon's context en focus**: Elon heeft nu 6 scriptgedreven skills (geen wiki-intelligence, geen meta-skill-building). Zijn systemprompt is ~56% kleiner. Tool distraction is gereduceerd.

3. **Parallelle capaciteit**: Elon en Dario kunnen gelijktijdig draaien. Zondagmiddag: Dario doet de tech audit (14:00, 600s budget) terwijl Elon maandagochtend data-sync draait. Geen concurrentie om resources.

**Wat Dario NIET oplost:**
- De fundamentele workflow-determinisme problemen (zie Deel 2 §11 t/m §13)
- Vrije-tekst handoffs tussen agents
- Task-checker heartbeat spam in agent-tasks.json
- Ontbrekende JSON-schema's voor StatusUpdate

Die vereisen de Lobster-pipeline refactoring uit het refactoring plan (Deel 2 §14).

---

## Deel 2 — Architectuuranalyse op basis van Structured Pipelines Doctrine

*Analysekader: Hiërarchie van Succes, Determinisme met Lobster, Anti-Patterns.*  
*Bronnen: cron/jobs.json, flows/registry.sqlite (136 runs), agents/*/sessions/, workspace/agent-tasks.json, c-suite-chat.jsonl, alle SKILL.md bestanden.*

---

### Architecturale Reinheid Score: **5 / 10**

| Criterium | Score | Toelichting |
|---|---|---|
| Rol-Isolatie | 6/10 | Rollen zijn benoemd maar Elon is near-mega-agent; Muddy mist artifact-boundary |
| Artifact Kwaliteit | 4/10 | Tasks JSON goed; subagent handoffs zijn ongestructureerde vrije tekst |
| Determinisme | 3/10 | Nul Lobster pipelines; data-sync scripts zijn de enige deterministische stappen |
| Geheugen-gebruik | 5/10 | Memory-agent is goed geïsoleerd maar wordt als statuslog misbruikt |

---

### 9. Bevinding 1 — Rol-Isolatie: Elon is een Near-Mega-Agent

**Wat gevonden:**

Elon heeft 11 skills. Van die 11 zijn er 6 wiki-gerelateerd:

| Skill | Domein | Past bij wie? |
|---|---|---|
| wiki-ingest | Content/infrastructuur | Aparte wiki-agent of Gary |
| wiki-onderhoud | Content/infrastructuur | Aparte wiki-agent |
| wiki-raw-check | Content/infrastructuur | Aparte wiki-agent |
| wiki-raw-transformeren | Content/infrastructuur | Aparte wiki-agent |
| wiki-zoek-antwoord | Content/kennisbank | Gary |
| wiki-wp-freshness | WordPress monitoring | Elon ✓ (technisch) |
| elon-tech-audit | Technisch ✓ | Elon |
| matomo-traffic | Data-sync ✓ | Elon |
| vikbooking-bookings | Data-sync ✓ | Elon |
| skill-building | Meta/architectuur ✓ | Elon |
| warren-revenue-analytics | Revenue data | Warren/Elon grens |

Elons scope is in één zin niet te beschrijven: hij doet technische audits, WordPress monitoring, data-sync, wiki-onderhoud én wiki-ingest. Dit is het anti-pattern: **"als een rol niet in één zin te beschrijven is, is de architectuur kapot."**

**Impact:**  
- 6 van 11 skills in de `available_skills` context zijn wiki-taken die in de dagelijkse technische werklast niet relevant zijn
- Wiki-onderhoud jobs (wiki-raw-check, wiki-onderhoud, wiki-wp-freshness) zijn cron-triggered op maandag en blokkeren door MCP-afhankelijkheid — een technisch probleem dat Elon's scope verder verwart
- Warren heeft ook toegang gekregen tot `warren-revenue-analytics` als cross-workspace skill — goed, maar het is een Elon-skill die via gedeelde workspace beschikbaar is. De skill-eigendom is onduidelijk.

**Aanbeveling:**  
Splits wiki-taken naar een `wiki-agent` of hevel ze over naar Gary's scope. Elon's skills reduceren naar: elon-tech-audit, matomo-traffic, vikbooking-bookings, wiki-wp-freshness (WordPress-technisch), skill-building. Dat is 5 duidelijk technische skills.

---

### 10. Bevinding 2 — Artifact Kwaliteit: Handoffs zijn vrije tekst

**Wat gevonden:**

De dagelijkse executive sync (cron: `daily-executive-sync`, elke werkdag 08:30) stuurt Muddy met dit bericht naar subagents:

> *"Start de dagelijkse executive sync. Vraag status updates van Elon, Gary en Warren."*

Muddy spawnt vervolgens Elon met een task-prompt als:

> *"Geef je dagelijkse status update voor maandag 13 april 2026. Wat heb je gedaan sinds de laatste meeting, wat doe je vandaag, wat blokkeert je? Focus op: MCP servers herstel, VikBooking database schema fix, cron job errors oplossen..."*

Elon antwoordt met vrije tekst. Muddy synthetiseert dat naar Discord.

Geen contract. Geen JSON. Geen gedefinieerde velden. De inhoud varieert per run.

**Bewijs van het probleem:**  
In `c-suite-chat.jsonl` staan Elon's status updates als vrije tekst in het `message`-veld. Muddy moet dit interpreteren voor de volgende stap. Bij timeouts (3 van 16 cron jobs gefaald) weet Muddy niet welk deel van de status ontbreekt.

**Gewenst contract (voorbeeld):**
```json
{
  "agent": "elon",
  "date": "2026-04-13",
  "since_last": ["Technische blokkades analyse", "Lab Board sync"],
  "today": ["MCP servers herstel", "VikBooking schema fix"],
  "blockers": [
    {"item": "Gateway stability", "priority": "P0"},
    {"item": "MCP servers offline", "priority": "P0"}
  ],
  "tasks_updated": ["technical-priorities-week-15-001"]
}
```

**Aanbeveling:**  
Definieer een `StatusUpdate` JSON-schema. Cron-payload stuurt dit schema mee als verwacht output-format. Muddy ontvangt gestructureerde JSON en aggregeert deterministisch.

---

### 11. Bevinding 3 — Determinisme: Nul Lobster Pipelines

**Wat gevonden:**

Er zijn 136 `flow_runs` in `flows/registry.sqlite`. Na inspectie blijken dit **memory-write flows** te zijn (schrijven naar MEMORY.md via de memory-agent), geen multi-step Lobster pipelines.

Er zijn **geen `.lobster` bestanden** aangetroffen in de hele workspace.

De wekelijkse workflows zijn volledig LLM-over-LLM:

```
Cron trigger
  → Muddy (LLM): "Start de dagelijkse executive sync"
    → Muddy besluit zelf welke subagents te spawnen
    → Elon (LLM): "Wat heb je gedaan?" → vrije tekst antwoord
    → Warren (LLM): "Wat heb je gedaan?" → vrije tekst antwoord  
    → Gary (LLM): "Wat heb je gedaan?" → vrije tekst antwoord
  → Muddy (LLM): synthetiseert vrije tekst → Discord bericht
```

Elke stap is non-deterministisch. De inhoud van de Discord-output verschilt per run, zelfs als de werkelijkheid gelijk is.

**Vergelijking met de script-driven data jobs (het goede voorbeeld):**

De matomo-weekly-sync en vikbooking-weekly-sync cron jobs gebruiken een directe Python script:

```
Cron trigger
  → python3 fetch_matomo.py  (deterministisch)
  → SQLite snapshot opgeslagen  (deterministisch)
  → Elon: "rapporteer het aantal snapshots"  (minimale LLM stap)
```

Dit is de correcte aanpak: deterministisch data ophalen, minimale LLM interpretatie voor rapportage.

**Wat een Lobster-pipeline zou verbeteren:**

De `daily-executive-sync` als Lobster flow:

```
Stap 1 (deterministisch): Lees agent-tasks.json → filter status=in_progress
Stap 2 (deterministisch): Lees c-suite-chat.jsonl → laatste 24h berichten per agent
Stap 3 (LLM Task): Genereer StatusUpdate JSON per agent op basis van task-data
Stap 4 (deterministisch): Aggregeer StatusUpdate JSONs → Discord bericht template
Stap 5 (optioneel human gate): Michiel keurt Discord bericht goed vóór publicatie
```

Stap 3 is de enige stap die AI-oordeel vereist. De rest is data-ophalen en formatteren.

**Aanbeveling:**  
Implementeer de `daily-executive-sync` als Lobster pipeline. Begin met de data-sync jobs als referentie (die zijn al half-deterministisch). Voeg een `StatusUpdate` JSON-schema toe als contract tussen stappen.

---

### 12. Bevinding 4 — Geheugen-Misbruik: Task Board als Statuslog

**Wat gevonden:**

De `task-checker` cron job (elke 30 min, 07:00–19:00) maakt bij elke run een nieuwe taak aan in `agent-tasks.json`:

```json
{
  "id": "task-checker-heartbeat-2026-04-13-1400",
  "title": "Task Checker Heartbeat - System Status",
  "description": "Automatische heartbeat van task-checker cron job. Gateway is operationeel... Core Web Vitals audit wordt nu opnieuw uitgevoerd door Elon voor productie site...",
  "status": "done"
}
```

Dit zijn géén taken. Het zijn statuslogs in een task board. Agent-tasks.json groeit onbeperkt met `done`-heartbeat entries die geen actionable work vertegenwoordigen.

**Tweede geheugen-misbruik: memory-agent als conversatie-archief**

De memory-agent draait dagelijks met:
> *"Analyseer de afgelopen 24 uur aan activiteiten, beslissingen en belangrijke informatie. Schrijf dit op in MEMORY.md."*

Dit is de "bandage" anti-pattern: de agent heeft geen gedefinieerde workflow-artifacts, dus wordt het geheugen gebruikt om de ontbrekende structuur te compenseren. Het resultaat is een groeiend MEMORY.md-bestand dat bij compaction afgekapt wordt.

**Derde misbruik: status-in-task-description**

In agent-tasks.json staan task `description`-velden met volledige proza-status-updates (zie LDR-2026-001 met 3.000+ karakter `plan`). De task-description is een statusrapport geworden, niet een taakbeschrijving. Bij ophalen van taken voor context-injectie wordt al deze prose meegestuurd.

**Aanbeveling:**
1. Stop met heartbeat-taken in agent-tasks.json. Gebruik een apart `status-log.jsonl` bestand voor monitoring output.
2. Definieer expliciete artifacts per workflow (zie §11). Als workflows artifacts produceren, is er minder reden om geheugen als opvang te gebruiken.
3. Begrens task-descriptions tot 200 tekens. Verwijs voor details naar wiki-vault bestanden.

---

### 13. Bevinding 5 — Workflow Definitie: Muddy als Open-Ended Orchestrator

**Wat gevonden:**

Muddy's cron-berichten zijn open vragen:

- `"Start de dagelijkse executive sync. Vraag status updates van Elon, Gary en Warren."`
- `"Voer de weekly-bot-overleg skill uit. Verzamel Gary's content-analyse uit c-suite-chat, haal wekelijkse input op..."`
- `"Voer de weekly-kompas-sessie skill uit. Verzamel Warren's revenue-analyse..."`

Muddy beslist zelf:
- Wanneer welke subagent te spawnen
- Hoeveel rondes (1, 2, 3 — zoals gezien in de executive meeting transcripten)
- Of subagent resultaten voldoende zijn om door te gaan
- Hoe te synthetiseren naar Discord

Dit is non-deterministisch orkestratatie. Bij elke run kan Muddy andere beslissingen nemen, wat leidt tot inconsistente outputs (bewezen door de `daily-executive-sync` timeouts in cron/runs).

**Hiërarchie-check:**

Volgens de Hiërarchie van Succes moet vóór geheugen het volgende gedefinieerd zijn:
1. ✗ **Workflow definitie**: niet gedefinieerd als stap-reeks, alleen als vage instructie
2. ✓ **Rol-scheiding**: aanwezig (Muddy/Elon/Gary/Warren)
3. ✗ **Artifacts**: niet gedefinieerd (vrije tekst handoffs)
4. ✓ **Regels**: aanwezig (AGENTS.md harde regels)
5. ✗ **Pipelines**: niet geïmplementeerd (geen Lobster)

Score: 2 van 5 hiërarchieniveaus correct geïmplementeerd.

---

### 14. Refactoring Plan — Stap-voor-stap naar Determinisme

#### Fase 1 — Artifacts definiëren (geen code, alleen schema's)

**Week 1:**
1. Definieer `StatusUpdate` JSON-schema (zie §10)
2. Definieer `ExecutiveSyncResult` schema (aggregatie van 3× StatusUpdate)
3. Voeg beide schemas toe aan `workspace/schemas/` directory
4. Verwijs ernaar in de cron-payload van `daily-executive-sync`

**Verwacht effect:** Agents weten welk formaat verwacht wordt. Muddy kan deterministisch aggregeren.

#### Fase 2 — Lobster Pipeline voor daily-executive-sync

**Week 2:**
Vervang de open `agentTurn` cron payload door een gestructureerde multi-stap aanpak:

```
Stap 1 — Dataverzameling (deterministisch, geen LLM):
  - Lees agent-tasks.json → filter per agent, status=in_progress|proposed
  - Lees c-suite-chat.jsonl → laatste 24h
  Output: data_context.json

Stap 2 — StatusUpdate generatie (LLM Task, geïsoleerd):
  Input: data_context.json + StatusUpdate schema
  Output: [elon_status.json, gary_status.json, warren_status.json]
  
Stap 3 — Aggregatie (deterministisch):
  Input: drie StatusUpdate JSONs
  Output: daily_digest.json

Stap 4 — Discord publicatie (deterministisch):
  Input: daily_digest.json + Discord template
  Output: Discord bericht
```

Als OpenClaw Lobster `.lobster` bestandsformaat ondersteunt: implementeer als flow.  
Als alternatief: implementeer als Python-script in een `scripts/daily-sync.py` dat de stappen sequentieel uitvoert.

#### Fase 3 — Elon's Skills Herstructureren

**Week 3:**
- Verplaats wiki-ingest, wiki-onderhoud, wiki-raw-check, wiki-raw-transformeren, wiki-zoek-antwoord naar een nieuwe `wiki-agent` of naar Gary
- Elon behoudt: elon-tech-audit, matomo-traffic, vikbooking-bookings, wiki-wp-freshness, skill-building
- Update `openclaw.json` agent-definitie voor Elon (reduceer skills van 11 naar 5)
- Update cron jobs die wiki-taken triggeren om de juiste agent te gebruiken

#### Fase 4 — Task Board sanering

**Week 4:**
- Verwijder alle `task-checker-heartbeat-*` entries uit agent-tasks.json
- Configureer task-checker om naar `logs/status-log.jsonl` te schrijven i.p.v. agent-tasks.json
- Begrens task-description maximumlengte in de system prompt instructie
- Voeg archivering toe: taken met status=done ouder dan 30 dagen naar `agent-tasks-archive.json`

---

### 15. Samenvatting Deel 2

| Anti-pattern (doctrine) | Aanwezig? | Ernst |
|---|---|---|
| Gecentraliseerd geheugen als dump | Deels (MEMORY.md groeit) | Middel |
| Mega-agents | Ja (Elon: 11 skills, 6 wiki) | Hoog |
| Geheugen als orkestratie | Ja (task-checker heartbeats, memory-agent als statuslog) | Hoog |
| Vage rollen | Ja (Muddy als open orchestrator, Elon's wiki-scope) | Hoog |
| Geen deterministische pipelines | Ja (nul Lobster-flows) | Hoog |
| Slechte artifact kwaliteit | Ja (vrije tekst handoffs) | Hoog |
| Vrije tekst status in task-board | Ja (proza in description/plan velden) | Middel |

**De kern van het probleem** is dat de architectuur de Hiërarchie van Succes omgekeerd heeft uitgerold: er is geheugen (MEMORY.md, memory-agent, wiki-vault), er zijn regels (AGENTS.md), maar de **workflow-definitie en artifacts ontbreken**. Dit maakt elke pipeline non-deterministisch en elke agent context-afhankelijk van conversatiegeschiedenis in plaats van gestructureerde data.

De script-driven data-sync jobs (matomo, vikbooking) zijn het correcte model: Python script → SQLite snapshot → minimale LLM rapportage. Dit patroon moet uitgebreid worden naar de executive sync en wiki-onderhoud workflows.

---

*Deel 2 gebaseerd op analyse van cron/jobs.json (16 jobs), flows/registry.sqlite (136 runs), agents/muddy|elon/sessions, workspace/agent-tasks.json (volledige takenlijst), en c-suite-chat.jsonl. Datum: 2026-04-13.*

---

## Deel 3 — Lobster Pipelines: Deterministische Workflows in OpenClaw

*Toegevoegd: 2026-04-13. Status: geïmplementeerd voor daily-executive-sync, dario-tech-audit, gary-content-strategy en warren-revenue-strategy.*

---

### Overzicht

Deel 2 constateerde "Nul Lobster Pipelines" als een van de zwaarste architectuurproblemen. Dit deel beschrijft wat Lobster is, waarom het de juiste oplossing is voor de problemen die in Deel 2 zijn gevonden, hoe het in onze specifieke proxy-opzet werkt, en hoe je het vanaf nul implementeert.

**Leeswijzer:**
- Beginner? Lees §16 t/m §19 voor het concept.
- Developer die wil implementeren? Lees §20 t/m §24 voor de volledige technische gids.
- Alleen het eindresultaat? Zie §25 voor de samenvatting.

---

### 16. Wat is Lobster?

Lobster is de pipeline-engine die ingebouwd is in OpenClaw. Het stelt je in staat om een reeks taken te definiëren als een YAML-bestand (een `.lobster` bestand) en die als één geautomatiseerde stroom uit te voeren.

**Vergelijking om het te begrijpen:**

Stel je voor dat een agent elke week dit doet:
1. Berekent welk onderwerp hij deze week analyseert
2. Haalt data op van een externe API
3. Vraagt aan een AI om de data te interpreteren
4. Schrijft het rapport op

Zonder Lobster doet een agent dit volledig zelf: hij leest zijn eigen instructies, beslist welke stap hij wanneer zet, roept tools aan, interpreteert de resultaten, enzovoort. Elke stap kost agent-tokens en -tijd. Bij elke run kan hij andere beslissingen nemen.

Met Lobster is elke stap **van tevoren vastgelegd** in een bestand. De engine voert ze in volgorde uit. De agent start alleen de pipeline en rapporteert het resultaat. De tussenliggende stappen draaien buiten de agent-context.

**Het kernprincipe:** alles wat je als een vast script kunt schrijven, hoort niet in de agent-context. Alleen stappen die echt AI-oordeel vereisen, zijn LLM-stappen.

---

### 17. Waarom Lobster? Het Probleem dat het Oplost

In Deel 2 §11 staat de vòòr-situatie:

```
Cron trigger
  → Muddy (LLM): "Voer de wekelijkse tech audit uit"
    → Agent leest SKILL.md (600 tokens instructies)
    → Agent besluit welke WP API-calls te doen
    → Agent doet de calls zelf
    → Agent vraagt LLM om analyse
    → Agent schrijft rapport
    → Agent schrijft naar wiki
```

Elke stap verbruikt tokens van de agent's context. Als de agent 6.605 tokens systemprompt heeft (zie Deel 1 §2.5), dan draagt elke run van zo'n workflow bij aan hoge kosten en onvoorspelbaar gedrag.

**Na implementatie met Lobster:**

```
Cron trigger
  → Agent: "Roep lobster aan voor deze pipeline"
    → Lobster stap 1: python3 -c "..." → aspect JSON (0 LLM tokens)
    → Lobster stap 2: curl WP_API → plugin data (0 LLM tokens)
    → Lobster stap 3: llm-invoke.py → analyse JSON (deepseek tokens, niet agent)
    → Lobster stap 4: python3 -c "..." → rapport opslaan (0 LLM tokens)
  → Agent rapporteert samenvatting aan Discord (minimale tokens)
```

**Kwantificeerbare voordelen:**
- Stap 1, 2, 4: **0 LLM tokens** — deterministisch shell script
- Stap 3 (LLM): draait via **relay-plane op deepseek-chat**, niet via de dure agent-model
- De agent zelf gebruikt alleen tokens voor het starten en rapporteren, niet voor het werk
- Elke run produceert **hetzelfde type output** — geen variatie in structuur

---

### 18. De Proxy-keten: Hoe LLM-aanroepen Werken

Om te begrijpen hoe LLM-aanroepen in pipelines werken, moet je de proxy-keten begrijpen.

```
┌─────────────────────────────────────────────────────────────┐
│                    HOST MACHINE (Linux PC)                   │
│                                                             │
│  ┌──────────────────┐     ┌──────────────────────────────┐  │
│  │   relay-plane    │     │    OpenClaw Gateway          │  │
│  │  (localproxy)    │     │    (poort 3333)               │  │
│  │  poort 4100      │◄────│                              │  │
│  │                  │     │  Agents: muddy, elon,        │  │
│  │  Routes:         │     │  gary, warren, dario,        │  │
│  │  auto → deepseek │     │  memory-agent                │  │
│  │  claude-sonnet   │     │                              │  │
│  │    → Anthropic   │     └──────────────────────────────┘  │
│  └──────────────────┘                                       │
│         │                                                   │
│         ▼                                                   │
│  ┌──────────────────┐                                       │
│  │  Externe APIs    │                                       │
│  │  - DeepSeek API  │                                       │
│  │  - Anthropic API │                                       │
│  └──────────────────┘                                       │
└─────────────────────────────────────────────────────────────┘
         │ (NAT via 10.0.2.1)
         ▼
┌─────────────────────────────────────────────────────────────┐
│              AGENT VM (Linux container, 10.0.1.2)           │
│                                                             │
│  /home/agent/workspace/                                     │
│  └── .openclaw/                                             │
│      ├── workspace/pipelines/*.lobster                      │
│      └── workspace/scripts/llm-invoke.py                   │
│                                                             │
│  Lobster pipeline draait hier als shell-processen           │
│  llm-invoke.py POST naar http://10.0.2.1:4100/v1/chat/...  │
└─────────────────────────────────────────────────────────────┘
```

**Sleuteldetail:** De relay-plane luistert op het host-IP (`10.0.2.1`) op poort 4100. De agent-VM bereikt de host via NAT. Alles wat in een Lobster pipeline naar de relay-plane stuurt, gebruikt dit adres.

**Modelrouting via relay-plane:**

| Model in aanroep | Wat er werkelijk draait |
|---|---|
| `auto` | deepseek-chat (goedkoop, snel) |
| `localproxy/claude-sonnet-4-6` | Anthropic claude-sonnet-4-6 (duurder, slimmer) |
| `deepseek/deepseek-chat` | deepseek-chat direct |

In pipelines gebruiken we altijd `auto` (= deepseek) voor LLM-stappen. De agent die de pipeline start, kan zelf op `claude-sonnet-4-6` draaien.

---

### 19. Het llm_task.invoke Probleem — en de Oplossing

OpenClaw's Lobster engine heeft een ingebouwde pipeline-stap: `pipeline: llm_task.invoke`. Dit klinkt als de logische keuze voor LLM-aanroepen vanuit een pipeline. **Het werkt niet.**

**Waarom niet:**

Lobster's `llm_task.invoke` stuurt een verzoek naar de gateway's `llm-task` plugin via `/tools/invoke`. Lobster verwacht terug:

```json
{
  "ok": true,
  "result": {
    "ok": true,
    "result": {
      "output": {
        "text": "...",
        "data": {...},
        "format": "json"
      }
    }
  }
}
```

Maar de `llm-task` plugin retourneert:

```json
{
  "content": [{"type": "text", "text": "..."}],
  "details": {...}
}
```

Lobster's `validateResponseEnvelope` controleert op `result.output` — dat veld bestaat niet in wat de plugin terugstuurt. Elke aanroep faalt met:

```
llm_task.invoke received invalid response envelope
```

Dit is een structurele incompatibiliteit. Geen configuratie-optie lost dit op.

**De oplossing: llm-invoke.py**

In plaats van via de gateway te gaan, roept het script de relay-plane **direct** aan:

```
Pipeline stap
  → python3 llm-invoke.py --prompt-env MIJN_PROMPT
    → POST http://10.0.2.1:4100/v1/chat/completions
      → relay-plane → deepseek-chat
    → JSON terug naar stdout
  → Volgende pipeline stap leest via stdin
```

Het script staat op:
```
/home/agent/workspace/.openclaw/workspace/scripts/llm-invoke.py
```

**Gebruik in een pipeline stap:**

```yaml
- id: analyze
  env:
    MIJN_PROMPT: >-
      Analyseer de data en return JSON met keys: ...
  command: >
    python3 /home/agent/workspace/.openclaw/workspace/scripts/llm-invoke.py
    --prompt-env MIJN_PROMPT
  stdin: '$vorige_stap.stdout'
```

**Gouden regel:** Gebruik **nooit** `pipeline: llm_task.invoke` in een `.lobster` bestand. Gebruik altijd `command: python3 llm-invoke.py`.

---

### 20. Lobster Pipeline Anatomie

Een `.lobster` bestand is een YAML-bestand met de volgende structuur:

```yaml
name: mijn-pipeline-naam          # unieke identifier
description: |                    # mensleesbare beschrijving
  Wat doet deze pipeline?

steps:
  - id: stap_naam                 # unieke ID per stap
    command: >                    # shell-commando (> = fold newlines)
      python3 -c "..."
    stdin: '$andere_stap.stdout'  # optioneel: input van andere stap
    env:                          # optioneel: omgevingsvariabelen
      MIJN_VAR: waarde
    approval: required            # optioneel: wacht op menselijke goedkeuring
```

**Stap-types:**

| Type | Wanneer | Voorbeeld |
|---|---|---|
| Shell-commando | Alles zonder LLM | `python3 script.py`, `curl`, `sqlite3` |
| LLM-aanroep | Analyse, generatie | `python3 llm-invoke.py --prompt-env VAR` |
| Approval gate | Vóór schrijfacties | `approval: required` op de stap |

**Stdin-doorvoer:**

Elke stap schrijft naar `stdout`. De volgende stap kan dat lezen via:

```yaml
stdin: '$stap_id.stdout'
```

Dit is de manier waarop data tussen stappen stroomt — als tekst via de pipe. Alle LLM-stappen en data-stappen schrijven JSON naar stdout zodat de volgende stap het kan parsen.

**Bestandslocatie:**

```
.openclaw/workspace/pipelines/
├── daily-executive-sync.lobster
├── dario-tech-audit.lobster
├── gary-content-strategy.lobster
└── warren-revenue-strategy.lobster
```

---

### 21. Volledig Geannoteerd Voorbeeld: dario-tech-audit

Dit is de referentie-implementatie. Elke beslissing is hier toegelicht.

```yaml
name: dario-tech-audit
description: |
  Wekelijkse technische audit — deterministisch aspect-bepaling + LLM-analyse + approval gate.
  # ↑ Altijd beschrijven: wat doet het, welke stappen zijn deterministisch/LLM.

steps:

  # ═══════════════════════════════════════════════════
  # STAP 1: DETERMINISTISCH — geen LLM
  # Bepaal welk van de 8 aspecten deze week aan de beurt is
  # op basis van ISO weeknummer % 8.
  # Output: JSON {"week": 16, "aspect": "Security Posture"}
  # ═══════════════════════════════════════════════════
  - id: determine_aspect
    command: >
      python3 -c "
      import datetime, json;
      week = datetime.date.today().isocalendar()[1];
      aspects = {0:'WordPress & Plugin Health', 1:'Performance & Core Web Vitals',
                 2:'Security Posture', 3:'Infrastructure & Uptime',
                 4:'Technische SEO', 5:'Database & Data-integriteit',
                 6:'Code Quality & Tech Debt', 7:'Integraties & API-health'};
      print(json.dumps({'week': week, 'aspect': aspects[week % 8]}))
      "
    # Geen stdin — dit is de eerste stap.
    # Output gaat automatisch naar stdout voor de volgende stap.

  # ═══════════════════════════════════════════════════
  # STAP 2: DETERMINISTISCH — geen LLM
  # Haal WordPress data op via de REST API.
  # stdin: JSON van stap 1 (aspect + weeknummer)
  # Output: gecombineerde JSON met aspect_meta + plugins + settings
  # ═══════════════════════════════════════════════════
  - id: gather_wp_data
    command: |
      # Lees stdin (= output van determine_aspect) en sla op in variabele
      . /home/agent/workspace/.env 2>/dev/null
      ASPECT=$(cat)   # cat leest stdin
      # Haal WP API data op — deterministisch, geen LLM
      curl -s -k -u "$WP_API_USER:$WP_API_PASSWORD" \
        "$WP_STAGING_URL/wp-json/wp/v2/plugins?per_page=100" \
        -o /tmp/wp-plugins.json
      curl -s -k -u "$WP_API_USER:$WP_API_PASSWORD" \
        "$WP_STAGING_URL/wp-json/wp/v2/settings" \
        -o /tmp/wp-settings.json
      # Combineer alles in één JSON voor de LLM-stap
      printf '{"aspect_meta": %s, "plugins": %s, "settings": %s}' \
        "$ASPECT" "$(cat /tmp/wp-plugins.json)" "$(cat /tmp/wp-settings.json)"
    stdin: '$determine_aspect.stdout'
    # ↑ Verwijst naar de stdout van stap 'determine_aspect'

  # ═══════════════════════════════════════════════════
  # STAP 3: LLM-AANROEP — via relay-plane (deepseek)
  # De enige stap die AI-oordeel vereist.
  # stdin: gecombineerde JSON van stap 2
  # Output: JSON met findings + lab_board_items
  # ═══════════════════════════════════════════════════
  - id: analyze
    env:
      # Prompt als env-var om quoting-problemen in YAML te vermijden
      AUDIT_PROMPT: >-
        Voer een technische audit uit voor het aspect van deze week.
        Gebruik de WordPress plugin- en settings-data uit de input.
        Genereer maximaal 5 concrete bevindingen met prioriteit P0/P1/P2.
        Elke bevinding heeft: title, finding, evidence, priority, recommendation.
        Geef ook lab_board_items voor P0/P1 items.
        Return een JSON object met keys: aspect (string), week (integer),
        findings (array), lab_board_items (array met title/priority).
    command: >
      python3 /home/agent/workspace/.openclaw/workspace/scripts/llm-invoke.py
      --prompt-env AUDIT_PROMPT
      # ↑ --prompt-env leest de prompt uit de env var AUDIT_PROMPT
      # llm-invoke.py leest stdin (= WP data) en stuurt het mee naar de LLM
    stdin: '$gather_wp_data.stdout'

  # ═══════════════════════════════════════════════════
  # STAP 4: DETERMINISTISCH + APPROVAL GATE
  # Bepaal het rapport-pad en metadata.
  # approval: required = pipeline pauzeert hier totdat Michiel goedkeurt.
  # Na goedkeuring schrijft de AGENT (niet de pipeline) het rapport.
  # ═══════════════════════════════════════════════════
  - id: write_report
    command: |
      python3 -c "
      import json, sys
      from datetime import date
      item = json.load(sys.stdin)
      aspect = item.get('aspect', 'onbekend')
      week = item.get('week', 0)
      findings = item.get('findings', [])
      # Bepaal bestandspad (deterministisch op basis van datum + aspect)
      report_path = f'/home/agent/wiki/agents/dario/tech-audit/{date.today().strftime(\"%Y\")}-W{week:02d}-{aspect.lower().replace(\" \", \"-\")[:30]}.md'
      print(json.dumps({
          'report_path': report_path,
          'aspect': aspect,
          'findings_count': len(findings),
          'p0_count': sum(1 for f in findings if f.get('priority') == 'P0'),
          'p1_count': sum(1 for f in findings if f.get('priority') == 'P1'),
          'ready_for_approval': True
      }))
      "
    stdin: '$analyze.stdout'
    approval: required
    # ↑ Pipeline pauzeert. Lobster toont 'requiresApproval.preview' aan de agent.
    # De agent stuurt dit naar Michiel. Na goedkeuring: resume met approvalId.
```

---

### 22. Visueel Stroomschema: Leven van een Pipeline Run

```
┌─────────────────────────────────────────────────────────────────────┐
│  CRON TRIGGER (bijv. zondag 14:00)                                  │
│  jobs.json: agentId=dario, model=claude-sonnet-4-6                  │
└─────────────────────────┬───────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  DARIO AGENT SESSIE START                                            │
│  Systemprompt: ~3.200 tokens (Dario's workspace-bestanden)          │
│  Bericht: "Roep de lobster tool aan voor dario-tech-audit.lobster"  │
└─────────────────────────┬───────────────────────────────────────────┘
                          │ lobster tool aanroep
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  LOBSTER ENGINE (buiten agent-context)                               │
│                                                                     │
│  Stap 1: determine_aspect                                           │
│  └─ python3 -c "week % 8 → aspect"                                  │
│     stdout: {"week":16,"aspect":"Security Posture"}                 │
│             │                                                       │
│  Stap 2:   ▼  gather_wp_data                                        │
│  └─ .env laden → curl WP API (plugins + settings)                  │
│     stdout: {"aspect_meta":{...},"plugins":[...],"settings":{...}}  │
│             │                                                       │
│  Stap 3:   ▼  analyze   (ENIGE LLM-stap)                           │
│  └─ llm-invoke.py --prompt-env AUDIT_PROMPT                         │
│     │                                                               │
│     └─► POST http://10.0.2.1:4100/v1/chat/completions              │
│         model: auto (→ deepseek-chat)                               │
│         input: AUDIT_PROMPT + WP data JSON                          │
│         ◄── response: {"findings":[...],"lab_board_items":[...]}    │
│     stdout: analysis JSON                                           │
│             │                                                       │
│  Stap 4:   ▼  write_report  [APPROVAL GATE ⏸]                      │
│  └─ python3 -c "bepaal rapport_path + metadata"                     │
│     stdout: {"report_path":"...","findings_count":4,"ready":true}   │
│     ⏸ Pipeline pauzeert — wacht op goedkeuring                     │
└─────────────────────────┬───────────────────────────────────────────┘
                          │ approval preview terug naar agent
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  DARIO STUURT PREVIEW NAAR MICHIEL (#approvals Discord channel)     │
│  Michiel reageert: "goedgekeurd"                                    │
└─────────────────────────┬───────────────────────────────────────────┘
                          │ lobster resume (approvalId, approved: true)
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  PIPELINE HERVAT — RAPPORT WORDT GESCHREVEN                          │
│  Dario schrijft rapport naar wiki-vault                             │
│  Dario stuurt samenvatting naar #daily-digest                       │
└─────────────────────────────────────────────────────────────────────┘

Token-gebruik:
  Dario agent:     ~500 tokens (starten + rapporteren)
  Deepseek LLM:    ~3.000 tokens (stap 3, buiten Dario's context)
  Totaal vs. voor: was ~8.000+ tokens volledig in Dario's context
```

---

### 23. Schema: Hoe een Pipeline Taak Eruitziet

Dit is de volledige data-structuur die door een pipeline-stap stroomt. Elke stap leest stdin en schrijft stdout. Zo ziet de JSON eruit op elk punt in de `dario-tech-audit` pipeline:

**Na stap 1 (determine_aspect) — stdout:**
```json
{
  "week": 16,
  "aspect": "Security Posture"
}
```

**Na stap 2 (gather_wp_data) — stdout:**
```json
{
  "aspect_meta": {
    "week": 16,
    "aspect": "Security Posture"
  },
  "plugins": [
    {
      "plugin": "wordfence/wordfence.php",
      "name": "Wordfence Security",
      "version": "7.11.5",
      "status": "active",
      "update": "none"
    }
  ],
  "settings": {
    "title": "Logies op Dreef",
    "url": "https://staging.logiesopdreef.nl"
  }
}
```

**Na stap 3 (analyze) — stdout (LLM output):**
```json
{
  "aspect": "Security Posture",
  "week": 16,
  "findings": [
    {
      "title": "Verouderde Wordfence versie",
      "finding": "Wordfence draait op 7.11.5, actueel is 7.12.1",
      "evidence": "plugins[].version: 7.11.5, update: available",
      "priority": "P1",
      "recommendation": "Update Wordfence via wp-admin → Plugins",
      "owner": "elon"
    }
  ],
  "lab_board_items": [
    {
      "title": "Update Wordfence naar 7.12.1",
      "priority": "P1"
    }
  ]
}
```

**Na stap 4 (write_report) — stdout (approval preview):**
```json
{
  "report_path": "/home/agent/wiki/agents/dario/tech-audit/2026-W16-security-posture.md",
  "aspect": "Security Posture",
  "findings_count": 1,
  "p0_count": 0,
  "p1_count": 1,
  "ready_for_approval": true
}
```

**Wat de agent ziet bij `approval: required`:**

De lobster tool geeft terug:
```json
{
  "ok": true,
  "status": "needs_approval",
  "requiresApproval": {
    "stepId": "write_report",
    "preview": {
      "report_path": "/home/agent/wiki/agents/dario/tech-audit/2026-W16-security-posture.md",
      "findings_count": 1,
      "p1_count": 1
    },
    "approvalId": "apr_abc123"
  }
}
```

De agent stuurt dit preview naar Michiel. Na goedkeuring:
```json
{
  "action": "resume",
  "approvalId": "apr_abc123",
  "approved": true
}
```

---

### 24. Implementatiegids: Lobster Pipeline in een Nieuwe OpenClaw Omgeving

Deze sectie beschrijft stap voor stap hoe je Lobster pipelines opzet in een nieuwe installatie met dezelfde proxy-architectuur (relay-plane op host, agents in VM).

#### Stap 1 — Controleer de relay-plane

De relay-plane moet bereikbaar zijn vanuit de agent-VM. Test dit vanuit de VM:

```bash
# Vanuit de agent-VM (als Michiel uitvoert: ! commando in session)
curl -s http://10.0.2.1:4100/v1/models
# Verwacht: {"data": [...]} of vergelijkbaar
```

Als dit mislukt: voer het netwerk-setup script van de relay-plane opnieuw uit op de host.

#### Stap 2 — Installeer llm-invoke.py

Maak het script aan op het volgende pad in de agent-VM:

```
/home/agent/workspace/.openclaw/workspace/scripts/llm-invoke.py
```

Volledige inhoud van het script:

```python
#!/usr/bin/env python3
"""
Direct LLM invoke script voor Lobster pipelines.
Roept de relay-plane proxy direct aan.
Leest JSON van stdin, retourneert JSON naar stdout.

Gebruik:
  python3 llm-invoke.py --prompt "..."
  python3 llm-invoke.py --prompt-env MIJN_PROMPT_VAR
  echo '{"key":"val"}' | python3 llm-invoke.py --prompt "..."
"""
import argparse, json, os, sys, urllib.request, urllib.error

DEFAULT_API_URL = "http://10.0.2.1:4100/v1/chat/completions"  # relay-plane adres
DEFAULT_MODEL   = "auto"          # auto → deepseek-chat via relay-plane
DEFAULT_API_KEY = "none"          # relay-plane vereist geen echte sleutel
DEFAULT_TIMEOUT = 120             # seconden

SYSTEM_PROMPT = (
    "You are a JSON-only function. "
    "Return ONLY a valid JSON value. "
    "Do not wrap in markdown fences. "
    "Do not include commentary. "
    "Do not call tools."
)

def strip_code_fences(text):
    t = text.strip()
    if t.startswith("```"):
        lines = t.split("\n", 1)
        t = lines[1] if len(lines) > 1 else t[3:]
        if t.endswith("```"):
            t = t[:-3].strip()
    return t

def call_llm(prompt, input_data, model, api_url, api_key, timeout):
    user_content = f"{prompt}\n\nINPUT_JSON:\n{json.dumps(input_data, ensure_ascii=False)}"
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user",   "content": user_content},
        ],
        "max_tokens": 8192,
    }
    req = urllib.request.Request(
        api_url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json",
                 "Authorization": f"Bearer {api_key}"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read().decode("utf-8")
    except urllib.error.HTTPError as e:
        raise RuntimeError(f"HTTP {e.code}: {e.read().decode('utf-8', errors='replace')[:400]}")
    except urllib.error.URLError as e:
        raise RuntimeError(f"Connection error: {e.reason}")

    result  = json.loads(body)
    content = result["choices"][0]["message"]["content"]
    return json.loads(strip_code_fences(content))

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--prompt",     default=None)
    parser.add_argument("--prompt-env", default=None)
    parser.add_argument("--model",      default=None)
    parser.add_argument("--api-url",    default=None)
    parser.add_argument("--api-key",    default=None)
    parser.add_argument("--timeout",    type=int, default=None)
    args = parser.parse_args()

    if args.prompt_env:
        prompt = os.environ.get(args.prompt_env, "").strip()
        if not prompt:
            print(f"Error: env var '{args.prompt_env}' is leeg", file=sys.stderr)
            sys.exit(1)
    elif args.prompt:
        prompt = args.prompt
    else:
        print("Error: --prompt of --prompt-env vereist", file=sys.stderr)
        sys.exit(1)

    api_url = args.api_url or os.environ.get("LLM_INVOKE_URL",     DEFAULT_API_URL)
    api_key = args.api_key or os.environ.get("LLM_INVOKE_API_KEY", DEFAULT_API_KEY)
    model   = args.model   or os.environ.get("LLM_INVOKE_MODEL",   DEFAULT_MODEL)
    timeout = args.timeout or int(os.environ.get("LLM_INVOKE_TIMEOUT", str(DEFAULT_TIMEOUT)))

    if not sys.stdin.isatty():
        raw = sys.stdin.read().strip()
        try:    input_data = json.loads(raw) if raw else None
        except: input_data = raw
    else:
        input_data = None

    try:
        result = call_llm(prompt, input_data, model, api_url, api_key, timeout)
    except Exception as e:
        print(json.dumps({"error": str(e)}), file=sys.stderr)
        sys.exit(1)

    print(json.dumps(result, indent=2, ensure_ascii=False))

if __name__ == "__main__":
    main()
```

#### Stap 3 — Maak een pipeline bestand aan

Maak een nieuw `.lobster` bestand aan in de pipelines-directory:

```
/home/agent/workspace/.openclaw/workspace/pipelines/mijn-pipeline.lobster
```

Gebruik de volgende minimale template:

```yaml
name: mijn-pipeline
description: |
  Korte beschrijving van wat deze pipeline doet.

steps:
  - id: stap_1_deterministisch
    command: >
      python3 -c "
      import json;
      print(json.dumps({'resultaat': 'data'}))
      "

  - id: stap_2_llm
    env:
      MIJN_PROMPT: >-
        Analyseer de input data en return JSON met key 'analyse'.
    command: >
      python3 /home/agent/workspace/.openclaw/workspace/scripts/llm-invoke.py
      --prompt-env MIJN_PROMPT
    stdin: '$stap_1_deterministisch.stdout'

  - id: stap_3_resultaat
    command: |
      python3 -c "
      import json, sys
      data = json.load(sys.stdin)
      print(json.dumps({'klaar': True, 'samenvatting': data.get('analyse', '')}))
      "
    stdin: '$stap_2_llm.stdout'
    approval: required
```

#### Stap 4 — Configureer de cron job

In `cron/jobs.json`, voeg een job toe die de agent instrueert de pipeline te starten:

```json
{
  "id": "uuid-hier-invullen",
  "agentId": "mijn-agent",
  "name": "mijn-pipeline-weekly",
  "description": "Wekelijkse pipeline run",
  "enabled": true,
  "schedule": {
    "kind": "cron",
    "expr": "0 14 * * 1",
    "tz": "Europe/Amsterdam"
  },
  "sessionTarget": "isolated",
  "wakeMode": "now",
  "payload": {
    "kind": "agentTurn",
    "message": "Roep de lobster tool aan:\n  action: \"run\"\n  pipeline: \"/home/agent/workspace/.openclaw/workspace/pipelines/mijn-pipeline.lobster\"\n\nLees het bestand NIET zelf uit. Voer de stappen NIET handmatig uit. Gebruik de lobster tool direct.\n\nBij status \"needs_approval\": stuur requiresApproval.preview naar Michiel (#approvals channel 1484546410278813776) en wacht op zijn goedkeuring.\nNa goedkeuring: resume met action \"resume\", approvalId en approved: true.",
    "lightContext": true,
    "model": "localproxy/claude-sonnet-4-6",
    "fallbacks": ["deepseek/deepseek-chat"],
    "timeoutSeconds": 600
  },
  "delivery": {
    "mode": "announce",
    "channel": "discord",
    "to": "DISCORD_CHANNEL_ID"
  }
}
```

**Waarom `claude-sonnet-4-6` voor de agent?**

De agent die de pipeline start, doet weinig werk (starten + resultaat rapporteren). Maar Lobster vereist dat de agent de `lobster` tool correct aanroept en eventuele approval-flows afhandelt. Deepseek mist soms de tool-calling precisie hiervoor. `claude-sonnet-4-6` is betrouwbaarder voor de tool-aanroep zelf.

De pipeline-stappen zelf draaien dan op deepseek (via llm-invoke.py `auto` model) — dat is de kostenoptimalisatie.

#### Stap 5 — Test de pipeline

Laat de agent de pipeline handmatig uitvoeren vóór je de cron job inschakelt. Geef de agent dit bericht:

```
Roep de lobster tool aan:
  action: "run"
  pipeline: "/home/agent/workspace/.openclaw/workspace/pipelines/mijn-pipeline.lobster"

Rapporteer elke stap-output en eventuele fouten.
```

**Veelvoorkomende fouten en oplossingen:**

| Fout | Oorzaak | Oplossing |
|---|---|---|
| `Connection error: [Errno 111]` | relay-plane niet bereikbaar | Herstart netwerk-setup script op host |
| `HTTP 401` of `HTTP 403` | API-sleutel fout | relay-plane gebruikt `Authorization: Bearer none` — check headers |
| `json.JSONDecodeError` in llm-invoke | LLM retourneert geen valide JSON | Verbeter de prompt: voeg `Return ONLY a JSON object` toe |
| `invalid response envelope` | Je gebruikt `pipeline: llm_task.invoke` | Vervang door `command: python3 llm-invoke.py` |
| Stap 2 krijgt lege stdin | Stap 1 schrijft niet naar stdout | Zorg dat stap 1 eindigt met `print(json.dumps(...))` |

---

### 25. Resultaten: Vóór en Na de Implementatie

#### Pipelines geïmplementeerd (2026-04-13)

| Pipeline | Agent | Was | Is nu |
|---|---|---|---|
| `daily-executive-sync` | Elon | agentTurn (open ended) | Lobster pipeline (4 stappen) |
| `dario-tech-audit` | Dario | agentTurn + SKILL.md | Lobster pipeline (4 stappen + approval) |
| `gary-content-strategy` | Gary | agentTurn + SKILL.md | Lobster pipeline (4 stappen + approval) |
| `warren-revenue-strategy` | Warren | agentTurn + SKILL.md | Lobster pipeline (4 stappen + approval) |

#### Token-gebruik vóór vs. na (per pipeline run)

| Pipeline | Vóór (agent-tokens) | Na (agent-tokens) | LLM-stap (relay-plane) |
|---|---|---|---|
| daily-executive-sync | ~12.000–18.000 | ~800 | ~6.000 (deepseek) |
| dario-tech-audit | ~10.000–15.000 | ~600 | ~4.000 (deepseek) |
| gary-content-strategy | ~10.000–14.000 | ~600 | ~3.500 (deepseek) |
| warren-revenue-strategy | ~11.000–16.000 | ~700 | ~4.500 (deepseek) |

*Schattingen op basis van gemiddelde sessieduur × token/s. Agent-tokens zijn claude-sonnet-4-6 (€ duur). Relay-plane tokens zijn deepseek-chat (€ goedkoop).*

#### Determinisme-score (Deel 2 §8 herberekend)

| Criterium | Was | Nu |
|---|---|---|
| Determinisme | 3/10 | **7/10** |
| Artifact Kwaliteit | 4/10 | **6/10** (pipeline outputs zijn gestructureerde JSON) |
| Rol-Isolatie | 6/10 | 6/10 (ongewijzigd) |
| Geheugen-gebruik | 5/10 | 5/10 (ongewijzigd) |
| **Totaal** | **5/10** | **6/10** |

#### Wat nog niet opgelost is

| Item | Status |
|---|---|
| Credentials in TOOLS.md (P0) | Gepland voor morgen (2026-04-14) |
| qmd wiki-queries in Gary/Warren pipelines | Niet mogelijk — agent-tools, geen shell-equivalent |
| Muddy als open-ended orchestrator | Ongewijzigd — weekly-bot-overleg/kompas/blauwdruk nog agentTurn |
| Task board heartbeat spam | Ongewijzigd |

De weekly Muddy-sessies (BOT overleg, Kompas, Blauwdruk) zijn bewust niet gepipelind: die vereisen echte inter-agent discussie en syntheseoordeel die waardevol zijn als agent-werk. Alleen de data-gedreven analyse-jobs zijn gepipelind.

---

*Deel 3 gebaseerd op implementatie van 2026-04-13. Pipeline bestanden staan in `.openclaw/workspace/pipelines/`. llm-invoke.py staat in `.openclaw/workspace/scripts/`.*
