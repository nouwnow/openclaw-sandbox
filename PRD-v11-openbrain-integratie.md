# PRD-v11 — OpenBrain als Rapportage-Opslaglaag voor OpenClaw

**Datum:** 2026-04-23  
**Auteur:** Claude Code analyse op verzoek van Michiel Nouwens  
**Status:** Ontwerp — nog niet geïmplementeerd  
**Aanleiding:** Agents genereren 27 Lobster-pipeline rapporten die nu alleen als platte Markdown-bestanden in wiki-vault staan. OpenBrain biedt semantisch zoeken via pgvector waarmee agents historische rapporten terug kunnen vinden op betekenis, niet op bestandsnaam.

---

## 0. TL;DR

Agents genereren wekelijks 20–30 rapporten naar `~/wiki-vault/`. Die zijn nu alleen doorzoekbaar via `qmd` (BM25 + lokale embeddings). OpenBrain voegt een **cloud-side pgvector laag** toe via een Supabase Edge Function. Elke keer dat een agent een rapport schrijft, stuurt een Lobster-stap het rapport naar OpenBrain. Agents kunnen daarna alle historische rapporten semantisch doorzoeken via een nieuw MCP-tool `search_reports`.

**Wat we bouwen:**
1. Supabase tabel `agent_reports` — dedicated tabel voor agent-rapporten (los van de bestaande `thoughts` tabel)
2. Edge Function `openclaw-reports-mcp` — 5 tools: `store_report`, `search_reports`, `list_reports`, `get_report`, `report_stats`
3. Python helper script `openbrain-store.py` — aanroepbaar vanuit Lobster-pipelines  
4. Lobster pipeline `openbrain-sync.lobster` — batch-ingest van bestaande wiki-vault rapporten bij de eerste run
5. MCP-configuratie in openclaw workspace — zodat agents OpenBrain direct kunnen raadplegen

---

## 1. Huidige staat — Analyse per systeem

### 1.1 OpenBrain

OpenBrain is een **Supabase-hosted second brain** met:

| Onderdeel | Details |
|-----------|---------|
| Database | Supabase PostgreSQL met `pgvector` extensie |
| Core tabel | `thoughts` — text content + 1536-dim embedding + JSONB metadata |
| Zoekfunctie | `match_thoughts(query_embedding, threshold, count, filter)` — cosine similarity |
| MCP servers | `open-brain-mcp`, `household-knowledge-mcp`, `site-performance-mcp`, `home-maintenance-mcp` |
| Auth | `MCP_ACCESS_KEY` via `x-brain-key` header of `?key=` query param |
| Transport | Supabase Edge Functions (Deno + Hono + `@hono/mcp` + `@modelcontextprotocol/sdk`) |
| Embedding model | `openai/text-embedding-3-small` via OpenRouter (1536 dimensies) |
| Deployment | `supabase functions deploy <naam> --no-verify-jwt` |
| Project directory | `~/OpenBrain/supabase/functions/<naam>/` |

**Bestaande MCP tools op `open-brain-mcp`:**
- `search_thoughts` — semantisch zoeken met drempelwaarde
- `list_thoughts` — filteren op type/topic/persoon/datum
- `thought_stats` — statistieken totaal/types/topics/mensen
- `capture_thought` — nieuw item opslaan met automatische embedding + metadata extractie

**Startup:** Dashboard draait lokaal: `npm run dev` in `Interface/household-knowledge/`. Edge Functions draaien permanent op Supabase cloud.

### 1.2 OpenClaw

OpenClaw draait als een **NixOS MicroVM** (`cloud-hypervisor`) op het host systeem:

| Onderdeel | Details |
|-----------|---------|
| VM IP | `10.0.1.2` (host gateway: `10.0.1.1`) |
| Hypervisor | cloud-hypervisor via `microvm.nix` |
| VM geheugen | 8192 MB, 4 vCPUs |
| Package manager | NixOS declaratief via `flake.nix` |
| Coordinator gateway | poort `18789` |
| Project-A gateway | poort `18790` |
| Dashboard | Next.js op poort `3333` |

**Agents:**

| Agent | Rol | Primaire output |
|-------|-----|-----------------|
| Muddy | COO / Coordinator | Orkestratie, Discord interface |
| Elon | CTO / Technical | Infra audits, technische analyses |
| Dario | Tech Analyst | Diepgaande audits, A/B testen |
| Gary | CMO / Content | Content strategie, blog artikelen |
| Warren | CRO / Revenue | Revenue analyses, booking inzichten |

**Virtiofs mounts (host → VM):**

| Host pad | VM pad | Tag |
|----------|--------|-----|
| `~/openclaw-workspace` | `/home/agent/workspace` | `openclaw-data` |
| `~/wiki-vault` | `/home/agent/wiki` | `wiki-vault` |
| `~/openclaw-workspace/.claude` | `/home/agent/.claude` | `agent-claude` |

**Internetverbinding:** VM heeft toegang tot externe internet via host gateway `10.0.1.1` → bereikt Supabase cloud gewoon.

### 1.3 Wiki-vault rapporten

De wiki-vault staat op `~/wiki-vault/` (host) = `/home/agent/wiki` (VM).

**Mappen structuur:**

```
~/wiki-vault/
├── index.md
├── raw/                    ← bronbestanden (nooit door agents gewijzigd)
├── wiki/                   ← door agents onderhouden wiki-pagina's
│   └── logies-op-dreef/
├── output/                 ← ad-hoc rapporten en query-resultaten
│   └── 40+ markdown bestanden
└── agents/                 ← automatische periodieke outputs
    └── daily/              ← dagelijkse executive sync bestanden
```

