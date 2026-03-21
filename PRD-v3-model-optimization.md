# PRD — Model Optimalisatie: Tiering, Routing & Cost Visibility
**Status:** Draft
**Datum:** 2026-03-21
**Scope:** Model-selectie strategie, compaction, dedicated agents, dashboard cost tracking

---

## 1. Huidige situatie (baseline)

### 1.1 Bevindingen uit de analyse

| Onderdeel | Huidige staat | Actie nodig |
|---|---|---|
| Prompt caching | ✅ Automatisch actief via Anthropic API | Geen |
| Compaction mode | `safeguard` — compacteert alleen bij overflow-risico | Evalueren |
| Model per agent | Niet geconfigureerd — valt terug op Openclaw default | Configureren |
| Routing logica | Coordinator beslist zelf welke agent voor welke taak | Expliciet instrueren |
| Cron/memory tasks | Draaien op coordinator (Sonnet) — overdone | Dedicated Haiku agent |
| Dashboard cost visibility | Geen — cacheRead/cacheWrite beschikbaar in sessions.json maar niet zichtbaar | Toevoegen |

### 1.2 Hoe de Orchestrator nu agents kiest

De coordinator spawnt agents op basis van taakinstructies in AGENTS.md en zijn eigen redenering. **Er is geen expliciete model-bewuste routing.** De coordinator weet niet dat editor goedkoper is dan writer — hij kiest op basis van taakinhoud en wat in AGENTS.md staat. Dit betekent:

- Eenvoudige opmaaktaken kunnen op de dure writer-agent belanden
- Alle cron jobs draaien op coordinator (Sonnet), ook triviale memory-extracties
- Er is geen escalatiepad naar Opus voor complexe taken

**Conclusie:** Routing moet expliciet geconfigureerd worden via AGENTS.md + model-toewijzing per agent in `openclaw.json`.

---

## 2. Doelstelling

1. **Kostenreductie** — 40-60% lagere tokenkosten door het juiste model voor de juiste taak
2. **Kwaliteitsverhoging** — Complexe taken worden nooit afgekapt door een te klein model
3. **Zichtbaarheid** — Dashboard toont real-time wat het systeem kost en hoe efficiënt het werkt
4. **Toekomstbestendigheid** — Tier 1 klaar voor Ollama (lokaal model) zonder architectuurwijzigingen

---

## 3. Model Tier Architectuur

```
┌─────────────────────────────────────────────────────────────────┐
│  TIER 4 — Opus 4.6                                              │
│  "Big Brain" — alleen bij expliciete escalatie                  │
│  Triggers: redenering mislukt, diepgaande ethische analyse,     │
│  complexe multi-stap planning, contra-intuïtieve besluiten      │
│  Kosten: hoogst                                                 │
├─────────────────────────────────────────────────────────────────┤
│  TIER 3 — Sonnet 4.6  ← het werkpaard                          │
│  coordinator, writer, researcher                                │
│  Gebruik: complex redeneren, schrijven, multi-stap research,    │
│  orchestratie, project planning, code review                    │
│  Kosten: middel-hoog                                            │
├─────────────────────────────────────────────────────────────────┤
│  TIER 2 — Haiku 4.5                                             │
│  editor, memory-agent                                           │
│  Gebruik: formatteren, samenvatten, data extractie,             │
│  memory schrijven, routinematige cron tasks, eenvoudige edits   │
│  Kosten: laag                                                   │
├─────────────────────────────────────────────────────────────────┤
│  TIER 1 — Ollama (lokaal, toekomstig)                           │
│  classificatie, routing beslissingen, template invulling,       │
│  eenvoudige yes/no checks, geen privacy-gevoelige data          │
│  Kosten: geen API-kosten                                        │
└─────────────────────────────────────────────────────────────────┘
```

### 3.1 Agent → Tier mapping

| Agent | Tier | Model | Reden |
|---|---|---|---|
| `coordinator` | 3 | `claude-sonnet-4-6` | Orchestreert, beslist, complexe routing |
| `writer` | 3 | `claude-sonnet-4-6` | Lange schrijftaken, creatief, meerstaps |
| `researcher` | 3 | `claude-sonnet-4-6` | Multi-stap research, bronanalyse |
| `editor` | 2 | `claude-haiku-4-5-20251001` | Formatteren, korrigeren, korte edits |
| `memory-agent` | 2 | `claude-haiku-4-5-20251001` | Samenvatten, extractie, memory schrijven |
| `escalation-agent` | 4 | `claude-opus-4-6` | Alleen bij expliciete ESCALATE_TO_OPUS trigger |

