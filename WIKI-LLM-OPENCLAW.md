# Wiki-LLM voor Openclaw (Claude Code)

Handleiding voor het raadplegen van de wiki-vault kennisbasis via openclaw: beschikbare tools, werkwijze voor het toevoegen van nieuwe content, raw→wiki transformatie en kant-en-klare skill-prompts.

> Architectuur en beheer: zie `~/wiki-vault/WIKI-LLM-SETUP.md`

---

## Inhoudsopgave

1. [Beschikbare tools](#1-beschikbare-tools)
2. [Informatie ophalen — werkwijze](#2-informatie-ophalen--werkwijze)
3. [Nieuwe raw content toevoegen](#3-nieuwe-raw-content-toevoegen)
4. [Raw → wiki transformatie](#4-raw--wiki-transformatie)
5. [Praktijkvoorbeelden](#5-praktijkvoorbeelden)
6. [Aanbevolen skills om aan te laten maken](#6-aanbevolen-skills-om-aan-te-laten-maken)
7. [Skill-prompts voor openclaw](#7-skill-prompts-voor-openclaw)

---

## 1. Beschikbare tools

Controleer actieve MCP-servers met `/mcp` in Claude Code:

```
llm-wiki  connected  (27 tools)   # vault-mind: lezen/schrijven/zoeken
qmd       connected  (4 tools)    # semantisch zoeken
```

### qmd__* — semantisch zoeken (4 tools)

| Tool | Gebruik |
|------|---------|
| `qmd__query` | Hybride zoeken: BM25 + vector + reranking. Aanbevolen voor alle zoekopdrachten. |
| `qmd__get` | Haal volledig document op via pad of docid. |
| `qmd__multi_get` | Haal meerdere documenten op via glob-patroon. |
| `qmd__status` | Toon index-status: collecties, aantal docs, embeddings. |

**Zoek-syntax voor `qmd__query`:**
```
searches=[
  {type:'lex', query:'exacte termen'},     # BM25 keyword
  {type:'vec', query:'semantische vraag'}, # vector/betekenis
  {type:'hyde', query:'hypothetisch antwoord'}
]
intent='wat je zoekt'
```

### llm-wiki__* — vault-beheer (27 tools)

| Categorie | Tools | Gebruik |
|-----------|-------|---------|
| Lezen | `vault_read`, `vault_list` | Bestanden lezen en mappen doorzoeken |
| Schrijven | `vault_write`, `vault_create`, `vault_append` | Wiki-pagina's aanmaken of bijwerken |
| Zoeken | `query_search`, `query_backlinks` | Full-text zoeken, backlinks opvragen |
| Metadata | `get_metadata`, `vault_exists` | Frontmatter lezen, bestaan controleren |

**Paden in de vault (vanuit de VM):**
- Raw bronbestanden: `/home/agent/wiki/raw/<project>/<categorie>/<slug>.md`
- Wiki-pagina's: `/home/agent/wiki/wiki/<project>/<categorie>/<slug>.md`
- Output/rapporten: `/home/agent/wiki/output/<project>/<bestand>.md`

---

## 2. Informatie ophalen — werkwijze

### Stap 1: Zoek relevante documenten

```
gebruik qmd__query met
  searches=[
    {type:'lex', query:'<trefwoorden>'},
    {type:'vec', query:'<semantische beschrijving>'}
  ]
  intent='<wat je wilt weten>'
```

Resultaat: paden + scores + snippets. Noteer paden (formaat: `logies-op-dreef/accommodatie/studio-beneden.md`).

### Stap 2: Lees volledig document

```
gebruik qmd__get met file="<pad uit zoekresultaat>"
```

Of via llm-wiki:
```
gebruik llm-wiki__vault_read met pad="/home/agent/wiki/raw/<pad>"
```

### Wanneer welke tool?

| Situatie | Tool |
|----------|------|
| Vraag beantwoorden | `qmd__query` → snippets zijn vaak voldoende |
| Volledig document nodig | `qmd__get` met pad uit zoekresultaat |
| Alle bestanden in een map | `llm-wiki__vault_list` |
| Backlinks opvragen | `llm-wiki__query_backlinks` |
| Overzicht index | `qmd__status` |

---

## 3. Nieuwe raw content toevoegen

### Stap 1: Maak projectmap aan

```bash
mkdir -p ~/wiki-vault/raw/<projectnaam>/<categorie>
```

Kies een projectnaam zonder spaties — dit wordt ook de qmd collectienaam.

### Stap 2: Maak markdown bestanden aan

Elk bestand krijgt verplichte frontmatter:

```markdown
---
title: "Paginatitel"
source_url: https://...        # originele URL (bij web-content)
source: wordpress              # of: manual, scrape, pdf, export
project: <projectnaam>        # = collectienaam in qmd
type: <categorie>             # accommodatie, info, locatie, etc.
indexed: <datum YYYY-MM-DD>
---

# Titel

[content]
```

### WordPress-export via REST API

```bash
# Haal alle pagina's op (pas URL aan)
curl "https://jouwsite.nl/wp-json/wp/v2/pages?per_page=100&_fields=id,slug,title,content,link" \
  > paginas.json

# Bekijk wat er is
python3 -c "import json; pages=json.load(open('paginas.json')); \
  [print(p['slug'], p['link']) for p in pages]"
```

Laat een openclaw-sessie vervolgens de JSON verwerken naar markdown bestanden met de juiste frontmatter.

### Stap 3: qmd collectie aanmaken

```bash
# Eénmalig per project — uitvoeren op de HOST (niet in de VM)
cd ~/wiki-vault/raw/<projectnaam>
~/.npm-global/bin/qmd collection add .

# Bevestig
~/.npm-global/bin/qmd collection list
```

### Stap 4: Indexeren en embeddings genereren

```bash
# Op de host — eerste keer downloadt model (~330MB), daarna snel
cd ~/wiki-vault/raw/<projectnaam>
~/.npm-global/bin/qmd embed

# Output: "✓ Done! Embedded N chunks from M documents in Xs"
```

### Stap 5: qmd service herstarten

```bash
systemctl --user restart mcp-qmd
```

### Stap 6: qmd index bijwerken in de openclaw VM

In een Claude Code terminalsessie in de VM:

```bash
cd /home/agent/wiki/raw/<projectnaam>
qmd collection add .   # als collectie nog niet bestaat
qmd embed
```

> **Let op:** de openclaw VM heeft een eigen qmd index op `/home/agent/workspace/.qmd/index.sqlite`. De host gebruikt `~/.cache/qmd/index.sqlite`. Beide moeten apart bijgewerkt worden.

### Bij nieuwe bestanden toevoegen aan bestaand project

```bash
# Bestanden toevoegen aan ~/wiki-vault/raw/<project>/...

# Re-indexeren op de host
cd ~/wiki-vault/raw/<projectnaam>
~/.npm-global/bin/qmd update
systemctl --user restart mcp-qmd

# Re-indexeren in de VM (Claude Code terminal)
cd /home/agent/wiki/raw/<projectnaam>
qmd update
```

---

## 3b. Wiki-pagina's indexeren in qmd

Na de raw→wiki transformatie (§4) zijn de wiki-pagina's beschikbaar maar nog **niet doorzoekbaar** via qmd. Indexeer ze als aparte collectie zodat je ook de verwerkte versies (met samenvattingen en wikilinks) kunt doorzoeken.

### Wanneer doen?

- Na de eerste raw→wiki transformatie van een nieuw project
- Na bulk-updates van wiki-pagina's

### Stappen (in de openclaw VM terminal)

```bash
# Stap 1: collectie aanmaken (eénmalig per project)
cd /home/agent/wiki/wiki/<projectnaam>
qmd collection add . --name <projectnaam>-wiki

# Stap 2: embeddings genereren
qmd embed

# Voorbeeld voor logies-op-dreef:
cd /home/agent/wiki/wiki/logies-op-dreef
qmd collection add . --name logies-op-dreef-wiki
qmd embed
# Output: "✓ Done! Embedded 56 chunks from 25 documents in 33s"
```

### Bij nieuwe wiki-pagina's toevoegen

```bash
cd /home/agent/wiki/wiki/<projectnaam>
qmd update    # indexeert alleen nieuwe/gewijzigde bestanden
qmd embed     # herberekent embeddings voor gewijzigde bestanden
```

### Zoeken in wiki vs. raw

| Collectie | Inhoud | Wanneer gebruiken |
|-----------|--------|-------------------|
| `logies-op-dreef` | Ruwe WordPress-content | Volledige originele tekst nodig |
| `logies-op-dreef-wiki` | Schone wiki met samenvattingen + wikilinks | Meeste zoekvragen — aangeraden |

```
# Zoeken in wiki-versies (aanbevolen)
gebruik qmd__query met
  searches=[{type:'vec', query:'studio faciliteiten prijs'}]
  collection='logies-op-dreef-wiki'
  intent='informatie over studio accommodaties'

# Zoeken in raw (originele tekst)
gebruik qmd__query met
  searches=[{type:'vec', query:'studio faciliteiten prijs'}]
  collection='logies-op-dreef'
  intent='originele WordPress pagina over studio'
```

---

## 4. Raw → wiki transformatie

De transformatie verwerkt bronbestanden naar Obsidian-klare wiki-pagina's.

### Wat er per bestand gebeurt

1. Originele frontmatter bewaren + `summary:` veld toevoegen
2. Booking-widgets, iframe-HTML en kalender-elementen verwijderen
3. `## Samenvatting` sectie toevoegen (2-4 zinnen)
4. Relevante termen omzetten naar `[[wikilinks]]`
5. `## Gerelateerde pagina's` sectie toevoegen
6. Schrijven naar `wiki/<project>/<zelfde pad>`

### Resultaat

```markdown
---
title: "Originele titel"
source_url: https://...
project: logies-op-dreef
type: accommodatie
indexed: 2026-04-11
summary: Compacte gelijkvloerse studio van 13m² in Driebergen. Vanaf €84/nacht.
---

# Studio Beneden

## Samenvatting
Studio Beneden is een compacte studio van 13m² in het centrum van [[driebergen|Driebergen]].
Eigen badkamer, kitchenette, espressomachine en 24/7 keyless check-in. Vanaf €84/nacht.

[schone content met [[wikilinks]] door de tekst]

## Gerelateerde pagina's
- [[studio-boven|Studio Boven]] – groter alternatief
- [[bed-and-breakfast|B&B]] – overzicht beide studio's
- [[driebergen|Driebergen]] · [[utrechtse-heuvelrug|Utrechtse Heuvelrug]]
```

### Opdracht aan Claude Code

Geef in een Claude Code sessie in de openclaw VM:

```
Verwerk alle bestanden in /home/agent/wiki/raw/<project>/ naar
/home/agent/wiki/wiki/<project>/.

Per bestand:
1. Bewaar de originele frontmatter, voeg een summary: veld toe (1-2 zinnen)
2. Verwijder booking-widgets, iframe-HTML en kalender-elementen
3. Voeg een ## Samenvatting sectie toe (2-4 zinnen kerninhoud)
4. Zet relevante termen om naar Obsidian wikilinks ([[slug|Naam]])
   - Gebruik de bestandsnaam zonder .md als slug
   - Voeg een weergavenaam toe na de pipe: [[studio-beneden|Studio Beneden]]
5. Voeg een ## Gerelateerde pagina's sectie toe met links naar
   gerelateerde pagina's gegroepeerd per categorie
6. Schrijf naar wiki/<project>/<zelfde relatieve pad als raw>

Maak ook een index.md in wiki/<project>/ met een overzicht van
alle pagina's georganiseerd per categorie met korte beschrijvingen.

Gebruik de llm-wiki MCP tools voor lezen en schrijven:
- lezen: llm-wiki__vault_read
- schrijven: llm-wiki__vault_write of llm-wiki__vault_create
- controleren: llm-wiki__vault_exists
```

### Wikilink-conventies

| Raw bestand | Slug | Wikilink |
|-------------|------|----------|
| `accommodatie/studio-beneden.md` | `studio-beneden` | `[[studio-beneden\|Studio Beneden]]` |
| `locaties/driebergen.md` | `driebergen` | `[[driebergen\|Driebergen]]` |
| `ervaringen/imkerij.md` | `imkerij` | `[[imkerij\|Imkerij]]` |

Gebruik altijd `[[slug|Weergavenaam]]`, nooit het volledige pad.

---

## 5. Praktijkvoorbeelden

### Vraag beantwoorden

```
gebruik qmd__query met
  searches=[
    {type:'lex', query:'studio prijs per nacht'},
    {type:'vec', query:'kosten overnachting faciliteiten beschikbaar'}
  ]
  intent='prijs en faciliteiten logies op dreef accommodaties'
```

### Specifiek document ophalen

```
gebruik qmd__get met file="logies-op-dreef/accommodatie/studio-beneden.md"
```

### Alle wiki-pagina's van een project

```
gebruik llm-wiki__vault_list met pad="/home/agent/wiki/wiki/logies-op-dreef"
```

### Nieuwe wiki-pagina schrijven

```
gebruik llm-wiki__vault_write met
  pad="/home/agent/wiki/wiki/logies-op-dreef/info/nieuwe-pagina.md"
  content="---\ntitle: ...\n---\n\n# Inhoud"
```

---

## 6. Aanbevolen skills om aan te laten maken

| Skill (slash command) | Waarde | Frequentie |
|-----------------------|--------|------------|
| `/wiki-zoek` | Zoekt in vault en beantwoordt vraag | Dagelijks |
| `/wiki-transformeer` | Transformeert raw/ naar wiki/ voor een project | Bij nieuwe content |
| `/wiki-voeg-toe` | Voegt nieuwe raw content toe aan vault | Bij nieuwe bronnen |
| `/wiki-rapport` | Genereert rapport op basis van vault-inhoud | Wekelijks |
| `/wiki-update` | Werkt bestaande wiki-pagina bij met nieuwe info | Ad-hoc |

---

## 7. Skill-prompts voor openclaw

Geef Claude Code de onderstaande prompts om nieuwe slash commands aan te laten maken. Skills worden opgeslagen als markdown bestanden in de `.claude/` map en zijn direct beschikbaar als `/skill-naam`.

### Kritieke technische context — verplicht meegeven aan elke skill

Elke skill-prompt bevat onderstaande technische context. Zonder dit gebruikt de skill verkeerde paden en werkt het niet.

```
TECHNISCHE CONTEXT VOOR DEZE SKILL:

Paden (Claude Code draait IN de openclaw VM):
- llm-wiki service draait als stdio proces in de VM
  → gebruik VM-paden: /home/agent/wiki/raw/...  en  /home/agent/wiki/wiki/...
  → NOOIT host-paden: /home/michiel/wiki-vault/... (die bestaan niet in de VM)
- qmd geeft paden terug als: logies-op-dreef/accommodatie/studio-beneden.md
  → deze werken direct in qmd__get zonder aanpassing

Collecties:
- logies-op-dreef       → ruwe WordPress-content (raw/)
- logies-op-dreef-wiki  → schone wiki met samenvattingen (wiki/) — aangeraden

Workflow zoeken → lezen:
1. qmd__query  → geeft paden + snippets terug
2. qmd__get    → geeft volledige tekst terug (gebruik pad uit stap 1)
3. llm-wiki__vault_read → alternatief voor lezen, geef VM-pad mee:
   /home/agent/wiki/wiki/<project>/<categorie>/<slug>.md

qmd index locatie in de VM: /home/agent/workspace/.qmd/index.sqlite
(aparte index van de host — moet apart bijgewerkt worden na nieuwe content)
```

### Skill 1: /wiki-zoek

```
Maak een nieuwe Claude Code skill aan als slash command /wiki-zoek.
Sla op als een markdown bestand in de juiste skills map voor dit project.

De skill doet het volgende:
1. Ontvang een vraag als argument: /wiki-zoek [vraag]
2. Gebruik qmd__query met zowel lex als vec zoekopdrachten
   - Formuleer 2-3 specifieke zoektermen op basis van de vraag
   - Geef altijd een duidelijke intent mee
3. Lees de top-3 meest relevante documenten via qmd__get
4. Beantwoord de vraag op basis van de opgehaalde inhoud
5. Toon gebruikte bronnen als wikilinks: [[slug|Naam]] (pad: ...)

Frontmatter:
name: wiki-zoek
description: >
  Zoekt in de wiki-vault via semantisch zoeken (qmd) en beantwoordt
  de vraag op basis van de gevonden documenten.

Voeg toe:
- Voorbeeld: /wiki-zoek wat kost studio beneden?
- Voorbeeld: /wiki-zoek welke workshops zijn er?
- Voorbeeld: /wiki-zoek hoe ver is het station?
```

### Skill 2: /wiki-transformeer

```
Maak een nieuwe Claude Code skill aan als slash command /wiki-transformeer.

De skill transformeert raw bestanden naar wiki-pagina's:
1. Ontvang projectnaam: /wiki-transformeer [projectnaam]
2. Gebruik llm-wiki__vault_list om raw/<project>/ in te lezen
3. Check via llm-wiki__vault_exists welke wiki-versies al bestaan
4. Voor elk nieuw te verwerken bestand:
   a. Lees via llm-wiki__vault_read
   b. Verwijder web-junk (widgets, iframes, kalenders)
   c. Voeg summary: toe aan frontmatter
   d. Schrijf ## Samenvatting (2-4 zinnen)
   e. Zet termen om naar [[slug|Naam]] wikilinks
   f. Voeg ## Gerelateerde pagina's toe
   g. Schrijf naar wiki/<project>/ via llm-wiki__vault_write
5. Maak of update index.md in wiki/<project>/
6. Rapporteer: N nieuwe pagina's, M al bestaande

Frontmatter:
name: wiki-transformeer
description: >
  Transformeert raw bronbestanden naar wiki-pagina's met wikilinks,
  samenvatting en gerelateerde pagina's. Slaat resultaat op in wiki/.
```

### Skill 3: /wiki-voeg-toe

```
Maak een nieuwe Claude Code skill aan als slash command /wiki-voeg-toe.

De skill voegt nieuwe raw content toe aan de vault:
1. Ontvang: URL of bestandspad als argument
   /wiki-voeg-toe [URL of pad] [projectnaam] [categorie]
2. Haal de content op (URL: fetch + html2text, bestand: lees direct)
3. Verwerk naar markdown met verplichte frontmatter:
   - title, source_url, source, project, type, indexed (vandaag)
4. Sla op als raw/<projectnaam>/<categorie>/<slug>.md
   - slug = bestandsnaam afgeleid van de titel (lowercase, koppeltekens)
5. Geef instructies voor re-indexering:
   "Voer uit op de host: cd ~/wiki-vault/raw/<project> && qmd update && qmd embed"
   "Herstart service: systemctl --user restart mcp-qmd"

Frontmatter:
name: wiki-voeg-toe
description: >
  Voegt nieuwe raw content (URL of bestand) toe aan de wiki-vault
  met correcte frontmatter. Geeft instructies voor qmd-herindexering.
```

### Skill 4: /wiki-rapport

```
Maak een nieuwe Claude Code skill aan als slash command /wiki-rapport.

De skill genereert een rapport op basis van vault-inhoud:
1. Ontvang: /wiki-rapport [onderwerp] voor [projectnaam]
2. Gebruik qmd__query met 3-5 zoekvragen die het onderwerp afdekken
3. Lees de top-10 meest relevante documenten via qmd__get
4. Schrijf een gestructureerd rapport:
   - Frontmatter: title, project, type: rapport, date, generated_by: claude
   - ## Inleiding (context en scope)
   - ## Bevindingen (per thema)
   - ## Conclusies
   - ## Bronnen (wikilinks naar gebruikte vault-pagina's)
5. Sla op als output/<projectnaam>/<datum>-<onderwerp>.md
   via llm-wiki__vault_write
6. Bevestig het pad waar het rapport opgeslagen is

Frontmatter:
name: wiki-rapport
description: >
  Genereert een rapport over een onderwerp op basis van vault-inhoud.
  Slaat resultaat op in output/ met wikilinks naar bronnen.
```

### Skill 5: /wiki-update

```
Maak een nieuwe Claude Code skill aan als slash command /wiki-update.

De skill werkt een bestaande wiki-pagina bij met nieuwe informatie:
1. Ontvang: /wiki-update [paginanaam of -pad] [nieuwe informatie of URL]
2. Lees de bestaande pagina via qmd__get of llm-wiki__vault_read
3. Haal nieuwe informatie op (als URL: fetch content)
4. Verwerk updates:
   - Werk de summary: in de frontmatter bij
   - Verwerk nieuwe informatie in de juiste secties
   - Voeg nieuwe wikilinks toe waar relevant
   - Update de indexed: datum
5. Schrijf de bijgewerkte pagina via llm-wiki__vault_write
6. Rapporteer wat er veranderd is

Frontmatter:
name: wiki-update
description: >
  Werkt een bestaande wiki-pagina bij met nieuwe informatie.
  Bewaart bestaande wikilinks en structuur.
```