**Actieve Lobster pipelines (27 totaal) die rapporten genereren:**

| Pipeline | Frequentie | Output locatie | Report type |
|----------|-----------|----------------|-------------|
| `daily-executive-sync` | Dagelijks | `/agents/daily/YYYY-MM-DD.md` | agent status aggregatie |
| `warren-revenue-strategy` | Wekelijks | `output/warren-*.md` | revenue analyse + aanbevelingen |
| `warren-revenue-fixed` | Wekelijks | `output/warren-*.md` | revenue fixed metrics |
| `warren-booking-analytics` | Wekelijks | `output/` | booking inzichten |
| `warren-review-strategy` | Wekelijks | `output/` | review strategie |
| `gary-content-strategy` | Wekelijks | `output/` | content strategie |
| `gary-booking-insights` | Wekelijks | `output/` | booking content inzichten |
| `gary-review-strategy` | Wekelijks | `output/` | review content |
| `dario-tech-audit` | Wekelijks | `output/` | technische audit |
| `crux-weekly-audit` | Wekelijks | `output/` | CRUX metrics audit |
| `matomo-weekly-sync` | Wekelijks | data sync + rapport | Matomo analytics |
| `vikbooking-weekly-sync` | Wekelijks | data sync + rapport | VikBooking data |
| `reviews-weekly-sync` | Wekelijks | data sync | gast-reviews |
| `project-status-snapshot` | Wekelijks | Discord + wiki | project status |
| `weekly-kompas-sessie` | Wekelijks | wiki | wekelijkse koers |
| `weekly-planning` | Wekelijks | wiki | weekplanning |
| `weekly-blauwdruk-sessie` | Wekelijks | wiki | strategische blauwdruk |
| `weekly-bot-overleg` | Wekelijks | wiki | agent-overleg |
| `content-output-archiving` | Periodiek | wiki | content archivering |
| `wiki-onderhoud-weekly` | Wekelijks | - | wiki onderhoud |
| `wiki-raw-check-weekly` | Wekelijks | - | raw-vs-wiki sync check |
| `wiki-wp-freshness-weekly` | Wekelijks | - | WordPress freshness check |
| `geheugen-extractie` | Periodiek | memory/ | geheugen extractie |
| `memory-weekly-curation` | Wekelijks | memory/ | memory curation |
| `heartbeat` | 55 minuten | - | system health |
| `task-checker` | 30 minuten | - | taak status check |
| `lab-board-archive` | Wekelijks | Discord | lab board archief |

**Rapport formaat:** Markdown met YAML frontmatter:
```yaml
---
title: "Executive Sync 2026-04-23"
date: 2026-04-23
type: daily-executive-sync
source: openclaw
generated_at: 2026-04-23T08:00:00Z
---
```

### 1.4 Huidige zoekinfrastructuur (wiki-vault)

Agents zoeken nu via twee MCP-servers die op de host draaien:

| Server | Transport | Poort | Tools | Nadeel |
|--------|----------|-------|-------|--------|
| `qmd` | HTTP naar `10.0.1.1:8767` | 8767 | `qmd__query`, `qmd__get`, `qmd__status` | Lokaal, BM25 + embedding (geen cloud sync) |
| `llm-wiki` | HTTP naar `10.0.1.1:8766` | 8766 | `llm-wiki__vault_*`, `llm-wiki__query_*` | Vault-beheer, geen semantische cross-rapport zoeken |

**Limitatie:** Geen gestructureerde metadata per rapport (agent, type, datum), geen cloud-side opslag, geen extern doorzoekbaar archief.

---

## 2. Probleemstelling

1. **Rapporten zijn niet semantisch doorzoekbaar over agents heen.** Dario kan niet zoeken "welke revenue aanbevelingen heeft Warren gemaakt in de afgelopen 3 maanden" zonder alle bestanden handmatig te lezen.

2. **Geen structurele metadata.** Wiki-vault bestanden hebben ad-hoc frontmatter. Geen uniforme indexering op `agent_id`, `report_type`, `week_number`, etc.

3. **Geen cloud-side backup van rapporten.** Wiki-vault is alleen beschikbaar als de VM draait (virtiofs). OpenBrain is altijd bereikbaar via Supabase cloud.

4. **Agents kunnen niet leren van historische rapporten.** Gary kan niet opvragen "wat heeft Warren vorige maand aanbevolen over Google Conversie" zonder handmatige navigatie.

5. **OpenBrain wordt niet benut voor agent-output.** OpenBrain is opgezet als persoonlijk second brain maar heeft capaciteit voor gestructureerde agent-rapporten die nu nergens worden opgeslagen met semantische zoekfunctie.

---

## 3. Doelstelling

OpenBrain inzetten als **persistente, semantisch doorzoekbare opslaglaag** voor alle OpenClaw agent-rapporten:

1. Elke pipeline die een rapport schrijft, **slaat het ook op in OpenBrain**
2. Alle bestaande rapporten in wiki-vault worden **eenmalig geïngesteerd** 
3. Agents kunnen **semantisch zoeken** in alle historische rapporten via een nieuw MCP-tool
4. Rapporten zijn **gefilterd doorzoekbaar** op agent, type, datum en keywords

---

## 4. Architectuur — Uitgewerkt Ontwerp

