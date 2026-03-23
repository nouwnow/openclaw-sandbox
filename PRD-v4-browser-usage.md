# BROWSER-USAGE.md — Browser & Web Automation voor OpenClaw

> Architectuur, tool-keuzeregels en configuratie voor geautomatiseerde toegang tot WordPress (staging: logiesopdreef.nl).

---

## Overzicht

OpenClaw heeft een **ingebouwde browser tool** met een eigen profielsysteem gebaseerd op CDP (Chrome DevTools Protocol). Er zijn drie profielen relevant voor onze setup, aangevuld met de WordPress REST API voor content en data.

**Stelregel:** API waar het kan, browser waar het moet.

---

## De vier tools

### 1. OpenClaw managed browser (`openclaw` profiel)

OpenClaw start en beheert een eigen, geïsoleerde Chromium-instantie op een eigen CDP-poort (standaard `18800`). Geen gedeeld browserprofiel, geen conflict met je dagelijkse browser.

**Kenmerken:**
- Eigen `userDataDir`, volledig geïsoleerd
- Headless of headed (instelbaar)
- `storageState` (cookies + localStorage) opslaan naar bestand → hergebruiken bij volgende sessie
- Eenmalig inloggen in WordPress → state opslaan → daarna automatisch ingelogd

**Beste voor:**
- Alles wat via de browser-UI moet op de staging site
- Pagina's bekijken, screenshots, formulieren invullen
- WordPress Admin UI (plugins beheren, instellingen, logs)
- Matomo dashboard (als Matomo geen API biedt voor de gewenste data)

**Zwakke punten:**
- Eerste keer handmatig inloggen nodig (daarna via storageState)
- Chromium moet draaien in de VM (display/Xvfb nodig bij headless=false, of headless gebruiken)

---

### 2. Chrome relay (`user` profiel / existing-session)

OpenClaw koppelt via Chrome DevTools MCP aan een bestaande Chrome/Brave/Chromium-sessie op de host. Hergebruikt je echte ingelogde sessie inclusief cookies, passkeys en local storage.

**Kenmerken:**
- `driver: "existing-session"`, `attachOnly: true`
- Koppelt aan draaiende browser op de host via CDP
- Vereist dat Chrome start met `--remote-debugging-port`
- Host-specifiek: werkt alleen als gateway op dezelfde machine draait of via remote CDP

**Beste voor:**
- Taken waarbij je al ingelogd bent en die sessie wil hergebruiken
- Passkeys of complexe auth die moeilijk te automatiseren zijn
- Handmatige interventie + AI-sturing combineren

**Zwakke punten:**
- OpenClaw draait in microVM (10.0.1.2), browser op host (10.0.1.1) → vereist remote CDP configuratie
- Chrome moet gestart zijn met `--remote-debugging-port=9222`
- Minder geïsoleerd, meer veiligheidsrisico bij machtige agent-acties

---

### 3. WordPress REST API + Application Passwords

Voor alles wat via de WordPress REST API kan: geen browser nodig, sneller, betrouwbaarder.

**Kenmerken:**
- Application Password aanmaken in WordPress Admin → opslaan als env-variabele
- Werkt met alle REST API endpoints: posts, pages, media, users, settings
- WooCommerce Bookings / klantgegevens via WooCommerce REST API (vereist WooCommerce plugin)
- Matomo heeft eigen REST API (`/matomo/?module=API`)

**Beste voor:**
- Posts aanmaken, lezen, aanpassen, verwijderen
- Klantgegevens en boekingen ophalen (WooCommerce)
- Bulk-operaties (meerdere posts in één run)
- Matomo statistieken ophalen

**Zwakke punten:**
- Alleen wat de REST API ondersteunt
- Voor puur-UI taken (plugin-instellingen, visuele editor) niet geschikt

---

### 4. Remote CDP profiel (`remote` profiel)

OpenClaw verbindt via `cdpUrl` met een browser die op een ander adres draait. Relevant als je de browser op de host wil laten draaien maar OpenClaw in de VM.

**Kenmerken:**
- `cdpUrl: "http://10.0.1.1:9222"` (host-IP vanuit VM)
- Browser draait op host, agent in VM
- Maximale isolatie: browser en agent gescheiden

**Beste voor:**
- Bestaande browser-sessie op host hergebruiken vanuit VM
- Gevallen waar headless in de VM problemen geeft

**Zwakke punten:**
- Browser moet op host gestart zijn met `--remote-debugging-port`
- SSRF-policy moet private network toestaan

---

## Tool-keuzeregels per taak

| Taak | Tool | Profiel / Methode |
|------|------|--------------------|
| Posts lezen, schrijven, aanpassen | WP REST API | Application Password |
| Pagina's publiceren / drafts | WP REST API | Application Password |
| Media uploaden | WP REST API | Application Password |
| Klantgegevens ophalen | WooCommerce REST API | Application Password |
| Boekingen bekijken / aanpassen | WooCommerce REST API | Application Password |
| Matomo statistieken | Matomo API | API token |
| Matomo data (UI-only) | Browser tool | `openclaw` profiel |
| WordPress logs ophalen (error log) | `bash` → bestand lezen | — (via Local WP pad) |
| Staging site bekijken / navigeren | Browser tool | `openclaw` profiel |
| WordPress Admin UI-navigatie | Browser tool | `openclaw` profiel (storageState) |
| Plugin-instellingen aanpassen | Browser tool | `openclaw` profiel (storageState) |
| Inloggen met passkey / 2FA | Browser tool | `user` profiel (host Chrome) |
| Bestaande sessie hergebruiken | Browser tool | `user` of `remote` profiel |

