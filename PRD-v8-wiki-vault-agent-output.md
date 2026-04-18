# PRD-v8 — Wiki-Vault Agent Output Integratie

**Datum:** 2026-04-12
**Vorige PRD:** PRD-v7-wiki-llm-obsidian-integratie.md
**Status:** Implementatie gereed

---

## 1. Doel

Alle significante agent-outputs (wekelijkse audits, strategie-analyses, teamsessies, data syncs) worden automatisch als gestructureerde markdown-bestanden opgeslagen in de wiki-vault. Zo ontstaat een doorzoekbaar, permanent archief van beslissingen en inzichten dat door alle agents via qmd bevraagd kan worden — vanuit zowel de openclaw-omgeving als hermes.

**Kernprincipe:** *If it's not written to a file, it doesn't exist.*

---

## 2. Probleemstelling

- Wekelijkse audits (Elon, Gary, Warren) en teamsessies (BOT, Kompas, Blauwdruk) leveren waardevolle analyses op die nu **alleen naar Discord** gaan en daarna verdwijnen.
- Agents kunnen elkaars eerdere analyses niet raadplegen — ze moeten elk weekend opnieuw beginnen zonder context.
- De wiki-vault bevat nu alleen statische WordPress-content. Er is nog geen archief van agent-kennis en team-beslissingen.
- Hermes en openclaw draaien als parallelle omgevingen en schrijven nu nergens gedeeld naartoe.

---

## 3. Oplossing

Elke skill die een significante output produceert krijgt een **laatste stap** die de bevindingen als markdown naar `wiki-vault/agents/` schrijft via de `llm-wiki__vault_write` tool (beschikbaar in zowel openclaw als hermes via MCP).

---

## 4. Wiki-Vault Structuur Uitbreiding

Bestaande structuur blijft intact. Nieuwe tak:

```
wiki-vault/
├── raw/logies-op-dreef/          — bestaand (WordPress bronnen, immutable)
├── wiki/logies-op-dreef/         — bestaand (schone wiki met wikilinks)
├── output/                       — bestaand (ad-hoc rapporten)
└── agents/                       — NIEUW: automatische agent outputs
    ├── daily/                    — Dagelijkse executive syncs
    │   └── 2026-04-14.md
    ├── weekly/                   — Wekelijkse teamsessies
    │   ├── bot-overleg/          — Vrijdag BOT-overleg
    │   │   └── 2026-W16.md
    │   ├── kompas-sessie/        — Woensdag Kompas
    │   │   └── 2026-W16.md
    │   └── blauwdruk-sessie/     — Zondag Blauwdruk
    │       └── 2026-W16.md
    ├── elon/
    │   ├── tech-audit/           — Wekelijkse tech audits (aspect + week)
    │   │   └── 2026-W16-security.md
    │   ├── matomo-sync/          — Matomo data syncs
    │   │   └── 2026-04-09.md
    │   └── vikbooking-sync/      — VikBooking data syncs
    │       └── 2026-04-07.md
    ├── gary/
    │   └── content-strategy/     — Wekelijkse content-strategie analyses
    │       └── 2026-W15-brand-voice.md
    └── warren/
        └── revenue-strategy/     — Wekelijkse revenue-strategie analyses
            └── 2026-W15-boekingspatronen.md
```

### Naamgeving

| Type | Bestandsnaam | Voorbeeld |
|---|---|---|
| Wekelijks (vast) | `YYYY-WNN.md` | `2026-W16.md` |
| Wekelijks (met aspect) | `YYYY-WNN-<aspect-slug>.md` | `2026-W16-security.md` |
| Dagelijks | `YYYY-MM-DD.md` | `2026-04-14.md` |

---

## 5. Frontmatter Standaard

Elk agent-outputbestand krijgt deze frontmatter:

```yaml
---
title: "<beschrijvende titel>"
date: YYYY-MM-DD
week: "YYYY-WNN"           # alleen voor wekelijkse bestanden
agent: elon|gary|warren|muddy
type: tech-audit|content-strategy|revenue-strategy|bot-overleg|kompas-sessie|blauwdruk-sessie|matomo-sync|vikbooking-sync|daily-sync
aspect: "<aspect naam>"    # alleen voor roterende skills
project: logies-op-dreef
source: openclaw|hermes
---
```

---

## 6. Scope: Welke Skills Worden Bijgewerkt

### 6.1 OpenClaw Skills (workspace-elon, workspace-gary, workspace-warren, workspace)

| Skill | Agent | Pad | Nieuwe stap |
|---|---|---|---|
| `elon-tech-audit` | Elon | `agents/elon/tech-audit/YYYY-WNN-<aspect>.md` | Stap 5 |
| `gary-content-strategy` | Gary | `agents/gary/content-strategy/YYYY-WNN-<aspect>.md` | Stap 5 |
| `warren-revenue-strategy` | Warren | `agents/warren/revenue-strategy/YYYY-WNN-<aspect>.md` | Stap 5 |
| `weekly-bot-overleg` | Muddy | `agents/weekly/bot-overleg/YYYY-WNN.md` | Stap 5 |
| `weekly-kompas-sessie` | Muddy | `agents/weekly/kompas-sessie/YYYY-WNN.md` | Stap 5 |
| `weekly-blauwdruk-sessie` | Muddy | `agents/weekly/blauwdruk-sessie/YYYY-WNN.md` | Stap 5 |
| `matomo-traffic` | Elon | `agents/elon/matomo-sync/YYYY-MM-DD.md` | Stap 3 |
| `vikbooking-bookings` | Elon | `agents/elon/vikbooking-sync/YYYY-MM-DD.md` | Stap 3 |

### 6.2 Hermes Skills (`.hermes/skills/logies-op-dreef/`)

Dezelfde skills in hermes krijgen identieke wiki-save stap. Paths zijn gelijk omdat beide omgevingen dezelfde wiki-vault benaderen via `llm-wiki__vault_write` (MCP endpoint `http://10.0.2.1:8766/mcp` voor hermes).

---

## 7. Implementatie per Omgeving

### OpenClaw VM
- Schrijven via: `llm-wiki__vault_write` (tool beschikbaar via MCP in de VM)
- VM-pad: `/home/agent/wiki/agents/...`
- Dit is de virtiofs-mount van `/home/michiel/wiki-vault/agents/` op de host

### Hermes
- Schrijven via: `llm-wiki__vault_write` (MCP HTTP endpoint `http://10.0.2.1:8766/mcp`)
- Zelfde paden als openclaw: `/home/agent/wiki/agents/...` (de MCP server hanteert dit als host-pad `~/wiki-vault/agents/...`)

**Beide omgevingen schrijven naar hetzelfde bestand.** Als hermes en openclaw dezelfde wekelijkse skill uitvoeren, overschrijft de laatste run het bestand — dat is gewenst gedrag (de rijkste output wint). In de praktijk draaien hermes en openclaw de zware wekelijkse skills op verschillende tijden (openclaw eerder, hermes later met `source: hermes` in de frontmatter).

---

## 8. Template per Skill Type

### 8.1 Strategie-audit (elon/gary/warren)

```markdown
---
title: "<Agent> <Type> W<NN> — <Aspect>"
date: YYYY-MM-DD
week: "YYYY-WNN"
agent: elon
type: tech-audit
aspect: "<aspect slug>"
project: logies-op-dreef
source: openclaw
---

# <Agent> <Type> — <Aspect> (Week <NN>)

## Samenvatting
<2-3 zinnen kernbevinding>

## Bevindingen
<gestructureerde bevindingen per subonderdeel>

## Lab Decision Board Proposals
- **P1:** <voorstel> — <motivatie>
- **P2:** <voorstel> — <motivatie>

## Actiepunten
- [ ] <actie> (@<agent>)

## Databronnen
- qmd queries: <collectie(s) gebruikt>
- Data: <matomo/vikbooking/wp-api indien gebruikt>
```