```
OPENCLAW VM (10.0.1.2)
│
├── Lobster Pipeline: warren-revenue-strategy
│   ├── [stap 1..n] → analyseert data, schrijft rapport
│   ├── [stap: write_wiki] → schrijft naar /home/agent/wiki/output/warren-*.md
│   └── [stap: store_openbrain] → roept openbrain-store.py aan
│                                  ↓ HTTPS naar Supabase
│
├── Python script: openbrain-store.py
│   ├── Leest rapport content uit stdin of bestandspad
│   ├── POST naar openclaw-reports-mcp Edge Function
│   └── Stuurt: agent_id, report_type, title, content, wiki_path, metadata
│
└── Claude Code MCP config (.claude/claude.json)
    └── openclaw-reports-mcp → https://<project>.supabase.co/functions/v1/openclaw-reports-mcp
        Tools: store_report, search_reports, list_reports, get_report, report_stats

SUPABASE CLOUD
│
├── Tabel: agent_reports
│   ├── id uuid
│   ├── agent_id text (warren, gary, dario, elon, muddy)
│   ├── report_type text (revenue-strategy, daily-sync, content-strategy, ...)
│   ├── title text
│   ├── content text
│   ├── wiki_path text (/home/agent/wiki/output/warren-revenue-2026-04-23.md)
│   ├── embedding vector(1536)
│   ├── metadata jsonb (date, week, tags, key_findings, source_pipeline)
│   └── created_at timestamptz
│
└── Edge Function: openclaw-reports-mcp
    ├── store_report — slaat nieuw rapport op met embedding
    ├── search_reports — semantisch zoeken (cosine similarity)
    ├── list_reports — filteren op agent/type/datum
    ├── get_report — ophalen op ID of wiki_path
    └── report_stats — statistieken per agent/type
```

---

## 5. Database Schema

### 5.1 Tabel `agent_reports`

```sql
-- Tabel voor OpenClaw agent-rapporten
CREATE TABLE agent_reports (
  id            uuid    DEFAULT gen_random_uuid() PRIMARY KEY,
  agent_id      text    NOT NULL,                    -- 'warren', 'gary', 'dario', 'elon', 'muddy'
  report_type   text    NOT NULL,                    -- 'revenue-strategy', 'daily-executive-sync', ...
  title         text    NOT NULL,
  content       text    NOT NULL,
  wiki_path     text,                                -- absoluut pad in wiki-vault, uniek per rapport
  embedding     vector(1536),
  metadata      jsonb   DEFAULT '{}'::jsonb,         -- date, week, tags, key_findings, source_pipeline
  created_at    timestamptz DEFAULT now()
);

-- Vector similarity index (HNSW, snelst voor cosine)
CREATE INDEX ON agent_reports USING hnsw (embedding vector_cosine_ops);

-- BTree indexes voor filtering
CREATE INDEX ON agent_reports (agent_id);
CREATE INDEX ON agent_reports (report_type);
CREATE INDEX ON agent_reports (created_at DESC);

-- GIN index voor JSONB metadata queries
CREATE INDEX ON agent_reports USING gin (metadata);

-- Uniek constraint op wiki_path (voorkomt duplicate ingest)
CREATE UNIQUE INDEX ON agent_reports (wiki_path) WHERE wiki_path IS NOT NULL;

-- Row Level Security
ALTER TABLE agent_reports ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Service role full access" ON agent_reports
  FOR ALL USING (auth.role() = 'service_role');
```

### 5.2 Zoekfunctie `match_agent_reports`

```sql
CREATE OR REPLACE FUNCTION match_agent_reports(
  query_embedding vector(1536),
  match_threshold float DEFAULT 0.5,
  match_count     int   DEFAULT 10,
  filter_agent    text  DEFAULT NULL,
  filter_type     text  DEFAULT NULL
)
RETURNS TABLE (
  id          uuid,
  agent_id    text,
  report_type text,
  title       text,
  content     text,
  wiki_path   text,
  metadata    jsonb,
  similarity  float,
  created_at  timestamptz
)
LANGUAGE plpgsql AS $$
BEGIN
  RETURN QUERY
  SELECT
    r.id, r.agent_id, r.report_type, r.title, r.content, r.wiki_path, r.metadata,
    1 - (r.embedding <=> query_embedding) AS similarity,
    r.created_at
  FROM agent_reports r
  WHERE
    1 - (r.embedding <=> query_embedding) > match_threshold
    AND (filter_agent IS NULL OR r.agent_id = filter_agent)
    AND (filter_type  IS NULL OR r.report_type = filter_type)
  ORDER BY r.embedding <=> query_embedding
  LIMIT match_count;
END;
$$;
```

---

## 6. Edge Function `openclaw-reports-mcp`

Locatie: `~/OpenBrain/supabase/functions/openclaw-reports-mcp/`

### 6.1 `deno.json` (dependencies)

```json
{
  "imports": {
    "@hono/mcp": "npm:@hono/mcp@0.1.1",
    "@modelcontextprotocol/sdk": "npm:@modelcontextprotocol/sdk@1.24.3",
    "hono": "npm:hono@4.9.2",
    "zod": "npm:zod@4.1.13",
    "@supabase/supabase-js": "npm:@supabase/supabase-js@2.47.10"
  }
}
```

### 6.2 `index.ts` — 5 tools