### 3.2 Escalatie protocol

De coordinator krijgt een expliciet escalatie-protocol in AGENTS.md:

```
## Model Escalatie Protocol

Kies de goedkoopste agent die de taak aankan:

TIER 2 (editor of memory-agent) — gebruik voor:
- Tekst formatteren, opschonen, samenvatten
- Data uit tekst extraheren (namen, datums, aantallen)
- Korte edits op bestaande content
- Alle memory-gerelateerde taken
- Routine cron-taken

TIER 3 (writer of researcher) — gebruik voor:
- Artikelen schrijven of onderzoek uitvoeren
- Multi-stap analyses
- Complexe redeneerketens
- Nieuwe content creëren

ESCALEER NAAR TIER 4 (escalation-agent) ALLEEN als:
- Een Tier 3 agent 2x hetzelfde probleem niet kan oplossen
- De taak diepgaand ethisch redeneren vereist
- De instructie expliciet "gebruik je beste denkvermogen" bevat
- Zeg: "ESCALATE_TO_OPUS: [korte reden]" en spawn escalation-agent
```

---

## 4. Compaction strategie

Huidige mode: `safeguard` — compacteert alleen bij overflow-risico.

**Advies: behoud `safeguard` als standaard.** Redenen:
- `safeguard` voorkomt onverwacht informatieverlies midden in een taak
- Context-overflow is het echte risico dat je wil mitigeren
- `default` kan context inkorten op momenten dat de agent juist de volledige history nodig heeft

**Overweeg `default` alleen voor de `memory-agent`** — die heeft nooit diepe conversatiecontext nodig en profiteert meer van een compacte context.

Per-agent compaction override in `openclaw.json`:
```json
{
  "agents": {
    "memory-agent": {
      "compaction": { "mode": "default" }
    }
  }
}
```

---

## 5. Dedicated Memory-Agent

### 5.1 Rationale

Alle cron jobs (memory-extractor, toekomstige admin-taken) draaien nu op de `coordinator` agent (Sonnet). Dit is 3-5x duurder dan nodig voor samenvatten en schrijven naar markdown files.

### 5.2 Configuratie

Nieuwe agent in `openclaw.json`:
```json
{
  "id": "memory-agent",
  "name": "Memory Agent",
  "model": "claude-haiku-4-5-20251001",
  "description": "Dedicated agent voor memory-operaties, extractie en cron-taken",
  "compaction": { "mode": "default" }
}
```

Workspace instructies (`workspace-memory-agent/AGENTS.md`):
```markdown
# Memory Agent

Je bent een dedicated memory-beheerder. Je taken zijn:
- Sessie-logs samenvatten en wegschrijven naar memory/YYYY-MM-DD.md
- Feiten extraheren en opslaan in facts.db
- MEMORY.md bijwerken met significante nieuwe inzichten
- Routine cron-taken uitvoeren

Schrijfstijl: beknopt, bullets, geen uitleg — alleen de feiten.
```

### 5.3 Cron job update

De `memory-extractor` cron wijst naar `memory-agent` i.p.v. `coordinator`:
```json
{
  "id": "memory-extractor",
  "payload": {
    "agentId": "memory-agent",
    ...
  }
}
```

---

## 6. Dashboard Uitbreidingen — Cost & Efficiency Visibility

Dit is de meest waardevolle toevoeging voor Mission Control: inzicht in wat het systeem kost en hoe efficiënt het werkt.

### 6.1 Dashboard Tab — Cost Widget

Nieuw paneel naast de bestaande stats bar:

```
┌─────────────────────────────────────────────────┐
│  💰 Token Efficiency                            │
│                                                 │
│  Cache hit rate:  78%  ████████░░               │
│  Tokens today:    142k  (est. $0.18)            │
│                                                 │
│  coordinator  ███████  48k  Sonnet              │
│  writer       █████    32k  Sonnet              │
│  researcher   ████     28k  Sonnet              │
│  editor       ██       14k  Haiku               │
│  memory-agent █        8k   Haiku               │
└─────────────────────────────────────────────────┘
```

**Data bron:** `sessions.json` bevat al `cacheRead`, `cacheWrite`, `inputTokens`, `outputTokens` per sessie.