**Samengevat:**
- **Standaard / automation werk** → `openclaw` managed profiel
- **Bestaande ingelogde sessie / passkeys** → `user` of `remote` profiel
- **Content, data, bulk** → WordPress / WooCommerce / Matomo REST API

---

## Netwerkconfiguratie

### VM ↔ Host bereikbaarheid

OpenClaw draait in de microVM op `10.0.1.2`. De host is bereikbaar op `10.0.1.1`. De staging site (Local WP) draait op de host.

**Situatie:** Local WP's nginx luistert al op `0.0.0.0:80` en `0.0.0.0:443` — de staging site is dus al bereikbaar vanuit de VM op `10.0.1.1`. Het enige dat ontbreekt is DNS-oplossing van de lokale domeinnaam.

**Oplossing (geïmplementeerd):** Hosts-entry in NixOS `flake.nix`:
```nix
networking.hosts = {
  "10.0.1.1" = [ "www.logiesopdreef.nl" ];
};
```

Na `nixos-rebuild` lost de VM `www.logiesopdreef.nl` op naar `10.0.1.1`, en nginx serveert de staging site op basis van de `Host:`-header.

### SSL-certificaat

Local WP gebruikt een eigen CA voor HTTPS. De VM vertrouwt dit certificaat standaard niet. Opties:
- **Tijdelijk:** browser-tool met `--ignore-https-errors` (of OpenClaw browser config equivalent)
- **Permanent:** Local WP CA exporteren en toevoegen aan de VM's `security.pki.certificateFiles` in `flake.nix`

### SSRF-policy

OpenClaw blokkeert standaard requests naar private netwerken. Voor de staging site moet dit worden toegestaan:

```json5
browser: {
  ssrfPolicy: {
    dangerouslyAllowPrivateNetwork: true
  }
}
```

---

## Configuratie: `openclaw.json`

Voeg het volgende toe aan `/home/michiel/openclaw-workspace/.openclaw/openclaw.json`:

```json5
{
  // ... bestaande config ...
  "browser": {
    "enabled": true,
    "defaultProfile": "openclaw",
    "ssrfPolicy": {
      "dangerouslyAllowPrivateNetwork": true
    },
    "headless": true,
    "profiles": {
      "openclaw": {
        "cdpPort": 18800,
        "color": "#FF4500"
      },
      "user": {
        "driver": "existing-session",
        "attachOnly": true,
        "color": "#00AA00"
      },
      "remote": {
        "cdpUrl": "http://10.0.1.1:9222",
        "attachOnly": true,
        "color": "#0066CC"
      }
    }
  }
}
```

**Toelichting:**
- `defaultProfile: "openclaw"` → managed browser standaard
- `headless: true` → geen display nodig in de VM
- `ssrfPolicy.dangerouslyAllowPrivateNetwork: true` → bereik staging op 10.0.1.x
- `user` profiel → voor als je bestaande Chrome-sessie wil hergebruiken
- `remote` profiel → browser op host via CDP

---

## Setup: WordPress Application Passwords

1. Log in op WordPress Admin van de staging site
2. Ga naar **Gebruikers → Jouw profiel → Application Passwords**
3. Maak een nieuw Application Password aan (naam: `openclaw-agent`)
4. Kopieer het wachtwoord (eenmalig zichtbaar)
5. Voeg toe aan `/home/michiel/openclaw-workspace/.env`:

```bash
WP_STAGING_URL=https://www.logiesopdreef.nl
WP_API_USER=<jouw-wp-gebruikersnaam>
WP_API_PASSWORD=<application-password>
```

**Gebruik in agent (via `web_fetch` of `bash`):**
```bash
# Posts ophalen
curl -u "$WP_API_USER:$WP_API_PASSWORD" \
  "$WP_STAGING_URL/wp-json/wp/v2/posts?per_page=10"

# Post aanmaken
curl -X POST -u "$WP_API_USER:$WP_API_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","content":"Inhoud","status":"draft"}' \
  "$WP_STAGING_URL/wp-json/wp/v2/posts"
```

---

## Setup: Matomo API

Als Matomo draait op de staging site of apart:

```bash
MATOMO_URL=https://www.logiesopdreef.nl/matomo
MATOMO_TOKEN=<jouw-api-token>  # Zie Matomo: Beheer → Persoonlijke instellingen → API-authenticatietoken

# Statistieken ophalen (bezoekersoverzicht)
curl "$MATOMO_URL/?module=API&method=VisitsSummary.get&idSite=1&period=week&date=today&format=JSON&token_auth=$MATOMO_TOKEN"
```

---