```typescript
import "jsr:@supabase/functions-js/edge-runtime.d.ts";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPTransport } from "@hono/mcp";
import { Hono } from "hono";
import { z } from "zod";
import { createClient } from "@supabase/supabase-js";

const SUPABASE_URL          = Deno.env.get("SUPABASE_URL")!;
const SUPABASE_SERVICE_ROLE = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const OPENROUTER_API_KEY    = Deno.env.get("OPENROUTER_API_KEY")!;
const MCP_ACCESS_KEY        = Deno.env.get("MCP_ACCESS_KEY")!;

const supabase = createClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE);

async function getEmbedding(text: string): Promise<number[]> {
  const r = await fetch("https://openrouter.ai/api/v1/embeddings", {
    method: "POST",
    headers: { Authorization: `Bearer ${OPENROUTER_API_KEY}`, "Content-Type": "application/json" },
    body: JSON.stringify({ model: "openai/text-embedding-3-small", input: text.slice(0, 8000) }),
  });
  if (!r.ok) throw new Error(`Embedding error: ${r.status}`);
  const d = await r.json();
  return d.data[0].embedding;
}

const server = new McpServer({ name: "openclaw-reports", version: "1.0.0" });

// Tool 1: store_report
server.registerTool("store_report", {
  title: "Sla Agent Rapport Op",
  description: "Sla een nieuw agent-rapport op in OpenBrain met semantische embedding. Controleer eerst of het rapport al bestaat via wiki_path.",
  inputSchema: {
    agent_id:    z.string().describe("Agent ID: warren, gary, dario, elon, muddy"),
    report_type: z.string().describe("Type rapport: revenue-strategy, daily-executive-sync, content-strategy, tech-audit, booking-insights, etc."),
    title:       z.string().describe("Titel van het rapport"),
    content:     z.string().describe("Volledige inhoud van het rapport (Markdown)"),
    wiki_path:   z.string().optional().describe("Absoluut pad in wiki-vault: /home/agent/wiki/output/warren-2026-04-23.md"),
    metadata:    z.record(z.unknown()).optional().describe("Extra metadata: { date, week, source_pipeline, tags, key_findings }"),
  },
}, async ({ agent_id, report_type, title, content, wiki_path, metadata }) => {
  try {
    // Dedup check op wiki_path
    if (wiki_path) {
      const { data: existing } = await supabase
        .from("agent_reports")
        .select("id")
        .eq("wiki_path", wiki_path)
        .single();
      if (existing) {
        return { content: [{ type: "text" as const, text: `Rapport al opgeslagen (id: ${existing.id}). Wiki_path: ${wiki_path}` }] };
      }
    }
    const embedding = await getEmbedding(`${title}\n\n${content}`);
    const { data, error } = await supabase.from("agent_reports").insert({
      agent_id, report_type, title, content, wiki_path,
      embedding,
      metadata: { ...metadata, source: "openclaw" },
    }).select("id").single();
    if (error) throw new Error(error.message);
    return { content: [{ type: "text" as const, text: `Rapport opgeslagen (id: ${data.id}). Agent: ${agent_id}, type: ${report_type}` }] };
  } catch (e: unknown) {
    return { content: [{ type: "text" as const, text: `Fout: ${(e as Error).message}` }], isError: true };
  }
});

// Tool 2: search_reports
server.registerTool("search_reports", {
  title: "Zoek in Agent Rapporten",
  description: "Zoek semantisch in alle agent-rapporten. Gebruik dit als je wilt weten wat agents eerder hebben geanalyseerd of aanbevolen over een onderwerp.",
  inputSchema: {
    query:       z.string().describe("Zoekvraag in het Nederlands"),
    agent_id:    z.string().optional().describe("Filter op agent: warren, gary, dario, elon, muddy"),
    report_type: z.string().optional().describe("Filter op type rapport"),
    limit:       z.number().optional().default(8),
    threshold:   z.number().optional().default(0.45),
  },
}, async ({ query, agent_id, report_type, limit, threshold }) => {
  try {
    const qEmb = await getEmbedding(query);
    const { data, error } = await supabase.rpc("match_agent_reports", {
      query_embedding: qEmb,
      match_threshold: threshold,
      match_count: limit,
      filter_agent: agent_id ?? null,
      filter_type: report_type ?? null,
    });
    if (error) throw new Error(error.message);
    if (!data?.length) return { content: [{ type: "text" as const, text: `Geen rapporten gevonden voor: "${query}"` }] };
    const results = data.map((r: Record<string, unknown>, i: number) => {
      const m = (r.metadata as Record<string, unknown>) || {};
      return [
        `--- Resultaat ${i + 1} (${((r.similarity as number) * 100).toFixed(1)}% match) ---`,
        `Titel: ${r.title}`,
        `Agent: ${r.agent_id} | Type: ${r.report_type}`,
        `Datum: ${new Date(r.created_at as string).toLocaleDateString("nl-NL")}`,
        m.source_pipeline ? `Pipeline: ${m.source_pipeline}` : "",
        r.wiki_path ? `Wiki: ${r.wiki_path}` : "",
        `\n${(r.content as string).slice(0, 600)}${(r.content as string).length > 600 ? "..." : ""}`,
      ].filter(Boolean).join("\n");
    });
    return { content: [{ type: "text" as const, text: `${data.length} rapport(en) gevonden:\n\n${results.join("\n\n")}` }] };
  } catch (e: unknown) {
    return { content: [{ type: "text" as const, text: `Fout: ${(e as Error).message}` }], isError: true };
  }
});

// Tool 3: list_reports
server.registerTool("list_reports", {
  title: "Lijst Agent Rapporten",
  description: "Lijst recente rapporten op, gefilterd op agent, type of periode.",
  inputSchema: {
    agent_id:    z.string().optional(),
    report_type: z.string().optional(),
    days:        z.number().optional().describe("Alleen rapporten van de laatste N dagen"),
    limit:       z.number().optional().default(20),
  },
}, async ({ agent_id, report_type, days, limit }) => {
  try {
    let q = supabase.from("agent_reports").select("id, agent_id, report_type, title, wiki_path, created_at")
      .order("created_at", { ascending: false }).limit(limit);
    if (agent_id) q = q.eq("agent_id", agent_id);
    if (report_type) q = q.eq("report_type", report_type);
    if (days) {
      const since = new Date(); since.setDate(since.getDate() - days);
      q = q.gte("created_at", since.toISOString());
    }
    const { data, error } = await q;
    if (error) throw new Error(error.message);
    if (!data?.length) return { content: [{ type: "text" as const, text: "Geen rapporten gevonden." }] };
    const lines = data.map((r: Record<string, unknown>, i: number) =>
      `${i + 1}. [${new Date(r.created_at as string).toLocaleDateString("nl-NL")}] ${r.agent_id}/${r.report_type}: ${r.title}`
    );
    return { content: [{ type: "text" as const, text: `${data.length} rapporten:\n\n${lines.join("\n")}` }] };
  } catch (e: unknown) {
    return { content: [{ type: "text" as const, text: `Fout: ${(e as Error).message}` }], isError: true };
  }
});

// Tool 4: get_report
server.registerTool("get_report", {
  title: "Haal Rapport Op",
  description: "Haal de volledige inhoud op van een specifiek rapport via ID of wiki_path.",
  inputSchema: {
    id:        z.string().optional().describe("UUID van het rapport"),
    wiki_path: z.string().optional().describe("Wiki-vault pad van het rapport"),
  },
}, async ({ id, wiki_path }) => {
  try {
    let q = supabase.from("agent_reports").select("*");
    if (id) q = q.eq("id", id);
    else if (wiki_path) q = q.eq("wiki_path", wiki_path);
    else return { content: [{ type: "text" as const, text: "Geef id of wiki_path op." }], isError: true };
    const { data, error } = await q.single();
    if (error) throw new Error(error.message);
    return { content: [{ type: "text" as const, text: `# ${data.title}\n\nAgent: ${data.agent_id} | Type: ${data.report_type}\nDatum: ${new Date(data.created_at).toLocaleDateString("nl-NL")}\n\n${data.content}` }] };
  } catch (e: unknown) {
    return { content: [{ type: "text" as const, text: `Fout: ${(e as Error).message}` }], isError: true };
  }
});