**Berekening cache hit rate:**
```
cacheRead / (cacheRead + inputTokens) × 100%
```

**Kostenschatting (Sonnet 4.6 prijzen):**
```
input:   $3 / 1M tokens
output:  $15 / 1M tokens
cache:   $0.30 / 1M tokens (10x goedkoper)
```

### 6.2 Sessions — Model Badge

Elke sessie in het dashboard toont een model-badge:
- `S4.6` (Sonnet, blauw)
- `H4.5` (Haiku, groen)
- `O4.6` (Opus, rood)

Gebaseerd op `model` veld in de session data.

### 6.3 Memory Tab — Agent Badge

Memory Journal toont welk agent een sessie uitvoerde, inclusief model-tier:
- Sessies van `memory-agent` krijgen een groen "H" badge (Haiku)
- Sessies van `coordinator` krijgen een blauw "S" badge (Sonnet)
- Geëxtraheerde daily notes tonen duidelijk "door memory-agent"

### 6.4 New API: `/api/stats/tokens`

```typescript
GET /api/stats/tokens?days=7
→ {
    totalInputTokens: number,
    totalOutputTokens: number,
    totalCacheRead: number,
    totalCacheWrite: number,
    cacheHitRate: number,      // 0-1
    estimatedCostUSD: number,
    byAgent: [{
      agent: string,
      model: string,
      tier: number,
      inputTokens: number,
      outputTokens: number,
      cacheRead: number,
      sessions: number,
    }],
    byDay: [{ date: string, tokens: number, cost: number }]
  }
```

---

## 7. Implementatiefases

### Fase A — Model configuratie (30 min, Claude in VM)
1. Update `openclaw.json`: voeg model per agent toe
2. Maak `memory-agent` aan met Haiku
3. Update `memory-extractor` cron om `memory-agent` te gebruiken
4. Voeg escalatie-protocol toe aan `AGENTS.md`
5. Herstart gateway

**Acceptatiecriterium:** `journalctl -u openclaw-gateway` toont "coordinator: claude-sonnet-4-6" en "editor: claude-haiku-4-5-20251001"

### Fase B — Dashboard token widget (dashboard rebuild)
1. Nieuwe `/api/stats/tokens` route
2. Cost widget op Dashboard tab
3. Model badges op sessies
4. Herstart dashboard

**Acceptatiecriterium:** Dashboard tab toont cache hit rate en geschatte kosten

### Fase C — Memory tab agent badges (dashboard rebuild)
1. Model-tier badges in Journal sessies
2. Memory-agent sessies visueel onderscheiden van coordinator sessies

### Fase D — Compaction tuning (optioneel, na observatie)
1. Monitor of `safeguard` voldoende is
2. Zet `memory-agent` op `default` compaction
3. Evalueer na 1 week of context-gebruik verbeterd is

### Fase E — Ollama Tier 1 (toekomstig)
1. Installeer Ollama in de VM
2. Configureer een `classifier-agent` met lokaal model
3. Router simpele classificatie-taken daarheen
4. Geen API-kosten voor routing-beslissingen

---

## 8. Risico's en mitigaties

| Risico | Impact | Mitigatie |
|---|---|---|
| Haiku mist context bij complexe editor-taak | Slechte output kwaliteit | Coordinator instrueert expliciet: "als output onvoldoende, spawn writer" |
| Escalation-agent te vaak aangeroepen | Hoge Opus kosten | Dashboard toont Opus-gebruik — alert bij > 5% van sessies |
| memory-agent verliest context bij `default` compaction | Incomplete extractie | Monitor memory/YYYY-MM-DD.md kwaliteit eerste week |
| Model pricing verandert | Kostenschattingen kloppen niet | Prijzen als constanten in de API route, makkelijk te updaten |

---

## 9. Openstaande vragen voor implementatie

1. **Openclaw model ID's** — zijn `claude-sonnet-4-6` en `claude-haiku-4-5-20251001` de exacte model strings die Openclaw accepteert? Controleer via `openclaw config schema`.

2. **Escalation-agent spawning** — kan de coordinator een agent spawnen die hij zelf niet is? Of moet de escalation-agent een vaste subagent zijn in de binding?

3. **Model veld in sessions.json** — is `model` een top-level veld in de session data, of zit het genest? Dit bepaalt of de badge-implementatie in het dashboard eenvoudig is.

Vraag dit aan Claude in de VM voordat je Fase A start.