## Setup: Browser met persistente auth (storageState)

Eenmalig inloggen en state opslaan via het login-script op de **host** (Ubuntu kan Playwright-binaries draaien; NixOS VM kan dat niet):

```bash
# Op de HOST — eerste keer setup (Chromium installeren + inloggen)
cd /home/michiel/openclaw-workspace/.openclaw
npx playwright install chromium
node wp-login.mjs
```

Het script staat op: `/home/michiel/openclaw-workspace/.openclaw/wp-login.mjs`
De storageState wordt opgeslagen op: `/home/michiel/openclaw-workspace/.openclaw/wp-staging-auth.json`
In de VM zichtbaar als: `/home/agent/workspace/.openclaw/wp-staging-auth.json`

**Daarna (bij verlopen sessie):** alleen `node wp-login.mjs` opnieuw uitvoeren — Chromium is al geïnstalleerd.

Bij gebruik in Playwright MCP:
```bash
npx @playwright/mcp@latest --storage-state=/home/agent/workspace/.openclaw/wp-staging-auth.json
```

---

## Agent-instructies (toe te voegen aan AGENTS.md / TOOLS.md)

Voeg het volgende toe aan de relevante agent workspaces (bv. Elon/Gary voor content, Warren voor data):

```markdown
## Browser & WordPress tool-gebruik

### Regel 1: REST API eerst
Voor posts, pagina's, media, klantgegevens en boekingen: gebruik altijd de WordPress REST API
via `web_fetch` met Basic Auth (WP_API_USER + WP_API_PASSWORD uit .env).
Endpoint: $WP_STAGING_URL/wp-json/wp/v2/

### Regel 2: openclaw browser profiel voor UI-taken
Als je de WordPress Admin UI nodig hebt (plugin-instellingen, logs bekijken, Matomo dashboard):
gebruik de browser tool met profile="openclaw" (managed, headless, geïsoleerd).
Auth: hergebruik storageState uit /home/agent/workspace/.openclaw/wp-staging-auth.json

### Regel 3: user/remote profiel alleen met toestemming
Gebruik profile="user" of profile="remote" (bestaande Chrome-sessie) alleen als:
- de taak passkeys of 2FA vereist, of
- de gebruiker expliciet vraagt om bestaande sessie te hergebruiken.
Altijd eerst bevestigen bij de gebruiker.

### Regel 4: staging only
Browser- en API-acties gaan ALTIJD naar de staging site (WP_STAGING_URL).
NOOIT automatisch acties uitvoeren op de live productiesite (logiesopdreef.nl zonder www)
tenzij de gebruiker dit expliciet heeft bevestigd.
```

---

## Samenvatting architectuur

```
┌─────────────────────────────────────────────────────┐
│  Host (Ubuntu, 10.0.1.1)                            │
│  ┌─────────────────┐  ┌──────────────────────────┐  │
│  │  Local WP       │  │  Chrome (optioneel)       │  │
│  │  Staging site   │  │  --remote-debugging-port  │  │
│  │  www.logieso..  │  │  =9222                    │  │
│  └────────┬────────┘  └───────────┬──────────────┘  │
└───────────│───────────────────────│─────────────────┘
            │ HTTP/HTTPS            │ CDP
            │                       │
┌───────────▼───────────────────────▼─────────────────┐
│  MicroVM (NixOS, 10.0.1.2)                          │
│  ┌──────────────────────────────────────────────┐   │
│  │  OpenClaw Gateway                            │   │
│  │                                              │   │
│  │  Browser tool:                               │   │
│  │  ┌────────────────┐  ┌──────────────────┐   │   │
│  │  │ openclaw prof. │  │ remote/user prof.│   │   │
│  │  │ Managed Chrom. │  │ CDP → host       │   │   │
│  │  │ Port 18800     │  │ Port 9222        │   │   │
│  │  └────────────────┘  └──────────────────┘   │   │
│  │                                              │   │
│  │  Agents: Muddy, Elon, Gary, Warren           │   │
│  │  → web_fetch (WP REST API)                  │   │
│  │  → browser tool (UI-taken)                  │   │
│  └──────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────┘
```

---

## Eerste opdracht: checklist staging site

Voor de eerste verbinding met `www.logiesopdreef.nl` staging:

- [ ] Controleer of staging bereikbaar is van VM: `curl http://10.0.1.1:<localwp-poort>/`
- [ ] Voeg hostnaam toe aan VM via `flake.nix` → `nix build` → VM herstarten (zie OPENCLAW-SETUP.md § "VM herstarten na nix build")
- [ ] Maak WordPress Application Password aan
- [ ] Sla credentials op in `.env`
- [ ] Test REST API: `GET /wp-json/wp/v2/posts`
- [ ] Voeg `browser` config toe aan `openclaw.json`
- [ ] Eenmalig browser login → `wp-staging-auth.json` opslaan
- [ ] Test Matomo API token (indien beschikbaar)
- [ ] Voeg tool-regels toe aan AGENTS.md van relevante agents

---

*Aangemaakt: 2026-03-23 | Gebaseerd op OpenClaw docs, Playwright MCP docs, browser-use docs*