// Tool 5: report_stats
server.registerTool("report_stats", {
  title: "Rapport Statistieken",
  description: "Overzicht van alle opgeslagen rapporten: totaal, per agent, per type.",
  inputSchema: {},
}, async () => {
  try {
    const { count } = await supabase.from("agent_reports").select("*", { count: "exact", head: true });
    const { data } = await supabase.from("agent_reports").select("agent_id, report_type, created_at").order("created_at", { ascending: false });
    const byAgent: Record<string, number> = {};
    const byType: Record<string, number> = {};
    for (const r of data || []) {
      byAgent[r.agent_id] = (byAgent[r.agent_id] || 0) + 1;
      byType[r.report_type] = (byType[r.report_type] || 0) + 1;
    }
    const sort = (o: Record<string, number>) => Object.entries(o).sort((a, b) => b[1] - a[1]);
    const lines = [
      `Totaal rapporten: ${count}`,
      `Nieuwste: ${data?.[0] ? new Date(data[0].created_at).toLocaleDateString("nl-NL") : "N/B"}`,
      "", "Per agent:",
      ...sort(byAgent).map(([k, v]) => `  ${k}: ${v}`),
      "", "Per type:",
      ...sort(byType).map(([k, v]) => `  ${k}: ${v}`),
    ];
    return { content: [{ type: "text" as const, text: lines.join("\n") }] };
  } catch (e: unknown) {
    return { content: [{ type: "text" as const, text: `Fout: ${(e as Error).message}` }], isError: true };
  }
});

// Hono app met auth
const app = new Hono();

app.get("/health", (c) => c.json({ status: "ok", service: "openclaw-reports-mcp" }));

app.all("*", async (c) => {
  const provided = c.req.header("x-brain-key")
    || c.req.header("x-access-key")
    || new URL(c.req.url).searchParams.get("key");
  if (!provided || provided !== MCP_ACCESS_KEY) {
    return c.json({ error: "Invalid or missing access key" }, 401);
  }
  const transport = new StreamableHTTPTransport();
  await server.connect(transport);
  return transport.handleRequest(c);
});

Deno.serve(app.fetch);
```

---

## 7. Python Helper Script `openbrain-store.py`

Locatie: `~/openclaw-workspace/.openclaw/workspace/scripts/openbrain-store.py`

Dit script wordt aangeroepen vanuit Lobster-pipelines als laatste stap. Het leest rapport metadata als args en de content via stdin.

```python
#!/usr/bin/env python3
"""Stuurt een agent-rapport naar OpenBrain via de openclaw-reports-mcp REST API.

Gebruik:
  echo "<rapport inhoud>" | python3 openbrain-store.py \
    --agent warren \
    --type revenue-strategy \
    --title "Warren Revenue Analyse Week 17" \
    --wiki-path /home/agent/wiki/output/warren-revenue-2026-04-23.md \
    --pipeline warren-revenue-strategy
"""
import argparse
import json
import os
import sys
import urllib.request
import urllib.error

