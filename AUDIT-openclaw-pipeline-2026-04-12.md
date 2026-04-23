# Pipeline Audit Rapport
## OpenClaw C-Suite Multi-Agent Pipeline — Logies op Dreef B&B
**Datum:** 2026-04-12
**Auditor:** Claude Code (claude-sonnet-4-6)
**Versie openclaw.json:** `lastTouchedVersion: 2026.4.9` (2026-04-10T23:03:16Z)
**Scope:** Volledige multi-agent pipeline, 16 cron jobs, 5 agents, MCP-integraties, memory-architectuur, handoffs

---

## Inhoudsopgave

1. [Fase 1 — Architectuuroverzicht & Componentinventarisatie](#fase-1)
2. [Fase 2 — Agent Configuratieanalyse](#fase-2)
3. [Fase 3 — Cron Job Audit](#fase-3)
4. [Fase 4 — Memory Architectuur](#fase-4)
5. [Fase 5 — Handoffs & Inter-Agent Communicatie](#fase-5)
6. [Fase 6 — MCP Integraties & Tooling](#fase-6)
7. [Fase 7 — Infrastructuur & Beveiliging](#fase-7)
8. [Fase 8 — Bekende Problemen & Foutanalyse](#fase-8)
9. [Fase 9 — Parallelle Omgevingen & Interferentierisico](#fase-9)
10. [Synthese: Prioriteitenmatrix & Aanbevelingen](#synthese)

---

## Fase 1 — Architectuuroverzicht & Componentinventarisatie {#fase-1}

### 1.1 Topologie

De pipeline opereert als een gelaagde multi-agent architectuur in een NixOS MicroVM, met de openclaw gateway op poort 18789. De host-naar-VM montage maakt de workspace en wiki-vault beschikbaar als virtiofs volumes:

```
Host: /home/michiel/openclaw-workspace/ → VM: /home/agent/workspace/
Host: /home/michiel/wiki-vault/         → VM: /home/agent/wiki/
```

De virtiofsd sockets zijn actief (`openclaw-agent-virtiofs-*.sock.pid` bestanden aanwezig). De config-health monitor bewaakt twee configuratiebestanden: `/etc/openclaw/openclaw.json` (onveranderd, 256 bytes, hash stabiel) en `/home/agent/workspace/.openclaw/openclaw.json` (6655 bytes, recentst geobserveerd 2026-04-12T17:48:55Z).

### 1.2 Componentinventaris

| Component | Status | Locatie |
|-----------|--------|---------|
| openclaw gateway | Actief (poort 18789) | VM |
| MCP llm-wiki | **OFFLINE** (zie Fase 6) | VM stdio |
| MCP qmd | **OFFLINE** (zie Fase 6) | VM stdio |
| Lab Decision Board API | Actief (127.0.0.1:3333) | Host |
| Discord bot | Actief (token via OPENCLAW_DISCORD_TOKEN) | VM |
| Chromium browser | Actief (headless, CDP poort 18800) | VM |
| Ollama (qwen3.5:9b) | Actief (10.0.1.1:11434) | Host |
| Relayplane proxy | Actief (10.0.2.1:4100/v1) | Host |
| WordPress REST API | Actief (logiesopdreef.nl) | Extern |
| Matomo Brain Bridge | Actief | Extern |

### 1.3 Agentinventaris

Vijf agents gedefinieerd in `openclaw.json`:

| Agent | Model | Workspace | Subagents allowed |
|-------|-------|-----------|-------------------|
| muddy (default) | localproxy/auto | workspace/ | elon, gary, warren, memory-agent |
| elon | localproxy/auto | workspace-elon/ | skill-manager |
| gary | localproxy/auto | workspace-gary/ | (leeg) |
| warren | localproxy/auto | workspace-warren/ | (leeg) |
| memory-agent | ollama/qwen3.5:9b | workspace-memory-agent/ | (leeg) |

Opvallend: er bestaat ook een `agents/main/` directory met eigen `auth-profiles.json` en `models.json`. Deze agent is **niet gedefinieerd** in de `agents.list` van `openclaw.json`, maar is wel zichtbaar in het filesystem en veroorzaakt de daily-executive-sync auth-fout (zie Fase 8).

### 1.4 Skillsinventaris per agent

**Elon (11 skills):** elon-tech-audit, matomo-traffic, skill-building, vikbooking-bookings, warren-revenue-analytics, wiki-ingest, wiki-onderhoud, wiki-raw-check, wiki-raw-transformeren, wiki-wp-freshness, wiki-zoek-antwoord

**Gary (2 skills):** gary-content-insights, gary-content-strategy

**Warren (2 skills + shared):** warren-revenue-strategy, warren-revenue-analytics (ook in Elon's workspace)

**Muddy (9 skills):** analytics-query, elon-tech-audit, gary-content-strategy, warren-revenue-analytics, warren-revenue-strategy, weekly-blauwdruk-sessie, weekly-bot-overleg, weekly-kompas-sessie, wiki-zoek-antwoord

**Observatie:** Muddy's workspace bevat kopieën van agent-specifieke skills (elon-tech-audit, gary-content-strategy, warren-revenue-analytics, warren-revenue-strategy). Dit suggereert dat Muddy deze skills kan uitvoeren zonder te delegeren — wat haaks staat op het protocol in AGENTS.md dat stelt dat Muddy altijd delegeert.

---

## Fase 2 — Agent Configuratieanalyse {#fase-2}

### 2.1 Muddy (COO — Orchestrator)

**Configuratie:**
- `default: true` — ontvangt alle Discord-berichten via channel binding
- `params.thinking: "low"` — afwijkend van `thinkingDefault: "medium"` in defaults
- Workspace: workspace/ (bootstrap: SOUL.md + AGENTS.md bij session startup)
- sessionKey voor heartbeat-gerelateerde cron jobs: `agent:muddy:cron:82e3979f-...`

**Afwijking thinking:** Muddy als orchestrator krijgt `thinking: "low"` terwijl subagents de default `"medium"` krijgen. Dit is een bewuste keuze (orchestrators hoeven minder te redeneren) maar creëert een risico: complexe delegatiebeslissingen (zoals multi-ronde executive meetings) kunnen suboptimale routing opleveren.

**AGENTS.md analysenote:** Het protocol maakt onderscheid tussen `sessions_spawn` (fire & forget), `sessions_send` (naar actieve sessie) en c-suite-chat.jsonl (async kanaal). De bootstra instructies zijn uitgebreid — 340 regels AGENTS.md — en passen binnen de `bootstrapMaxChars: 20000` limiet. Totale bootstraplimiet is 80000 tekens; Muddy's workspace laadt SOUL.md + AGENTS.md + memory files, ruim binnen limiet.

**Boundary issue:** AGENTS.md Regel 1 stelt "Muddy delegeert ALTIJD — ook simpele taken." Maar Muddy's workspace bevat skills zoals `elon-tech-audit` en `gary-content-strategy`, die Muddy in principe zelf kan uitvoeren. Geen mechanisme voorkomt dit per configuratie.

### 2.2 Elon (CTO)

**Configuratie:**
- `subagents.allowAgents: ["skill-manager"]` — mag skill-manager spawnen
- skill-manager is **niet gedefinieerd** in agents.list — dit is een ghost reference
- 11 skills in workspace-elon/skills/
- Bootstrap: SOUL.md, AGENTS.md, IDENTITY.md, HEARTBEAT.md, MEMORY.md, TOOLS.md, USER.md

**skill-manager ghost reference:** Elon's config verwijst naar `skill-manager` als toegestane subagent, maar deze agent bestaat niet in `openclaw.json`. Als Elon probeert skill-manager te spawnen, zal dit falen met een onbekende-agent fout. Vermoedelijk een overblijfsel van een eerdere architectuurversie.

**Skill-opbouw observatie:** De wiki-raw-check en wiki-onderhoud skills vereisen llm-wiki MCP (offline). Beide jobs timen out op maandag (zie Fase 3 en Fase 8).

### 2.3 Gary (CMO)

**Configuratie:**
- `subagents.allowAgents: []` — geen subagents
- 2 skills, maar gary-content-strategy references `qmd__query` en `qmd__get` tools
- qmd MCP server is **niet geregistreerd** in `.mcp.json` (alleen context7 en llm-wiki)

**qmd ontbreekt in .mcp.json:** De gary-content-strategy skill roept `qmd__query collection="logies-op-dreef-wiki"` en `qmd__query collection="logies-op-dreef"` aan. De .mcp.json op workspace-niveau bevat alleen `context7` en `llm-wiki`. Er is geen qmd MCP server geconfigureerd. Dit betekent dat de skill een tool aanroept die niet beschikbaar is — stille failure of graceful degradation is niet gedocumenteerd.

### 2.4 Warren (CRO)

**Configuratie:**
- `subagents.allowAgents: []` — geen subagents
- warren-revenue-strategy roept ook `qmd__query` aan via hetzelfde probleem als Gary
- Workspace bevat reports/, skills/, en data/ subdirectories

### 2.5 Memory-Agent

**Configuratie:**
- Model: `ollama/qwen3.5:9b` — lokaal model, geen external API calls
- Workspace: workspace-memory-agent/ — alleen basis bootstrap bestanden (geen skills)
- Cron: geheugen-extractie dagelijks 07:00, isolated session, timeout 300s
- Model fallback: `deepseek/deepseek-chat` — model context switch bij failover

**Modelkloof:** Memory-agent draait op qwen3.5:9b (9B parameters, lokaal) terwijl alle andere agents op localproxy/auto (via Relayplane, vermoedelijk een frontier model) draaien. Voor een rol die kwalitatieve samenvatting en geheugenextractie vereist, is dit een significante capaciteitskloof. Het risico is dat geheugenextractie oppervlakkiger is dan nodig.

**Fallback mismatch:** De fallback `deepseek/deepseek-chat` in het job payload contrasteert met het lokale Ollama model. Bij failover van Ollama gaat geheugenextractie naar DeepSeek — een extern model met andere privacy-implicaties voor bedrijfsdata.

---

## Fase 3 — Cron Job Audit {#fase-3}

### 3.1 Overzicht (16 jobs)

| Status | Job naam | Agent | Schedule | Timeout | Errors |
|--------|----------|-------|----------|---------|--------|
| OK | task-checker | muddy | `*/30 7-18 * * *` | 180s | 0 |
| OK | heartbeat | muddy | `0,55 7-18 * * *` | 120s | 0 |
| **KAPOT** | daily-executive-sync | muddy | `30 7 * * 1-5` | 300s | **4** |
| OK | geheugen-extractie | memory-agent | `0 7 * * *` | 300s | 0 |
| OK | wiki-wp-freshness | elon | `0 7 * * 1` | 120s | 0 |
| **TIMEOUT** | wiki-raw-check | elon | `30 7 * * 1` | 300s | **1** |
| **TIMEOUT** | wiki-onderhoud | elon | `0 8 * * 1` | 600s | **1** |
| OK | vikbooking-weekly-sync | elon | `0 8 * * 1` | (geen) | 0 |
| OK | matomo-weekly-sync | elon | `0 8 * * 3` | (geen) | 0 |
| OK | warren-revenue-strategy-weekly | warren | `0 14 * * 3` | 600s | 0 |
| OK | weekly-kompas-sessie | muddy | `0 16 * * 3` | 600s | 0 |
| OK | gary-content-strategy-weekly | gary | `0 14 * * 5` | 300s | 0 |
| OK | weekly-bot-overleg | muddy | `0 16 * * 5` | 600s | 0 |
| OK | elon-tech-audit-weekly | elon | `0 14 * * 0` | 300s | 0 |
| OK | weekly-blauwdruk-sessie | muddy | `0 16 * * 0` | 600s | 0 |
| OK | weekly-planning | muddy | `30 8 * * 0` | 600s | 0 |

**Foutpercentage: 3/16 jobs (18.75%)** in error-toestand.

### 3.2 Maandagochtend Congestie

De maandag bevat de hoogste cron-dichtheid van de week. Met `maxConcurrentRuns: 2` is er een congestierisico:

```
07:00 — geheugen-extractie (memory-agent, 300s) + wiki-wp-freshness (elon, 120s)  [= 2, AT LIMIT]
07:30 — daily-executive-sync (muddy, 300s) + wiki-raw-check (elon, 300s)          [= 2, AT LIMIT]
08:00 — vikbooking-weekly-sync (elon, geen) + wiki-onderhoud (elon, 600s)          [= 2, AT LIMIT]
```

Als geheugen-extractie langer duurt dan 30 minuten (het heeft 155s gemiddeld), overlappen 07:00 en 07:30 slots niet. Maar als het systeem om 07:30 nog steeds 2 actieve jobs heeft, worden daily-executive-sync en wiki-raw-check **vertraagd** door de queue. Dit is een **structureel knelpunt**, niet alleen een toevallige fout.

Bovendien: bij `wakeMode: "now"` worden gemiste jobs direct ingehaald bij herstart van de gateway. Als de VM na een weekend herstart, kunnen alle maandagochtend-jobs tegelijk vuren — wat de queue met 8 jobs per minuut kan overspoelen.

### 3.3 Dubbele Heartbeat

De native heartbeat config in `openclaw.json`:
```json
"heartbeat": {
  "every": "55m",
  "activeHours": { "start": "07:00", "end": "19:00", "timezone": "Europe/Amsterdam" },
  "model": "localproxy/auto",
  "target": "last",
  "directPolicy": "allow"
}
```

En in `jobs.json` ook een `heartbeat` cron job (id: `82e3979f-...`):
```json
"schedule": { "kind": "cron", "expr": "0,55 7-18 * * *" },
"payload": { "message": "HEARTBEAT", "timeoutSeconds": 120 }
```

Dit zijn twee mechanismes voor dezelfde functie. De native heartbeat gebruikt `target: "last"` (stuurt naar de laatste actieve sessie) terwijl de cron heartbeat een isolated session opent. De cron heartbeat heeft ook een hardcoded `sessionKey: "agent:muddy:discord:channel:1484546226262249622"` — dat is het #daily-digest kanaal. Dit leidt tot heartbeat-berichten die in een Discord channel context gaan in plaats van de actuele werksessie.

**Bewijs van impact:** daily-executive-sync heeft `sessionKey: "agent:muddy:cron:82e3979f-91dc-4037-a91f-de808054a9a7"` — dit is exact de session-key van de heartbeat cron job. De executive sync is dus gekoppeld aan de heartbeat's session context, wat de auth-fout mede kan verklaren (verkeerd agent-context gepickt).

### 3.4 Timeouts zonder expliciete waarde

De matomo-weekly-sync en vikbooking-weekly-sync hebben **geen `timeoutSeconds`** in hun payload. Ze draaien respectievelijk 68s en 102s. Zonder timeout geldt de default `subagents.runTimeoutSeconds: 300`. Dit is functioneel maar onexpliciet — als de data-ophaling ooit trager wordt, is er geen configureerbare failsafe.

### 3.5 Timeout-sizing Analyse

```
elon-tech-audit-weekly: 279s bij timeout 300s → 93% benutting [KRITIEK RISICO]
weekly-kompas-sessie:   358s bij timeout 600s → 60% benutting [normaal]
wiki-raw-check:         180s bij timeout 300s → timed out (duurde langer dan 180s)
wiki-onderhoud:         300s bij timeout 600s → timed out (duurde langer dan 300s)
```

elon-tech-audit-weekly draait op 93% van zijn timeout. Eén extra stap (bijv. een extra API call bij aspect 7: Integraties) en de job timet uit. Timeout moet omhoog naar minimaal 450s.

---

## Fase 4 — Memory Architectuur {#fase-4}

### 4.1 Memory Lagen

De pipeline hanteert vier geheugenlagen:

**Laag 1 — Session-lokaal (ephemeer)**
Elke cron job draait als `sessionTarget: "isolated"`. Er is geen session continuity tussen runs. Informatie uit de vorige uitvoering is alleen beschikbaar via bestanden. Dit is de juiste keuze voor autonome taken, maar betekent dat agents bij elke run opnieuw hun context opbouwen.

**Laag 2 — Workspace Bootstrap (persistent)**
Elke agent laadt bij session startup zijn workspace-bestanden:
- SOUL.md (identiteit), AGENTS.md (protocol), MEMORY.md (kortetermijn), memory/YYYY-MM-DD.md (dagelijks)
- Muddy's AGENTS.md: 340 regels — goed gedocumenteerd
- Elon's workspace: veel bestanden, waaronder audit logs, reports, scripts
- `bootstrapMaxChars: 20000` per bestand, `bootstrapTotalMaxChars: 80000` totaal

**Laag 3 — Native Memory Search (semantisch)**
`agents.defaults.memorySearch.enabled: true, provider: "gemini"` — semantisch zoeken in opgeslagen memories via Gemini embeddings. Dit vereist een externe Gemini API call voor elke memory search bij session startup. Gemini is nergens geconfigureerd als provider in `openclaw.json` (geen entry in `models.providers`), wat vragen oproept over authenticatie.

**Laag 4 — Gedeeld C-Suite Chat**
`/home/agent/workspace/.openclaw/workspace/c-suite-chat.jsonl` — 0 bytes? Nee: het bestand bevat wel een entry (de Elon tech-audit melding van 2026-04-12T15:51:00Z). De `wc -l` retourneerde 0 maar `tail -5` toonde een entry — het bestand heeft geen afsluitende newline. Dit is een schrijfwijze-inconsistentie die kan leiden tot parsefouten bij line-by-line JSONL parsing.

### 4.2 Memory-Agent Werking

De geheugen-extractie job stuurt:
> "Analyseer de afgelopen 24 uur aan activiteiten, beslissingen en belangrijke informatie."

Maar memory-agent draait als **isolated session** zonder toegang tot andere agents' session logs. Het heeft alleen toegang tot zijn eigen workspace en bestanden die agents hebben neergeschreven. Dit betekent:
- Memory-agent kan alleen samenvatten wat expliciet naar bestanden is geschreven
- Impliciete beslissingen in agent-sessies die niet naar c-suite-chat of files zijn geschreven, gaan verloren

De MEMORY.md van Muddy toont entries van 2026-04-11 en 2026-04-12 — de memory-agent functioneert dus, maar de kwaliteit hangt af van hoe goed agents hun tussentijdse output documenteren.

### 4.3 Compaction Mechanisme

```json
"compaction": {
  "mode": "safeguard",
  "identifierPolicy": "strict",
  "memoryFlush": {
    "enabled": true,
    "softThresholdTokens": 6000,
    "prompt": "Write any lasting notes to memory/YYYY-MM-DD.md; reply with NO_REPLY if nothing to store.",
    "systemPrompt": "Session nearing compaction. Store durable memories now."
  }
}
```

`softThresholdTokens: 6000` is relatief laag voor agents die uitgebreide wiki-analyses uitvoeren. Elon's tech-audit kan eenvoudig 6000+ tokens produceren in één sessie. Bij compaction schrijft Elon naar zijn eigen `memory/YYYY-MM-DD.md`, wat correct is. Maar de geheugen-extractie van memory-agent weet niet automatisch dat er een compaction heeft plaatsgevonden — er is geen trigger of notificatie.

### 4.4 Wiki-Vault als Agent Output (Nieuw)

`/home/michiel/wiki-vault/agents/` is net aangemaakt met subdirectories per agent (elon/, gary/, warren/, daily/, weekly/) en een `_meta.json`. De subdirectories zijn aanwezig maar leeg (de listcommando's tonen mapnamen maar geen bestanden daarin). De wiki-vault is via llm-wiki MCP bereikbaar als schrijfkanaal (Stap 8 in skills), maar llm-wiki is offline — dus schrijven naar wiki-vault/agents/ is momenteel geblokkeerd.

---

## Fase 5 — Handoffs & Inter-Agent Communicatie {#fase-5}

### 5.1 Handoff Mechanismen (4 kanalen)

**Kanaal A: sessions_spawn (primair)**
Muddy spawnt subagents via `sessions_spawn`. De maximale spawn-diepte is 2 (`maxSpawnDepth: 2`). Elon kan skill-manager spawnen (Laag 2), maar skill-manager bestaat niet. Warren en Gary mogen geen subagents spawnen.

De subagents/runs.json bevat één gefaalde run (gateway 1006 error, Elon gespawnd door Muddy via heartbeat-sessie). De run ended met status `"error"` en `frozenResultText: null`. Het `cleanup: "keep"` veld betekent dat de run-data bewaard blijft voor analyse.

**Kanaal B: c-suite-chat.jsonl (async)**
Bedoeld als async communicatiekanaal. In de praktijk heeft het één entry — van Elon na de tech-audit van vandaag. Het kanaal is functioneel maar onderbenut: de weekly-kompas-sessie (358s runtime) en weekly-bot-overleg (208s runtime) zijn succesvol afgerond zonder merkbaar gebruik van c-suite-chat als coördinatiemechanisme zichtbaar in het bestand.

**Kanaal C: Lab Decision Board API**
REST API op `http://127.0.0.1:3333/api/agent-tasks`. Het `agent-tasks.json` bevat 8 taken:
- 2 status `review` (wp-admin-rechten, core-web-vitals)
- 1 status `in_progress` (technische-prioriteiten-week-15)
- 2 status `done` (sync-agent-tasks, task-checker-heartbeat)
- 3 task-specifieke done-taken (OTA kanaal verificatie, post-stay survey)

De AGENTS.md red line "agent-tasks.json is read-only voor alle agents" is goed gedocumenteerd en voorzien van een code-voorbeeld voor de API. Dit is een concrete bescherming tegen JSON-corruptie.

**Kanaal D: Discord Webhooks**
Drie webhooks: DISCORD_BOT_WEBHOOK_URL (vrijdag BOT-overleg), DISCORD_KOMPAS_WEBHOOK_URL (woensdag Kompas), DISCORD_BLAUWDRUK_WEBHOOK_URL (zondag Blauwdruk). De webhooks worden gelezen uit `.env`. De cron jobs voor de sessies gebruiken `delivery: { "mode": "none" }` — de sessie publiceert zelf via webhook in het skill-script.

### 5.2 Session Keys en Handoff Coherentie

De cron jobs voor wekelijkse teamsessies hebben elk een `sessionKey` die verwijst naar `agent:muddy:discord:channel:1484546226262249622` (#daily-digest). Dit is inconsistent met het doel: de sessies publiceren via Discord webhooks naar aparte kanalen, maar de session context is aan het #daily-digest Discord kanaal gekoppeld. Dit betekent dat de agent bij een sessie-start de context van het #daily-digest kanaal meeneemt.

De weekly-sessies hebben `delivery: { "mode": "none" }` — ze kondigen zichzelf niet aan via openclaw, maar sturen zelf via webhook. Dit is correct gedocumenteerd in de skills.

### 5.3 Coördinatie Timing

Het wekelijkse patroon is ontworpen met een producer-consumer sequentie:

```
Woensdag 14:00 — warren-revenue-strategy (produceert naar c-suite-chat)
Woensdag 16:00 — weekly-kompas-sessie    (consumeert warren's output)

Vrijdag 14:00  — gary-content-strategy   (produceert naar c-suite-chat)
Vrijdag 16:00  — weekly-bot-overleg      (consumeert gary's output)

Zondag  14:00  — elon-tech-audit         (produceert naar c-suite-chat)
Zondag  16:00  — weekly-blauwdruk-sessie (consumeert elon's output)
```

Dit is een elegante 2-uurs buffer-architectuur. Het risico: als de producer (bijv. elon-tech-audit) timet out of faalt, gaat de consumer (weekly-blauwdruk-sessie) door zonder input — de Blauwdruk-sessie laat dan een lege of onvolledige analyse zien.

Bewijs: elon-tech-audit draait 279s (93% van 300s timeout). Zondag is dit de kritiekste link.

---

## Fase 6 — MCP Integraties & Tooling {#fase-6}

### 6.1 Geconfigureerde MCP Servers

`.mcp.json` (workspace-niveau):
```json
{
  "mcpServers": {
    "context7": { "command": "npx", "args": ["-y", "@upstash/context7-mcp"] },
    "llm-wiki": {
      "command": "node",
      "args": ["/home/agent/workspace/obsidian-llm-wiki/mcp-server/dist/index.js"],
      "env": { "VAULT_MIND_VAULT_PATH": "/home/agent/wiki" }
    }
  }
}
```

**Niet geconfigureerd maar wél gebruikt:** `qmd` MCP server. De gary-content-strategy, warren-revenue-strategy, en elon-tech-audit skills roepen allemaal `qmd__query` en/of `qmd__get` aan. qmd staat nergens in `.mcp.json` of `openclaw.json`. Dit betekent dat alle drie wekelijkse analyse-skills **zonder wiki-query functionaliteit werken** — ze falen stil of skippen de wiki-analyse stap.

### 6.2 llm-wiki Status

Volgens de tech-audit van 2026-04-12 (Elon's werkbestand `tech-audit-integraties-2026-04-12.md`):
> "MCP servers (llm-wiki, qmd) zijn OFFLINE"

Dit heeft directe impact op:
- wiki-raw-check: Stap 1 en 2 gebruiken `llm-wiki__vault_list` → **timeout omdat tool niet reageert**
- wiki-onderhoud: zelfde afhankelijkheid → **timeout**
- wiki-ingest: `llm-wiki__vault_write` en `llm-wiki__vault_read` → **niet bruikbaar**
- wiki-zoek-antwoord: `llm-wiki__vault_search` → **niet bruikbaar**
- Schrijven naar wiki-vault/agents/ (Stap 8 in nieuwe skills) → **geblokkeerd**

De llm-wiki MCP server draait als stdio process. De configuratie verwijst naar:
`/home/agent/workspace/obsidian-llm-wiki/mcp-server/dist/index.js`

Dit pad is beschikbaar in de VM (het obsidian-llm-wiki project staat in de workspace). De oorzaak van offline zijn is niet direct afleidbaar uit de configuratiebestanden — waarschijnlijk crasht het Node.js process en herstart het niet automatisch.

### 6.3 Toolcount Discrepantie

De pipeline-beschrijving vermeldt: llm-wiki (27 tools), qmd (4 tools). De `.mcp.json` configureert alleen llm-wiki en geen qmd. Als qmd 4 tools levert die door 3 agents gebruikt worden in wekelijkse analyses, ontbreekt effectief een kwart van het MCP tooling-oppervlak.

### 6.4 context7 MCP

`context7` is geconfigureerd als externe npx-package (`@upstash/context7-mcp`). Dit vereist internetconnectiviteit vanuit de VM. Geen fallback geconfigureerd. context7 wordt gebruikt door Claude Code (de huidige sessie) maar vermoedelijk niet door agents in de pipeline.

---

## Fase 7 — Infrastructuur & Beveiliging {#fase-7}

### 7.1 Gateway Configuratie

```json
"gateway": {
  "port": 18789,
  "mode": "local",
  "controlUi": { "dangerouslyDisableDeviceAuth": true },
  "auth": { "mode": "token", "token": "af21006010193b74f53fae1550ea72e6685d497880f4e9c2" }
}
```

**Kritieke bevinding:** `dangerouslyDisableDeviceAuth: true` is ingesteld op de control UI. Dit betekent dat de gateway control interface geen device-authenticatie vereist. Gecombineerd met het feit dat de gateway op `mode: "local"` draait (alleen loopback) is het risico beperkt, maar als de VM ooit netwerktoegang krijgt of de port geforward wordt, is de control UI zonder extra authenticatie bereikbaar.

**Gateway token in plain text:** De gateway auth-token (`af21006010193b74f53fae1550ea72e6685d497880f4e9c2`) staat in plain text in `openclaw.json`. Dit bestand wordt gemonitord door de config-health monitor, maar is niet versleuteld. Elke process met leesrechten op het bestand heeft de token.

**Gateway 1006 errors:** De subagents/runs.json toont een recente `gateway closed (1006 abnormal closure)` bij een Elon-spawn door Muddy. De error specificeert `ws://127.0.0.1:18789` als doel. Dit is een WebSocket abnormal closure — het gateway process beëindigde de verbinding onverwacht. Met `maxConcurrentRuns: 2` en `subagents.maxConcurrent: 3` kan een piek van gelijktijdige verbindingen de gateway destabiliseren.

### 7.2 Browser Configuratie

```json
"browser": {
  "headless": true,
  "ssrfPolicy": { "dangerouslyAllowPrivateNetwork": true },
  "extraArgs": ["--ignore-certificate-errors"]
}
```

Twee risicovolle instellingen:
- `dangerouslyAllowPrivateNetwork: true` — agents kunnen via de browser privé-netwerkadressen benaderen (10.0.x.x, 192.168.x.x). Dit is nodig voor WordPress admin op de lokale staging server, maar ook een SSRF-attack vector als een agent misleid wordt.
- `--ignore-certificate-errors` — SSL/TLS certificaatfouten worden genegeerd. Legitiem voor staging, maar dit geldt voor alle browser-interacties inclusief externe sites.

### 7.3 Auth-Profiles en Provider Configuratie

`/home/michiel/openclaw-workspace/.openclaw/agents/main/agent/auth-profiles.json`:
```json
{
  "profiles": {
    "openai:default": { "type": "token", "provider": "openai", "token": "sk-dummy-openai-key-placeholder" },
    "deepseek:default": { "type": "token", "provider": "deepseek", "token": "sk-c1294fcac52b4ce3bd65229d1d579560" }
  }
}
```

**Probleem 1:** `openai:default` heeft een dummy placeholder token (`sk-dummy-openai-key-placeholder`). Wanneer daily-executive-sync probeert te draaien in de context van agent "main", zoekt het naar de openai provider — en vindt een dummy token. Dit is de directe oorzaak van de `No API key found for provider "openai"` fout.

**Probleem 2:** DeepSeek API key staat in plain text in het bestand. Dit is hetzelfde risico als de gateway token.

**Probleem 3:** De `main` agent heeft auth-profiles maar is niet gedefinieerd in `openclaw.json` agents.list. Dit is een orphan configuratie — resterend van een eerdere architectuurversie of initiële setup.

### 7.4 SSRF en Netwerktoegang

De WordPress staging URL is via `web_fetch` **niet** bereikbaar (gedocumenteerd in AGENTS.md: "web_fetch werkt NIET voor www.logiesopdreef.nl (privé IP, geblokkeerd door SSRF)"). Agents moeten de WP REST API via bash/curl gebruiken. Dit is correct gedocumenteerd als red line, maar er is geen technische preventie — een agent kan nog steeds proberen web_fetch te gebruiken en stil falen.

---

## Fase 8 — Bekende Problemen & Foutanalyse {#fase-8}

### 8.1 Probleem 1: daily-executive-sync — Auth Error (KRITIEK)

**Status:** consecutiveErrors: 4, lastErrorReason: "auth"

**Root Cause (volledig):**
```
FailoverError: No API key found for provider "openai". 
Auth store: /home/agent/workspace/.openclaw/agents/main/agent/auth-profiles.json
(agentId: muddy, sessionKey: agent:muddy:cron:82e3979f-...[heartbeat session])
```

De error chain is:
1. daily-executive-sync heeft `sessionKey: "agent:muddy:cron:82e3979f-91dc-4037-a91f-de808054a9a7"` — dit is de heartbeat cron session
2. Bij het verwerken van deze sessie wordt agent-context "main" gepickt in plaats van "muddy"
3. De "main" agent's auth-profiles.json heeft `openai:default: sk-dummy-openai-key-placeholder`
4. De failover naar deepseek slaagt niet omdat het ook via dezelfde auth-lookup faalt
5. Job faalt direct (lastDurationMs: 1446ms — niet eens gestart)

**Impact:** Dagelijks om 07:30 (ma-vr) faalt de executive sync. Muddy spawnt geen Elon, Gary of Warren voor dagelijkse status. Het team heeft geen dagelijkse coördinatiemoment via dit mechanisme. Dit heeft directe operationele impact.

**Oplossing:** Verwijder de `sessionKey` van daily-executive-sync (laat het een nieuwe isolated session aanmaken als `agentId: muddy`) óf fix de main agent auth-profiles door `sk-dummy-openai-key-placeholder` te vervangen door een geldige key of de provider te verwijderen.

### 8.2 Probleem 2: wiki-raw-check — Timeout (MATIG)

**Status:** consecutiveErrors: 1, lastDurationMs: 180030ms, timeout: 300s

**Root Cause:**
De skill roept `llm-wiki__vault_list` aan via MCP. llm-wiki MCP is offline. De agent wacht tot de tool-call een response geeft — maar de tool is niet beschikbaar. Na 180s (halverwege de 300s timeout) breekt de job af.

**Aanvullend risico:** De wiki-raw-check draait om 07:30 op maandag — zelfde slot als de (kapotte) daily-executive-sync. Met maxConcurrentRuns: 2 kan dit tot een queuing delay leiden.

**Oplossing:** Herstel llm-wiki MCP server. Voeg timeout-handling toe aan het skill-script (detecteer MCP unavailability en rapporteer gracefully). Verhoog job timeout naar 450s als buffer.

### 8.3 Probleem 3: wiki-onderhoud — Timeout (MATIG)

**Status:** consecutiveErrors: 1, lastDurationMs: 300019ms, timeout: 600s

**Root Cause:**
Zelfde als wiki-raw-check — llm-wiki MCP offline. De wiki-onderhoud skill voert een uitgebreidere audit uit (broken links, orphans, index, frontmatter) en gebruikt meerdere MCP calls. Na 300s (halverwege de 600s timeout) breekt de job af.

**Aanvullend risico:** wiki-onderhoud draait op maandag 08:00 — gelijktijdig met vikbooking-weekly-sync. Beide zijn voor Elon, maar vikbooking heeft geen MCP dependency en loopt normaal (102s).

### 8.4 Probleem 4: Dubbele Heartbeat (LAAG)

Native heartbeat config (`heartbeat.every: "55m"`) én cron heartbeat job (`0,55 7-18 * * *`). Beide sturen "HEARTBEAT" naar Muddy.

De native heartbeat gebruikt `target: "last"` (gaat naar de meest recente actieve sessie). De cron heartbeat opent een isolated session. Dit zijn twee verschillende mechanismes met overlappende functionaliteit. Bij elke 55-minuut tick gaat Muddy twee keer een heartbeat verwerken.

**Bewijs:** De cron heartbeat heeft een successvolle last run (27s, ok). De native heartbeat heeft een aparte config maar geen aparte monitoring entry.

### 8.5 Probleem 5: qmd MCP niet geconfigureerd (HOOG)

Gary's gary-content-strategy roept `qmd__query` aan — tool bestaat niet in `.mcp.json`. Warren's warren-revenue-strategy: zelfde. Elon's elon-tech-audit: zelfde. Alle drie wekelijkse analyses die "wiki-vault via qmd" moeten analyseren, doen dit niet.

Dit is een **stille failure**: de agents presteren alsof ze de wiki geanalyseerd hebben, maar ze konden de tool niet aanroepen. De outputs (c-suite-chat entries, Lab Decision Board proposals) kunnen gebaseerd zijn op verouderde of incomplete context.

### 8.6 Probleem 6: elon-tech-audit Timeout-risico (PREVENTIEF)

Niet kapot, maar 93% timeout-gebruik (279s/300s). De volgende keer dat Elon een complexer aspect auditeert (zoals aspect 7: Integraties, met MCP checks) of een extra API call doet, timet de job uit.

---

## Fase 9 — Parallelle Omgevingen & Interferentierisico {#fase-9}

### 9.1 Hermes vs. OpenClaw

De Hermes workspace (`/home/michiel/hermes-workspace/`) draait parallel met dezelfde wekelijkse taken, maar **2 uur later** qua schedule. De Hermes skills voor logies-op-dreef omvatten: matomo-traffic, vikbooking-bookings, weekly-blauwdruk-sessie, weekly-bot-overleg, weekly-kompas-sessie.

**Gedeelde resources:**
- Matomo Brain Bridge API (`/wp-json/brain/v1/matomo/summary`) — beide omgevingen halen data op
- VikBooking API — beide omgevingen halen boekingsdata op
- De Hermes matomo-traffic skill gebruikt `~/.hermes/data/matomo.db` (lokaal)
- De openclaw matomo-traffic skill gebruikt een SQLite in `workspace-elon/skills/matomo-traffic/`

Beide databases zijn gescheiden, dus geen directe schrijfconflicten. Maar:
- De WordPress REST API ontvangt dubbele wekelijkse calls — maandag openclaw 08:00 + Hermes 10:00
- De weekly sessies produceren vergelijkbare analyses voor dezelfde B&B maar via verschillende agents
- Er is geen mechanisme om te voorkomen dat beide omgevingen tegelijk Lab Decision Board entries indienen

### 9.2 Mogelijke Interferentie

**Lab Decision Board:** Beide omgevingen schrijven naar `http://127.0.0.1:3333/api/agent-tasks`. Als Hermes ook P1/P2 tasks indient voor dezelfde issues als openclaw, kan de takenlijst duplicaten bevatten of conflicterende aanbevelingen.

**WordPress API Load:** Op woensdag 08:00 (openclaw matomo-sync) en een equivalent Hermes tijdstip worden vergelijkbare REST API calls gemaakt. De WordPress server (en de Matomo Brain Bridge) heeft geen rate limiting mechanisme zichtbaar in de configuratie.

**Memory Isolation:** Hermes agents schrijven naar `~/.hermes/` paths terwijl openclaw agents naar `/home/agent/workspace/.openclaw/` schrijven. De wiki-vault is gedeeld (`/home/michiel/wiki-vault/` = `/home/agent/wiki/`) — als Hermes agents ook naar wiki-vault schrijven, kunnen ze openclaw-agentoutputs overschrijven.

### 9.3 Skill-Overlap in Hermes

Hermes heeft `weekly-blauwdruk-sessie`, `weekly-bot-overleg`, en `weekly-kompas-sessie` als skills — exact dezelfde namen als de openclaw cron jobs. Als Hermes deze skills op een later tijdstip uitvoert, schrijven ze naar c-suite-chat.jsonl of andere shared bestanden? De Hermes skills staan in `/home/michiel/hermes-workspace/.hermes/skills/logies-op-dreef/` — dit is een aparte locatie maar het is onduidelijk of ze dezelfde output-paden gebruiken.

---

## Synthese: Prioriteitenmatrix & Aanbevelingen {#synthese}

### Prioriteitenmatrix

| Prioriteit | Probleem | Impact | Moeilijkheid | Snelste Fix |
|------------|----------|--------|--------------|-------------|
| **P0 — Kritiek** | daily-executive-sync kapot (4 auth errors) | Geen dagelijkse teamcoördinatie | Laag | Verwijder sessionKey uit job entry |
| **P0 — Kritiek** | llm-wiki MCP offline | wiki-raw-check + wiki-onderhoud timen out; wiki-vault writes geblokkeerd | Middel | Process restart + supervisord watchdog |
| **P1 — Hoog** | qmd niet geconfigureerd in .mcp.json | Wekelijkse analyses (Gary, Warren, Elon) missen wiki-context | Laag | Voeg qmd MCP toe aan .mcp.json |
| **P1 — Hoog** | elon-tech-audit 93% timeout-gebruik | Volgende complexe audit timet out | Laag | Verhoog timeout naar 450-500s |
| **P2 — Matig** | skill-manager ghost reference in Elon config | Elon kan niet skill-manager spawnen (fout) | Laag | Verwijder uit allowAgents of definieer de agent |
| **P2 — Matig** | Dubbele heartbeat (native + cron) | Redundantie, onnodige runs, confusion | Laag | Kies één mechanisme en verwijder de ander |
| **P2 — Matig** | Maandagochtend congestie (8 jobs, maxConcurrent: 2) | Queue delays, wakeMode: "now" kan flood veroorzaken bij restart | Middel | Spreid jobs, verhoog maxConcurrentRuns naar 3 |
| **P2 — Matig** | main agent orphan met dummy openai key | Directe oorzaak daily-executive-sync auth fout | Laag | Fix auth-profiles.json of verwijder main agent |
| **P3 — Laag** | Dubbele skills in Muddy workspace (Muddy kan Elon bypassen) | Architecturele inconsistentie met delegatieprotocol | Middel | Verwijder agent-specifieke skills uit Muddy workspace |
| **P3 — Laag** | c-suite-chat.jsonl geen trailing newline | Potentieel parse-probleem | Laag | Fix schrijfprocedure in skills |
| **P3 — Laag** | dangerouslyDisableDeviceAuth: true | Security risico als gateway ooit extern bereikbaar wordt | Laag | Enable device auth |
| **P3 — Laag** | Hermes parallelle omgeving — interferentierisico | Duplicatie Lab Decision Board, shared wiki-vault | Middel | Documenteer scope per omgeving |

### Aanbevelingen per Fase

#### Onmiddellijk (deze week)

**1. Fix daily-executive-sync sessionKey**
In `jobs.json`, verwijder de `sessionKey` veld van de daily-executive-sync job (id: `c386e2a6-...`). Zonder sessionKey maakt de job een nieuwe isolated session aan als agent "muddy" — zonder de "main" agent auth-lookup. Dit lost de 4 consecutive errors op.

**2. Herstel llm-wiki MCP**
Controleer of het Node.js process van `obsidian-llm-wiki/mcp-server/dist/index.js` actief is in de VM. Start het handmatig en voeg een supervisord/process-manager watchdog toe zodat het automatisch herstart. Test met een `llm-wiki__vault_list` call.

**3. Voeg qmd toe aan .mcp.json**
Registreer de qmd MCP server in `.mcp.json`. Zodra het process-pad en startup-commando bekend zijn, toevoegen als derde MCP server entry.

#### Korte termijn (komende twee weken)

**4. Verhoog elon-tech-audit timeout**
Verander `timeoutSeconds: 300` naar `timeoutSeconds: 480` voor elon-tech-audit-weekly. Ruime buffer voor aspect 7 (Integraties) die extra MCP calls doet.

**5. Verwijder skill-manager ghost reference**
In `openclaw.json`, verwijder `"skill-manager"` uit Elon's `subagents.allowAgents`. Als skill-manager als agent toegevoegd moet worden: definieer het eerst in agents.list.

**6. Kies één heartbeat mechanisme**
De native heartbeat (`heartbeat.every: "55m"`) is het juiste mechanisme voor continue agent-beschikbaarheid. De cron heartbeat job is redundant. Disable of verwijder de heartbeat cron job (id: `82e3979f-...`). Pas ook de sessionKey van daily-executive-sync aan (zie aanbeveling 1).

**7. Spreiding maandagochtend jobs**
Herplan maandagochtend jobs om congestie te reduceren:
```
07:00 — geheugen-extractie + wiki-wp-freshness  (huidig, OK)
07:30 — wiki-raw-check alleen                   (verplaats daily-executive-sync naar 08:30)
08:00 — wiki-onderhoud alleen                   (verplaats vikbooking naar 08:30)
08:30 — daily-executive-sync + vikbooking
```

#### Structureel (volgende sprint)

**8. VikBooking database schema fix**
Het tech-audit rapport van Elon documenteert een `bookings` tabel ontbreekt in VikBooking DB. Dit blokkeert Warren's revenue analytics. Lab Decision Board heeft dit als `in_progress` (technical-priorities-week-15-001). Opvolging vereist.

**9. Hermes/OpenClaw scope-scheiding**
Documenteer expliciet welke pipeline verantwoordelijk is voor welke output. Overweeg: Hermes als development/backup, OpenClaw als productie. Voorkom dat beide omgevingen tegelijk naar Lab Decision Board schrijven door omgevingstagging (bijv. `"source": "openclaw"` in task JSON).

**10. Gemini memory search authenticatie**
`agents.defaults.memorySearch.provider: "gemini"` vereist een Gemini API key. Deze is niet zichtbaar in `openclaw.json` providers of auth-profiles. Verifieer dat de Gemini API key correct geconfigureerd is, anders faalt memory search stil bij elke session startup.

**11. MCP server supervisie**
Beide MCP servers (llm-wiki, qmd) draaien als stdio processes zonder monitored lifecycle. Implementeer een supervisord of systemd service die de processes bewaakt en herstart bij crash. De elon-tech-audit van week 15 meldt "MCP servers offline" — dit is kennelijk niet de eerste keer.

---

### Positieve Bevindingen

De audit heeft ook sterke punten geïdentificeerd die de pipeline stabiel houden:

- **13/16 cron jobs draaien probleemloos** — de maandelijks roterende audit-structuur (ISO week % 8) is elegant en goed geïmplementeerd
- **Producer-consumer timing** (14:00 analyse → 16:00 teamsessie) is architectureel solide
- **c-suite-chat.jsonl protocol** is goed gedocumenteerd en meervoudig gebruikt
- **Lab Decision Board red line** (geen directe bestandsschrijf, altijd via API) is concreet en met codevoorbeelden gedocumenteerd
- **agent-tasks.json** toont actieve besluitvorming en opvolging (8 taken, merendeel resolved)
- **SOUL.md/AGENTS.md bootstrap pattern** geeft agents een rijke identiteits- en protocolcontext bij elke sessie
- **bootstrapMaxChars/bootstrapTotalMaxChars** limieten zijn realistisch (20k/80k) en voorkomen context-overflow
- **Memory compaction** (safeguard mode, soft threshold 6000 tokens) is pragmatisch geconfigureerd
- **DeepSeek fallback** op alle cron jobs zorgt voor continuïteit bij localproxy/auto unavailability

---

*Audit gegenereerd op 2026-04-12 op basis van directe analyse van:*
- `/home/michiel/openclaw-workspace/.openclaw/openclaw.json` (hoofdconfiguratie)
- `/home/michiel/openclaw-workspace/.openclaw/cron/jobs.json` (16 cron jobs)
- `/home/michiel/openclaw-workspace/.openclaw/workspace/SOUL.md` en `AGENTS.md`
- `/home/michiel/openclaw-workspace/.openclaw/workspace/MEMORY.md`
- `/home/michiel/openclaw-workspace/.openclaw/agents/main/agent/auth-profiles.json`
- `/home/michiel/openclaw-workspace/.openclaw/subagents/runs.json`
- `/home/michiel/openclaw-workspace/.openclaw/logs/config-health.json`
- `/home/michiel/openclaw-workspace/.openclaw/workspace-elon/tech-audit-integraties-2026-04-12.md`
- `/home/michiel/openclaw-workspace/.openclaw/agent-tasks.json`
- Skill-definities: elon-tech-audit, wiki-raw-check, wiki-ingest, gary-content-strategy (SKILL.md bestanden)
- Agent workspace listings: workspace-elon/, workspace-gary/, workspace-warren/, workspace-memory-agent/
- `/home/michiel/wiki-vault/agents/` (structuur)
- `/home/michiel/hermes-workspace/.hermes/skills/logies-op-dreef/` (parallelle omgeving)
