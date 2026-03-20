<div align="center">

# Openclaw Sandbox

**A hypervisor-isolated command center for a multi-agent AI pipeline — controlled entirely from Discord.**

*Built on [Openclaw](https://github.com/openclaw/openclaw) + [NixOS MicroVM](https://github.com/astro/microvm.nix). Declarative, reproducible, and small enough to understand.*

---

[![NixOS](https://img.shields.io/badge/NixOS-MicroVM-7B5EA7?style=for-the-badge&logo=nixos)](https://github.com/astro/microvm.nix)
[![Hypervisor](https://img.shields.io/badge/Isolation-cloud--hypervisor-blue?style=for-the-badge&logo=linux)](https://github.com/cloud-hypervisor/cloud-hypervisor)
[![Discord](https://img.shields.io/badge/Interface-Discord-5865F2?style=for-the-badge&logo=discord)](https://discord.com)
[![Claude](https://img.shields.io/badge/Auth-Claude_Subscription-CC785C?style=for-the-badge)](https://claude.ai)
[![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](LICENSE)

</div>

---

## Why This Exists

[Openclaw](https://github.com/openclaw/openclaw) is a powerful multi-agent AI platform — but it's a complex Node.js application with full system access. Running it directly on your host means trusting a large, opaque codebase with your files, credentials, and network.

This sandbox wraps Openclaw in a **NixOS MicroVM** with **cloud-hypervisor** — giving you true hypervisor-level isolation. The agent can't touch your host. It can only see what you explicitly share via virtiofs. Everything is declarative: one `flake.nix` defines the entire environment, reproducibly.

The result: Openclaw's full multi-agent power — coordinator, writer, researcher, editor — without compromising your host system.

> **Looking for a simpler single-agent setup?**
> See [nanoclaw-sandbox](https://github.com/nouwnow/nanoclaw-sandbox) — the same hypervisor isolation pattern for a lightweight Telegram bot.

---

## What You Get

<div align="center">
<img src="assets/office-preview.png" alt="Mission Control — Pixel Art Office" width="820" />
<p><em>Mission Control — live pixel art view of your agent team. Characters walk to their desks when working, rest at the pool when done, and show speech bubbles with what they're actually writing.</em></p>
</div>

---

**One Discord bot. A full AI team behind it.**

```
You (Discord)
    │
    ▼
@OpenClaw Agent — coordinator
    ├── 🔍 Researcher  → finds sources, facts, background
    ├── ✍️  Writer      → drafts content
    └── 🎨 Editor      → refines and finalizes
         │
         ▼
    ~/openclaw-workspace/content/
```

Each sub-agent gets its own **Discord thread** — you watch the full pipeline live as it executes. Steer agents mid-task, kill them, inspect logs — all from Discord.

**From Discord:**
```
@OpenClaw Agent write a deep-dive article on AI trends in 2026 — use the full team
@OpenClaw Agent research the top 5 competitors of [company] and make a SWOT
@OpenClaw Agent every Monday at 9:00: generate the content calendar for the week
/subagents list
/log 2
/steer 1 focus more on the European market
```

---

## Architecture

```
Host (Linux)
└── NixOS MicroVM (cloud-hypervisor, 8GB RAM, 4 vCPU)
    ├── openclaw-gateway    (port 18789) ← coordinator, Discord-connected
    ├── openclaw-writer     (port 18790) ← leaf agent, no Discord
    ├── openclaw-researcher (port 18791) ← leaf agent, no Discord
    ├── openclaw-editor     (port 18792) ← leaf agent, no Discord
    ├── dashboard (Next.js) (port 3333)  ← Mission Control web UI
    └── virtiofs mounts
        ├── /nix/store        → host Nix store (read-only)
        └── /home/agent/workspace → ~/openclaw-workspace (read-write, persistent)
```

**Isolation model:**
- The VM runs under cloud-hypervisor — hardware-level separation from the host
- The agent user (uid 1000) can only write to the virtiofs workspace
- No SSH, no host network access beyond the tap interface
- `/nix/store` is shared read-only — no redundant downloads, fast builds

**Declarative:** The entire VM — packages, services, users, mounts — is defined in `flake.nix`. Rebuild with `nix build`. Version-locked via `flake.lock`.

---

## Requirements

- **Host OS:** Linux (Ubuntu 22.04+ / Debian 12+ / NixOS)
- **RAM:** 12 GB minimum (VM uses 8 GB by default)
- **Disk:** 20 GB free
- **KVM:** required (`/dev/kvm` accessible)
- **Nix:** with flakes enabled
- **Accounts:** [Claude Code](https://claude.ai/product/claude-code) subscription (Pro or Max), Discord account

---

## Quick Start

```bash
# 1. Clone
git clone https://github.com/nouwnow/openclaw-sandbox
cd openclaw-sandbox

# 2. Install Nix (skip if already installed)
curl -L https://nixos.org/nix/install | sh
echo "experimental-features = nix-command flakes" >> ~/.config/nix/nix.conf

# 3. KVM access
sudo usermod -aG kvm $USER  # log out and back in

# 4. Workspace
mkdir -p ~/openclaw-workspace/{.claude,.npm-global,.openclaw/agents/main/agent,.openclaw-bundled-plugins}

# 5. Create disk image for writable Nix store overlay
truncate -s 4G nix-store-rw.img
nix-shell -p e2fsprogs --run "mkfs.ext4 nix-store-rw.img"

# 6. Build the VM
nix build  # first time: 10–30 min

# 7. Network
sudo ./setup-network.sh

# 8. Start
./result/bin/virtiofsd-run   # terminal 1 — keep open
./result/bin/microvm-run     # terminal 2 — login: agent / agent
```

Then follow [OPENCLAW-SETUP.md](OPENCLAW-SETUP.md) to configure Discord and Anthropic auth.

---

## Installation

<details>
<summary>⚙️ Steps 1–4: Host preparation</summary>

### Step 1 — Host dependencies

```bash
sudo apt update && sudo apt install -y git curl iptables qemu-utils acl e2fsprogs
```

### Step 2 — Install Nix

```bash
curl -L https://nixos.org/nix/install | sh
. ~/.nix-profile/etc/profile.d/nix.sh
```

Enable flakes:
```bash
mkdir -p ~/.config/nix
echo "experimental-features = nix-command flakes" >> ~/.config/nix/nix.conf
```

### Step 3 — KVM access

```bash
sudo usermod -aG kvm $USER
# Log out and back in, then verify:
id | grep kvm
```

### Step 4 — Check UID/GID

```bash
id
# uid=1000(yourname) ...
```

If your uid/gid is **not** 1000, edit `flake.nix`:
```nix
users.groups.agent.gid = <your-gid>;
users.users.agent.uid  = <your-uid>;
```

</details>

<details>
<summary>🖥️ Steps 5–9: Build and start the VM</summary>

### Step 5 — Create workspace

```bash
mkdir -p ~/openclaw-workspace/{.claude,.npm-global,.openclaw/agents/main/agent,.openclaw-bundled-plugins}
chmod 777 ~/openclaw-workspace
```

### Step 6 — Create disk image

```bash
cd ~/openclaw-sandbox
truncate -s 4G nix-store-rw.img
nix-shell -p e2fsprogs --run "mkfs.ext4 nix-store-rw.img"
```

> The image must be formatted as ext4, not just allocated. `truncate` alone is not enough.

### Step 7 — Build the VM

```bash
nix build
```

First build: 10–30 minutes. Produces `./result/bin/microvm-run` and `./result/bin/virtiofsd-run`.

### Step 8 — Configure network

```bash
sudo ./setup-network.sh
```

> **Cold boot note:** Network settings are lost on host reboot. Always run `sudo ./setup-network.sh` before starting the VM after a reboot. See [README — Persistent network](#-persistent-network) to make this permanent.

### Step 9 — Start the VM

```bash
./result/bin/virtiofsd-run   # terminal 1 — filesystem bridge (keep open)
./result/bin/microvm-run     # terminal 2 — VM console, login: agent / agent
```

</details>

<details>
<summary>🤖 Steps 10–15: Openclaw and Discord setup</summary>

See [OPENCLAW-SETUP.md](OPENCLAW-SETUP.md) for the full step-by-step configuration.

> **Critical:** Openclaw's Anthropic plugin does **not** read `CLAUDE_CODE_OAUTH_TOKEN` from `.env`. You must create `auth-profiles.json` separately. The Discord pairing code works without this — but the agent won't actually respond to questions until it's configured. See [OPENCLAW-SETUP.md Step 4](OPENCLAW-SETUP.md#stap-4--anthropic-auth-configureren-auth-profiles).

</details>

---

## Daily Use

```bash
# Terminal 1 — filesystem bridge (keep open)
./result/bin/virtiofsd-run

# Terminal 2 — VM console
./result/bin/microvm-run
# login: agent / agent
```

Check the agent:
```bash
# In the VM
sudo systemctl status openclaw-gateway
tail -f /tmp/openclaw-gateway.log
```

---

## Multi-Agent Pipeline

Openclaw 2026.3.x has full native multi-agent support:

- **Agent bindings** — route Discord channels or DMs to specific agents
- **Subagent spawning** — coordinator spawns writer/researcher/editor as `run`-mode tasks
- **Discord thread binding** — each sub-agent automatically gets its own Discord thread via `registerDiscordSubagentHooks`
- **Live control** — `/subagents list`, `/steer <n> <msg>`, `/kill <n>`, `/log <n>` from Discord
- **Parallel broadcasting** — send one message to multiple agents simultaneously

To configure the pipeline, use Claude Code inside the VM:
```bash
cd ~/workspace && claude
```

Example:
```
Configure multi-agent orchestration in openclaw.json:
- coordinator role: main/orchestrator, listens on Discord
- writer, researcher, editor: leaf agents, local only
- enable registerDiscordSubagentHooks so each sub-agent gets its own Discord thread
```

---

## Mission Control Dashboard

A fully local Next.js dashboard that runs inside the VM and is accessible from your host browser at `http://10.0.1.2:3333`. No cloud, no external services — everything streams directly from the Openclaw gateway over WebSocket.

```
Host browser  →  http://10.0.1.2:3333
                      │
              Next.js (in VM, port 3333)
                      │  WebSocket + SSE
              openclaw-gateway (port 18789)
                      │
              coordinator / writer / researcher / editor
```

The dashboard has five tabs, each auto-refreshing from live gateway events:

### Tab 1 — Dashboard

Real-time overview of everything happening right now:

- **Agents panel** — all configured agents with name and session count
- **Active sessions** — open conversations per agent with token usage and age
- **Task backlog** — all queued, running, and completed tasks; create new tasks via the form
- **Live feed** — raw gateway push events streamed via SSE as they arrive
- **Stats bar** — pending / running count and active session count at a glance

Sessions and agents refresh every 5 minutes via polling. The live feed updates instantly via SSE — no polling.

### Tab 2 — 🏢 Office (Pixel Art)

A 2D pixel art simulation of your agent team rendered on an HTML5 Canvas. Characters react to live gateway events in real time:

| Agent state | Trigger | Animation |
|---|---|---|
| Walking to desk | `agent lifecycle: phase=start` | Character moves toward its assigned desk |
| Typing at desk | Arrived at desk | Hands-on-keyboard animation, green status dot |
| Speech bubble | `chat delta` event | What the agent is currently writing appears above its head |
| Walking to pool | `agent lifecycle: phase=end` | Character moves to the swimming pool to rest |
| Resting at pool | At pool | Floating arm animation, blue status dot |
| Error flash | `agent lifecycle: phase=error` | Red `!` above head, 3-second error state |
| Idle wandering | No active session | Character roams slowly between zones |

Each agent has a fixed desk, a shared meeting table, and a shared swimming pool. The status bar at the top always shows the current state of every agent.

### Tab 3 — Calendar (Scheduled Tasks)

Visual weekly calendar for all Openclaw cron jobs stored in `~/.openclaw/cron/jobs.json`:

- **Always Running** section — interval-based jobs (`kind: "every"`) shown as permanent badges
- **Weekly grid** — cron jobs plotted on the correct days based on the weekday field of the cron expression (`0 9 * * 1-5` → Mon–Fri)
- **Today highlight** — current day column is highlighted in red
- **Job detail modal** — click any job to see: agent, schedule expression, timezone, next/last run time, error count, and full prompt
- **Enable/disable toggle** and **delete** from the modal
- **New schedule form** — create a cron or interval job with agent, expression, timezone, prompt, and timeout

Schedules are read and written directly from `jobs.json`. The Openclaw gateway picks up changes on restart.

### Tab 4 — Memory

Browse the full conversation history of all agents, grouped by day:

- **Long-term memory section** — workspace Markdown files (`SOUL.md`, `USER.md`, `IDENTITY.md`, `HEARTBEAT.md`) shown per agent with word count and last-updated timestamp
- **Daily journal** — all sessions across all agents grouped by date, newest first, with total size per day
- **Day view** — click a day to see all sessions that ran that day, per agent, with start time and file size
- **Conversation view** — click a session to see the full chat rendered as a conversation: user messages on the right, assistant on the left, timestamps on each message
- **Search** — filter by agent name or date

Memory is read directly from `~/.openclaw/agents/{agent}/sessions/*.jsonl` — one file per conversation, date extracted from the first line.

### Tab 5 — Docs

Document viewer for all files the agents have created:

- **Category filter chips** — filter by `articles`, `newsletters`, `research`, `scripts`, or `other`
- **Full-text search** — searches title, slug, agent name, and date
- **File list** — sorted by date descending, with category badge, file size, and word count
- **Rendered markdown** — right panel renders the document with headers, bold, lists, code, and blockquotes
- **File header** — shows filename, category, size, word count, creation date, and which agent wrote it

All agents save output to `~/workspace/content/{category}/YYYY-MM-DD_{agent}_{slug}.md`. This convention is configured in each agent's `AGENTS.md` workspace instructions.

---

### Setup

The dashboard is a Next.js app located in `~/workspace/dashboard/`. It is configured as a **systemd service** in `flake.nix` and starts automatically with the VM — no manual steps needed after a `nix build`.

**First-time setup (in the VM):**
```bash
cd ~/workspace/dashboard
npm install
```

**After `nix build` + VM restart:** the dashboard builds and starts automatically. Available at `http://10.0.1.2:3333`.

**Check status:**
```bash
sudo systemctl status openclaw-dashboard
sudo journalctl -u openclaw-dashboard -f
```

> **Build time:** the systemd service runs `npm run build` on every start/restart (~15–20 seconds). The dashboard is only available after the build completes.

**Manual restart:**
```bash
sudo systemctl restart openclaw-dashboard
```

---

### NixOS + VM Challenges and Solutions

Running a Next.js app inside a NixOS MicroVM requires solving several non-obvious problems. These are all already solved in this repo — documented here so you understand why the config looks the way it does.

<details>
<summary><strong>1. systemd service fails with <code>spawn sh ENOENT</code></strong></summary>

**Problem:** `npm run build` in a systemd service immediately fails. `npm` runs scripts by invoking `sh` internally, but NixOS systemd services get a minimal `PATH` with no `/bin/sh`.

**Solution:** Add `path = [ pkgs.bash pkgs.nodejs_20 pkgs.coreutils ]` to the service definition in `flake.nix`. This puts bash, node, and standard tools in the service's `PATH`.

```nix
systemd.services.openclaw-dashboard = {
  path = [ pkgs.bash pkgs.nodejs_20 pkgs.coreutils ];
  ...
};
```

</details>

<details>
<summary><strong>2. Firewall blocks port 3333 — browser can't connect</strong></summary>

**Problem:** The Next.js server binds on `0.0.0.0:3333` inside the VM, but the NixOS firewall drops incoming packets from the host. `http://10.0.1.2:3333` times out in the browser.

**Solution:** Add port 3333 to `networking.firewall.allowedTCPPorts` in `flake.nix`. Already done.

**Quick fix without rebuild:**
```bash
sudo iptables -I INPUT -p tcp --dport 3333 -j ACCEPT
```

</details>

<details>
<summary><strong>3. Gateway WebSocket protocol — 8 non-obvious requirements</strong></summary>

The Openclaw gateway uses a custom WebSocket protocol. None of this is documented publicly — it was reverse-engineered from the minified source in the Nix store.

| Requirement | What happens if you get it wrong |
|---|---|
| Wait for `connect.challenge` before sending `connect` | Gateway closes silently |
| Include `"type": "req"` in every request frame | `1008 invalid request frame` |
| Use `client.id = "openclaw-control-ui"` | All scopes are cleared → `missing scope: operator.read` |
| Include `Origin: http://127.0.0.1:3333` header | `origin missing or invalid` |
| Set `dangerouslyDisableDeviceAuth: true` in `openclaw.json` | Ed25519 keypair required → connection refused |
| Auth is plain token: `auth: { token: "..." }` | Trying HMAC causes auth failure |
| Response data is in `payload`, not `result` | Silent empty responses |
| `agents.list` / `sessions.list` return wrapper objects | Panels show 0 items despite 200 OK |

All of these are solved in `src/lib/gateway.ts`.

</details>

<details>
<summary><strong>4. <code>tasks.list</code> does not exist in the gateway</strong></summary>

**Problem:** The gateway has no `tasks.list` RPC method. Calling it returns `unknown method: tasks.list`.

**Solution:** Tasks are stored locally in `~/.openclaw/tasks.json` and read/written directly by the Next.js API routes — no gateway needed.

</details>

<details>
<summary><strong>5. <code>ws</code> package requires <code>serverExternalPackages</code></strong></summary>

**Problem:** Next.js on Node.js 20+ tries to bundle the `ws` WebSocket package. This breaks at runtime with `bufferutil.mask is not a function`.

**Solution:** Add to `next.config.js`:
```js
serverExternalPackages: ['ws', 'bufferutil', 'utf-8-validate']
```

</details>

<details>
<summary><strong>6. Agent memory is UUID-based, not date-based</strong></summary>

**Problem:** Openclaw session files are named by UUID (`3759cfbe-....jsonl`), not by date. There is no `sessions.list` gateway method that returns dates.

**Solution:** The memory API reads the first line of each `.jsonl` file to extract the `timestamp` field, then groups sessions by date client-side.

</details>

---

### Gateway Push Events (for the Pixel Art Office)

The office simulation is driven by live WebSocket events. Key event types:

```javascript
// Agent starts working → walk to desk
{ event: 'agent', payload: { stream: 'lifecycle', data: { phase: 'start' }, sessionKey: 'agent:coordinator:...' }}

// Agent writing → speech bubble
{ event: 'agent', payload: { stream: 'assistant', data: { delta: '...', text: '...' }, sessionKey: '...' }}

// Agent done → walk to pool
{ event: 'agent', payload: { stream: 'lifecycle', data: { phase: 'end' }, sessionKey: '...' }}

// Cron job fired → coordinator goes to work
{ event: 'cron', payload: { action: 'started', jobId: '...' }}
```

Extract `agentId` from `sessionKey`: `'agent:coordinator:cron:...'` → `split(':')[1]` → `'coordinator'`

See [OPENCLAW-SETUP.md — Stap 9](OPENCLAW-SETUP.md) for the full gateway protocol reference.

---

## Configuration

### Resource scaling

```nix
# flake.nix
microvm = {
  mem  = 8192;   # 8 GB  — default
  # mem = 16384; # 16 GB — for heavy swarm pipelines
  vcpu = 4;
  # vcpu = 8;    # for parallel agent execution
};
```

### Mount additional directories

```nix
{ source = "/home/youruser/projects";
  mountPoint = "/home/agent/projects";
  tag = "projects";
  proto = "virtiofs"; }
```

### After `nix build` upgrades

The Nix store hash changes with each upgrade. Regenerate the plugin overlay:

```bash
# On the host:
~/openclaw-sandbox/build-plugin-overlay.sh

# Then in the VM:
sudo systemctl restart openclaw-gateway
```

---

## Persistent Network

After a host reboot, the tap interface is gone. To make it permanent:

**`/etc/systemd/network/10-vmtap1.netdev`:**
```ini
[NetDev]
Name=vmtap1
Kind=tap

[Tap]
User=youruser
```

**`/etc/systemd/network/10-vmtap1.network`:**
```ini
[Match]
Name=vmtap1

[Network]
Address=10.0.1.1/24
IPMasquerade=ipv4
IPForward=yes
```

```bash
sudo systemctl enable --now systemd-networkd
sudo systemctl restart systemd-networkd
```

---

## Project Structure

```
openclaw-sandbox/
├── flake.nix                  # Complete VM definition
├── flake.lock                 # Pinned dependency versions
├── setup-network.sh           # Host network setup (vmtap1 + NAT)
├── build-plugin-overlay.sh    # Rebuild plugin overlay after nix build upgrades
├── nix-store-rw.img           # Writable ext4 overlay for /nix/store in VM
├── README.md                  # This file
└── OPENCLAW-SETUP.md          # Openclaw + Discord + multi-agent setup guide

~/openclaw-workspace/          # Persistent state (virtiofs, survives VM reboots)
├── .claude/                   # Claude Code auth
├── .npm-global/               # Global npm packages incl. claude binary
├── .claude.json               # Claude Code session (symlinked into VM)
├── .env                       # Secrets: Discord token, config paths
├── .openclaw/                 # Openclaw gateway state
│   ├── agents/main/agent/
│   │   └── auth-profiles.json # Anthropic OAuth token (required for AI responses)
│   └── workspace/
│       └── AGENTS.md          # Coordinator instructions: always use thread: true
├── .openclaw-bundled-plugins/ # Plugin overlay (74 plugins, workaround for Nix)
├── content/                   # Agent output — files written by the pipeline
└── dashboard/                 # Mission Control web dashboard (Next.js)
    ├── src/app/api/feed/      # SSE stream — gateway push events → browser
    ├── src/app/api/agents/    # REST — agents.list
    ├── src/app/api/sessions/  # REST — sessions.list
    └── src/lib/gateway.ts     # Gateway WebSocket client (challenge-response)
```

---

## Troubleshooting

<details>
<summary>Bot connects but doesn't respond to questions</summary>

The Discord pairing flow is built into the gateway and works without Anthropic auth. Actual AI responses require `auth-profiles.json`:

```bash
cat ~/openclaw-workspace/.openclaw/agents/main/agent/auth-profiles.json
```

Missing? See [OPENCLAW-SETUP.md Step 4](OPENCLAW-SETUP.md).

</details>

<details>
<summary>Bot ignores messages in server channels</summary>

In server channels, the bot only responds to @mentions:
```
@OpenClaw Agent hello
```

In DMs, no mention is needed.

</details>

<details>
<summary>Plugin manifests not found (0 plugins loaded)</summary>

```bash
ls ~/openclaw-workspace/.openclaw-bundled-plugins/ | wc -l  # should be ~74
~/openclaw-sandbox/build-plugin-overlay.sh
# then in the VM:
sudo systemctl restart openclaw-gateway
```

</details>

<details>
<summary>virtiofs: EPERM on write to workspace</summary>

UID/GID mismatch. Check with `id` on the host and ensure `users.users.agent.uid` and `users.groups.agent.gid` in `flake.nix` match your host uid/gid (default 1000).

</details>

<details>
<summary>cloud-hypervisor: "Failed connecting backend"</summary>

virtiofsd was not started before the VM. Always start `virtiofsd-run` first, then `microvm-run`.

</details>

<details>
<summary>Network not working in VM</summary>

```bash
# In the VM:
ping 8.8.8.8
# Not reachable? On the host:
sudo ~/openclaw-sandbox/setup-network.sh
```

</details>

<details>
<summary>Dashboard shows "Gateway connection closed" or 500 errors</summary>

The gateway requires a specific connection handshake. Common causes:

- **Wrong `client.id`** — must be `"openclaw-control-ui"` (not `"gateway-client"` or `"cli"`)
- **Missing `type: "req"`** — every request frame must include this field
- **`connect` sent before challenge** — wait for `connect.challenge` event before sending `connect`
- **Missing `dangerouslyDisableDeviceAuth`** — without this in `openclaw.json`, the gateway clears all scopes for clients without an Ed25519 device keypair, causing `missing scope: operator.read`
- **Wrong `GATEWAY_URL`** — inside the VM use `ws://127.0.0.1:18789`; from the host use `ws://10.0.1.2:18789`

See [OPENCLAW-SETUP.md — Stap 9](OPENCLAW-SETUP.md) for the full protocol and config.

</details>

<details>
<summary>Dashboard agents/sessions panel shows 0 items despite 200 OK</summary>

The gateway wraps list results in an object: `agents.list` returns `{ agents: [...], defaultId, ... }` and `sessions.list` returns `{ sessions: [...], count, ... }`. The API routes must extract the nested array, not return the wrapper object directly.

</details>

<details>
<summary>Claude Code re-asks for login after reboot</summary>

```bash
# In the VM, after first login:
cp ~/.claude.json ~/workspace/.claude.json
```

The symlink is already configured in `flake.nix` — this only needs to be done once.

</details>

---

## FAQ

**Why NixOS MicroVM instead of Docker?**

Docker provides process-level isolation. A MicroVM provides hypervisor-level isolation — the agent runs in a separate kernel with its own memory space. There's no shared kernel, no escape via kernel vulnerabilities, and no host process namespace access. For an application with full system capabilities like Openclaw, this matters.

**Why Openclaw instead of building your own?**

Openclaw provides multi-agent orchestration, Discord/Telegram/WhatsApp channels, scheduling, memory, and tooling that would take months to build. This sandbox provides the isolation layer that makes running it safe.

**Does this work with a Claude API key instead of a subscription?**

Yes. Replace the OAuth token in `auth-profiles.json` with:
```json
{
  "version": 1,
  "profiles": {
    "anthropic:default": {
      "type": "apiKey",
      "provider": "anthropic",
      "token": "sk-ant-api03-..."
    }
  }
}
```

**How do I update Openclaw?**

Edit the `nix-openclaw` input in `flake.nix`, run `nix flake update`, then `nix build`. After the build, regenerate the plugin overlay with `build-plugin-overlay.sh`.

**Can I run this alongside nanoclaw-sandbox?**

Yes. They use different subnets (nanoclaw: `10.0.0.x`, openclaw: `10.0.1.x`), different tap interfaces (`vmtap0` / `vmtap1`), and different vsock CIDs. They can run in parallel.

---

## Related Projects

| Project | Description |
|---------|-------------|
| [nanoclaw-sandbox](https://github.com/nouwnow/nanoclaw-sandbox) | Single-agent Telegram bot — same hypervisor isolation, simpler setup |
| [openclaw/openclaw](https://github.com/openclaw/openclaw) | The Openclaw platform this sandbox runs |
| [astro/microvm.nix](https://github.com/astro/microvm.nix) | NixOS MicroVM framework |

---

## License

MIT