OPENBRAIN_MCP_URL = os.environ.get(
    "OPENBRAIN_MCP_URL",
    "https://<YOUR_PROJECT_REF>.supabase.co/functions/v1/openclaw-reports-mcp"
)
OPENBRAIN_KEY = os.environ.get("OPENBRAIN_KEY", "")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--agent",     required=True, help="Agent ID (warren/gary/dario/elon/muddy)")
    parser.add_argument("--type",      required=True, help="Rapport type")
    parser.add_argument("--title",     required=True, help="Rapport titel")
    parser.add_argument("--wiki-path", default=None,  help="Absoluut wiki-vault pad")
    parser.add_argument("--pipeline",  default=None,  help="Bron Lobster pipeline naam")
    args = parser.parse_args()

    content = sys.stdin.read().strip()
    if not content:
        print("WARN: lege content, rapport niet opgeslagen", file=sys.stderr)
        sys.exit(0)

    payload = {
        "agent_id":    args.agent,
        "report_type": args.type,
        "title":       args.title,
        "content":     content,
        "wiki_path":   args.wiki_path,
        "metadata": {
            "source_pipeline": args.pipeline,
        },
    }

    # Directe REST call naar de Edge Function (store_report tool)
    # We gebruiken een simpele HTTP POST in plaats van MCP protocol
    # omdat Lobster-pipelines geen MCP client hebben
    body = json.dumps({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "tools/call",
        "params": {
            "name": "store_report",
            "arguments": payload,
        }
    }).encode()

    req = urllib.request.Request(
        OPENBRAIN_MCP_URL,
        data=body,
        headers={
            "Content-Type": "application/json",
            "x-brain-key":  OPENBRAIN_KEY,
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            result = json.load(resp)
            text = result.get("result", {}).get("content", [{}])[0].get("text", "OK")
            print(f"[openbrain] {text}")
    except urllib.error.HTTPError as e:
        body = e.read().decode()
        print(f"[openbrain] HTTP {e.code}: {body}", file=sys.stderr)
    except Exception as e:
        print(f"[openbrain] Fout: {e}", file=sys.stderr)

if __name__ == "__main__":
    main()
```

**Let op:** Het script heeft `OPENBRAIN_MCP_URL` en `OPENBRAIN_KEY` als environment variabelen nodig. Die worden toegevoegd aan `.env` op de workspace.

---

## 8. Lobster Pipeline `openbrain-sync.lobster`

Locatie: `~/openclaw-workspace/.openclaw/workspace/pipelines/openbrain-sync.lobster`

Eenmalige batch-ingest van alle bestaande wiki-vault rapporten. Daarna periodiek (wekelijks) voor nieuwe rapporten.

```yaml
name: openbrain-sync
description: |
  Batch-ingest van wiki-vault output/agents rapporten naar OpenBrain.
  Eerste run: alle bestaande bestanden (50+ rapporten).
  Herhaalde runs: alleen nieuwe bestanden (via sqlite dedup tracking).
  Geen LLM nodig — alle metadata wordt uit frontmatter geëxtraheerd.

steps:
  - id: scan_reports
    command: |
      python3 -c "
      import json, os
      from pathlib import Path

      dirs = [
          '/home/agent/wiki/output',
          '/home/agent/wiki/agents',
      ]
      files = []
      for d in dirs:
          p = Path(d)
          if p.exists():
              for f in sorted(p.rglob('*.md')):
                  files.append(str(f))
      print(json.dumps({'files': files, 'total': len(files)}))
      "

  - id: ingest_to_openbrain
    command: |
      python3 /home/agent/workspace/.openclaw/workspace/scripts/openbrain-batch-ingest.py
    stdin: '$scan_reports.stdout'
```

**Bijbehorend `openbrain-batch-ingest.py` script:**

```python
#!/usr/bin/env python3
"""Batch ingest van wiki-vault Markdown bestanden naar OpenBrain.
Slaat reeds geïngesteerde bestanden over via wiki_path dedup.
"""
import json, os, sys, re, time
import urllib.request

OPENBRAIN_MCP_URL = os.environ.get("OPENBRAIN_MCP_URL", "")
OPENBRAIN_KEY     = os.environ.get("OPENBRAIN_KEY", "")

PIPELINE_PATTERNS = {
    "daily-executive-sync":   r"agents/daily/",
    "warren-revenue":         r"warren.*(revenue|terugblik|weekly)",
    "gary-content":           r"gary.*(content|booking|review)",
    "dario-audit":            r"dario.*audit",
    "executive-sync":         r"executive.*(sync|daily)",
    "google-conversion":      r"google.*(conversie|conversion|funnel)",
    "ota-analysis":           r"ota.*(channel|activation)",
    "weekly-retrospective":   r"weekly.*(retrospective|planning|status)",
}

AGENT_PATTERNS = {
    "warren": r"warren",
    "gary":   r"gary",
    "dario":  r"dario",
    "elon":   r"elon",
    "muddy":  r"executive|daily|kompas|planning|retrospective",
}

def detect_agent(path: str) -> str:
    p = path.lower()
    for agent, pat in AGENT_PATTERNS.items():
        if re.search(pat, p):
            return agent
    return "muddy"

def detect_type(path: str) -> str:
    p = path.lower()
    for rtype, pat in PIPELINE_PATTERNS.items():
        if re.search(pat, p):
            return rtype
    return "report"

def extract_frontmatter_title(content: str) -> str:
    m = re.search(r'^title:\s*["\']?(.+?)["\']?\s*$', content, re.MULTILINE)
    if m:
        return m.group(1).strip()
    # Eerste H1
    m = re.search(r'^#\s+(.+)$', content, re.MULTILINE)
    if m:
        return m.group(1).strip()
    return os.path.basename(content)[:80]

def store_report(agent_id, report_type, title, content, wiki_path):
    payload = {
        "agent_id":    agent_id,
        "report_type": report_type,
        "title":       title,
        "content":     content,
        "wiki_path":   wiki_path,
        "metadata":    {"source_pipeline": "openbrain-sync", "batch_ingest": True},
    }
    body = json.dumps({
        "jsonrpc": "2.0", "id": 1,
        "method": "tools/call",
        "params": {"name": "store_report", "arguments": payload},
    }).encode()
    req = urllib.request.Request(
        OPENBRAIN_MCP_URL, data=body, method="POST",
        headers={"Content-Type": "application/json", "x-brain-key": OPENBRAIN_KEY},
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        result = json.load(resp)
        return result.get("result", {}).get("content", [{}])[0].get("text", "?")

data = json.load(sys.stdin)
files = data.get("files", [])
print(f"[openbrain-sync] {len(files)} bestanden gevonden")

ok = skip = err = 0
for path in files:
    try:
        with open(path) as f:
            content = f.read()
        if len(content.strip()) < 100:
            skip += 1
            continue
        title     = extract_frontmatter_title(content)
        agent_id  = detect_agent(path)
        rtype     = detect_type(path)
        result    = store_report(agent_id, rtype, title, content, path)
        if "al opgeslagen" in result:
            skip += 1
        else:
            ok += 1
            print(f"  ✓ {os.path.basename(path)} → {agent_id}/{rtype}")
        time.sleep(0.5)  # rate limit OpenRouter embeddings
    except Exception as e:
        err += 1
        print(f"  ✗ {os.path.basename(path)}: {e}", file=sys.stderr)

print(f"\n[openbrain-sync] Klaar: {ok} opgeslagen, {skip} overgeslagen, {err} fouten")
```

---

## 9. Pipeline Integratie (store_report na elke write)

Voor elke pipeline die een rapport schrijft, voegen we een finale `store_openbrain` stap toe. Voorbeeld voor `daily-executive-sync`:

```yaml
# Bestaande stap:
- id: write_discord
  command: |
    python3 -c "... schrijft naar wiki_path ..."
  stdin: '$aggregate.stdout'

# NIEUW: OpenBrain ingest stap
- id: store_openbrain
  command: |
    python3 -c "
    import subprocess, sys, datetime
    today = datetime.date.today().isoformat()
    wiki_path = f'/home/agent/wiki/agents/daily/{today}.md'
    try:
        with open(wiki_path) as f:
            content = f.read()
        result = subprocess.run([
            'python3', '/home/agent/workspace/.openclaw/workspace/scripts/openbrain-store.py',
            '--agent', 'muddy',
            '--type', 'daily-executive-sync',
            '--title', f'Executive Sync {today}',
            '--wiki-path', wiki_path,
            '--pipeline', 'daily-executive-sync',
        ], input=content, capture_output=True, text=True, timeout=30)
        print(result.stdout)
        if result.returncode != 0:
            print(result.stderr, file=sys.stderr)
    except Exception as e:
        print(f'OpenBrain store fout (niet kritiek): {e}', file=sys.stderr)
    "
```

**Prioriteit van pipeline-integraties** (meest waardevolle rapporten eerst):

| Pipeline | Agent | Prioriteit |
|----------|-------|-----------|
| `warren-revenue-strategy` | warren | P1 |
| `warren-booking-analytics` | warren | P1 |
| `gary-content-strategy` | gary | P1 |
| `daily-executive-sync` | muddy | P1 |
| `dario-tech-audit` | dario | P2 |
| `gary-booking-insights` | gary | P2 |
| `crux-weekly-audit` | dario | P2 |
| `weekly-kompas-sessie` | muddy | P2 |
| `project-status-snapshot` | muddy | P3 |
| overige pipelines | alle | P3 |

---

## 10. MCP Configuratie in OpenClaw

Agents krijgen direct toegang tot `search_reports` via claude MCP config in de VM:

```bash
# In de VM (of in openclaw-workspace/.claude/claude.json):
claude mcp add --transport http openclaw-reports \
  https://<YOUR_PROJECT_REF>.supabase.co/functions/v1/openclaw-reports-mcp \
  --header "x-brain-key: <MCP_ACCESS_KEY>"
```

Dit voegt de volgende sectie toe aan `~/.claude.json` in de VM:

```json
{
  "mcpServers": {
    "openclaw-reports": {
      "type": "http",
      "url": "https://<YOUR_PROJECT_REF>.supabase.co/functions/v1/openclaw-reports-mcp",
      "headers": {
        "x-brain-key": "<MCP_ACCESS_KEY>"
      }
    }
  }
}
```

**AGENTS.md update voor agents:**

Voeg toe aan het hoofd AGENTS.md:

```markdown
## OpenBrain — Rapport Kennisbasis

Je hebt toegang tot OpenBrain via `openclaw-reports` MCP tools:

| Tool | Wanneer gebruiken |
|------|-------------------|
| `search_reports` | "Wat heeft Warren vorige maand aanbevolen over Google Conversie?" |
| `list_reports` | "Geef een overzicht van alle Dario audits van de afgelopen 2 weken" |
| `get_report` | "Toon de volledige inhoud van het revenue rapport van week 16" |
| `report_stats` | "Hoeveel rapporten heeft Gary dit kwartaal geschreven?" |
| `store_report` | Automatisch — wordt door pipelines aangeroepen, niet handmatig |
```

---

## 11. Environment Variabelen

Voeg toe aan `~/openclaw-workspace/.env`:

```bash
# OpenBrain integratie
OPENBRAIN_MCP_URL=https://<YOUR_PROJECT_REF>.supabase.co/functions/v1/openclaw-reports-mcp
OPENBRAIN_KEY=<jouw_MCP_ACCESS_KEY>
```

De Supabase project ref en access key staan al in de OpenBrain Credential Tracker (`credential-tracker.md`).

---

## 12. Implementatievolgorde

### Fase 1 — OpenBrain Setup (dag 1, ~2 uur)

**Op de host in `~/OpenBrain/`:**

1. SQL migratie uitvoeren in Supabase dashboard:
   - Tabel `agent_reports` aanmaken (zie §5.1)
   - Zoekfunctie `match_agent_reports` aanmaken (zie §5.2)
   - Verificatie: Table Editor toont `agent_reports` met correcte kolommen

2. Edge Function aanmaken en deployen:
   ```bash
   cd ~/OpenBrain
   supabase functions new openclaw-reports-mcp
   # Schrijf deno.json + index.ts (zie §6)
   supabase functions deploy openclaw-reports-mcp --no-verify-jwt
   ```

3. Health check:
   ```bash
   curl https://<PROJECT_REF>.supabase.co/functions/v1/openclaw-reports-mcp/health
   # Verwacht: {"status":"ok","service":"openclaw-reports-mcp"}
   ```

### Fase 2 — Helper Scripts (dag 1, ~1 uur)

**In `~/openclaw-workspace/.openclaw/workspace/scripts/`:**

4. `openbrain-store.py` aanmaken (zie §7)
5. `openbrain-batch-ingest.py` aanmaken (zie §8)
6. `.env` updaten met `OPENBRAIN_MCP_URL` en `OPENBRAIN_KEY`

### Fase 3 — Batch Ingest Bestaande Rapporten (dag 1, ~30 min)

7. `openbrain-sync.lobster` pipeline aanmaken (zie §8)
8. Eenmalig draaien:
   ```bash
   # In de VM, in Claude Code:
   # trigger de openbrain-sync pipeline manueel
   ```
9. Verificeren: `report_stats` tool toont 40+ rapporten

### Fase 4 — Pipeline Integraties (dag 2, ~3 uur)

10. P1 pipelines updaten met `store_openbrain` stap:
    - `warren-revenue-strategy.lobster`
    - `warren-booking-analytics.lobster`
    - `gary-content-strategy.lobster`
    - `daily-executive-sync.lobster`

11. Na volgende cron run: verify nieuwe rapporten verschijnen in OpenBrain

### Fase 5 — MCP Configuratie (dag 2, ~30 min)

12. MCP server toevoegen aan claude config in workspace
13. AGENTS.md updaten met OpenBrain gebruik instructies
14. Test: Muddy of Warren vraagt "wat zijn de laatste revenue aanbevelingen?"

### Fase 6 — P2/P3 Pipelines (week 2)

15. Resterende pipelines integreren op basis van prioriteit (§9)
16. `openbrain-sync.lobster` als wekelijkse cron instellen als vangnet

---

## 13. Risico's en Mitigaties

| Risico | Kans | Impact | Mitigatie |
|--------|------|--------|-----------|
| OpenRouter rate limit bij batch ingest | Middel | Laag | `time.sleep(0.5)` per request in batch script |
| VM heeft geen internet bij ingest | Laag | Middel | Test connectivity (`curl supabase.co`) voor de run |
| MCP_ACCESS_KEY conflict (gedeeld met thoughts) | Laag | Laag | Zelfde key is prima — aparte tabel, aparte tools |
| Embedding kosten voor 50+ rapporten | Laag | Laag | ~$0.01 voor alle bestaande rapporten (text-embedding-3-small) |
| Pipeline stap faalt bij OpenBrain down | Laag | Laag | Stap is niet-kritiek, fout wordt gelogd maar pipeline gaat door |
| Duplicate rapporten bij herstart pipeline | Laag | Laag | `wiki_path` UNIQUE constraint voorkomt duplicaten |
| Supabase Edge Function cold start vertraging | Middel | Laag | Eerste call duurt ~2-3s, daarna warm |

---

## 14. Succescriteria

- [ ] `agent_reports` tabel aangemaakt in Supabase
- [ ] `openclaw-reports-mcp` Edge Function deployed en bereikbaar
- [ ] Health endpoint `/health` geeft `{"status":"ok"}` terug
- [ ] Batch ingest: 40+ bestaande wiki-vault rapporten opgeslagen
- [ ] `search_reports("revenue Google Conversie")` geeft relevante Warren rapporten terug
- [ ] P1 pipelines schrijven automatisch naar OpenBrain na wiki-vault write
- [ ] Agents kunnen via Claude Code `search_reports` aanroepen
- [ ] `report_stats` toont juiste verdeling over agents

---

## 15. Samenvatting Technische Keuzes

| Keuze | Alternatief | Reden |
|-------|------------|-------|
| Aparte `agent_reports` tabel | Bestaande `thoughts` tabel uitbreiden | Geen mixing van persoonlijke gedachten en agent-rapporten; rijker schema |
| batch-ingest Python script | Lobster-native steps | Betere foutafhandeling, rate limiting, dedup logica |
| `wiki_path` als dedup key | Content hash | Pad is stabiel en leesbaar voor debugging |
| Bestaande `MCP_ACCESS_KEY` hergebruiken | Aparte OPENCLAW_BRAIN_KEY | Minder complexity, één auth systeem voor OpenBrain |
| HTTP POST naar MCP JSON-RPC | Supabase client direct | Consistent met hoe andere clients (Claude Desktop) de API aanroepen |
| store_openbrain als pipeline stap (niet als MCP call) | MCP tool in pipeline | Lobster pipelines hebben geen MCP client; Python HTTP call is eenvoudiger |

---

*PRD-v11 — OpenBrain als Rapportage-Opslaglaag voor OpenClaw*  
*Gegenereerd op basis van analyse van OpenBrain, openclaw-sandbox en openclaw-workspace op 2026-04-23*