### 8.2 Teamsessie (muddy)

```markdown
---
title: "<Sessienaam> — Week <NN>"
date: YYYY-MM-DD
week: "YYYY-WNN"
agent: muddy
type: bot-overleg|kompas-sessie|blauwdruk-sessie
project: logies-op-dreef
source: openclaw
---

# <Sessienaam> — Week <NN>

## Input van het team
**<Agent 1>:** <kernpunten>
**<Agent 2>:** <kernpunten>

## Discussie & Conclusies
<beslissingen en richting>

## Lab Decision Board
<goedgekeurde en ingediende proposals>

## Acties voor komende week
- [ ] <actie> (@<agent>)
```

### 8.3 Data sync (elon)

```markdown
---
title: "Matomo Sync — YYYY-MM-DD"
date: YYYY-MM-DD
agent: elon
type: matomo-sync
project: logies-op-dreef
source: openclaw
---

# Matomo Traffic Sync — YYYY-MM-DD

## Snapshots
- Totaal opgeslagen: <N>
- Nieuwe snapshot: <timestamp>

## Kernmetrics (laatste 30 dagen)
- Bezoeken: <N>
- Bounce rate: <%>
- Top pagina: <pagina>
```

---

## 9. qmd Collectie voor agents/

Na implementatie moet de `agents/` map doorzoekbaar worden via qmd. Dit laat agents elkaars eerdere analyses ophalen.

### Eenmalig aanmaken (op de host)

```bash
cd ~/wiki-vault/agents
~/.npm-global/bin/qmd collection add . --name agents-output
~/.npm-global/bin/qmd embed
systemctl --user restart mcp-qmd
```

### In de openclaw VM (eenmalig)

```bash
cd /home/agent/wiki/agents
qmd collection add . --name agents-output
qmd embed
```

### Wekelijks bijwerken

De `wiki-onderhoud-weekly` skill krijgt een extra stap die na de audit ook de agents-collectie bijwerkt:

```bash
cd /home/agent/wiki/agents
qmd update && qmd embed
```

### Gebruik in skills

Agents kunnen elkaars eerdere analyses ophalen:

```
qmd__query collection="agents-output"
  searches=[{type:"vec", query:"gary content strategie brand voice vorige week"}]
  intent="vorige content-strategie analyse van Gary"
```

---

## 10. Geen wijzigingen aan

- `raw/` en `wiki/` mappen — die blijven voor WordPress/kennisbank content
- `cron/jobs.json` — geen nieuwe cron jobs nodig; de save-stap zit in bestaande skills
- `openclaw.json` — geen wijzigingen
- Daily-executive-sync — wordt voorlopig niet bijgewerkt (heeft actieve auth errors)

---

## 11. Implementatievolgorde

1. ✅ Mapstructuur aanmaken (`wiki-vault/agents/` tree)
2. ✅ OpenClaw skills updaten (8 skills)
3. ✅ Hermes skills updaten (8 skills)
4. ✅ qmd `_meta.json` aanmaken voor agents/ collectie
5. `wiki-onderhoud-weekly` update met qmd re-index stap (volgende iteratie)

---

## 12. Acceptatiecriteria

- Na uitvoering van `elon-tech-audit` bestaat `wiki-vault/agents/elon/tech-audit/YYYY-WNN-*.md`
- Na uitvoering van `weekly-bot-overleg` bestaat `wiki-vault/agents/weekly/bot-overleg/YYYY-WNN.md`
- Bestand heeft correcte frontmatter en leesbare structuur
- `qmd__query collection="agents-output"` retourneert relevante resultaten na indexering
- Zowel openclaw als hermes schrijven naar hetzelfde pad
