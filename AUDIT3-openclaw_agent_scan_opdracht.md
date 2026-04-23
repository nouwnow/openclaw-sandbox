# AGENT INSTRUCTIE: OpenClaw Kennissysteem Scan & Regelcreatie

## Doel van deze opdracht

Jij bent een analyse-agent die de volledige OpenClaw-omgeving scant. Je taak is tweeledig:

1. **Analyseer** hoe data momenteel gezocht, gevonden en gebruikt wordt door agents in OpenClaw.
2. **Stel regels op** voor alle agents zodat ze weten welke informatie via de **Karpathy Wiki-Vault** gezocht moet worden, en welke via de **Open Brain MCP server**.

Voer deze opdracht stap voor stap uit zoals hieronder beschreven.

---

## DEEL 1 — ACHTERGROND: De twee kennissystemen

Lees deze sectie volledig voordat je begint met scannen. De regels die je opstelt zijn gebaseerd op de fundamentele verschillen tussen beide systemen.

### Systeem A: Karpathy Wiki-Vault

**Wat het is:**
Een compilatiesysteem. Wanneer nieuwe informatie binnenkomt, verwerkt een agent die informatie direct: het leest de bron, extraheert wat relevant is, schrijft een gesynthetiseerde wiki-pagina, legt kruisverwijzingen aan en markeert tegenstrijdigheden. De kennis wordt één keer "gecompileerd" en daarna als navigeerbaar artefact bewaard.

**Fundamentele toegevoegde waarde:**
- Pre-gebouwde syntheses: de denkarbeid is al gedaan vóórdat een vraag gesteld wordt.
- Evolutie van begrip zichtbaar over tijd: hoe ideeën zich ontwikkelen is traceerbaar.
- Kruisverwijzingen tussen bronnen worden automatisch aangelegd.
- Tegenstrijdigheden worden gemarkeerd op het moment van ingestie.
- Ideaal voor diep, narratief begrip van een complex onderwerp.

**Wanneer het uitblinkt:**
- Onderzoek waarbij meerdere bronnen op elkaar voortbouwen.
- Vragen waarbij de verbinding tússen bronnen de waarde is, niet één enkel feit.
- Persoonlijke kennisevolutie over weken en maanden.
- Onderwerpen die narratief begrip vereisen (samenvattingen, conceptuele verbanden, thematische overzichten).

**Beperkingen om te onthouden:**
- Niet geschikt voor hoog-volume operationele data (boven ~10.000 documenten).
- Niet geschikt voor gelijktijdige schrijftoegang door meerdere agents.
- Wiki-pagina's kunnen verouderd raken zonder dat dit zichtbaar is.
- Bevat AI-redactionele keuzes: nuances kunnen verloren gaan.
- Ongeschikt voor precieze gestructureerde queries (filteren op datum, bedrag, categorie, etc.).

---

### Systeem B: Open Brain MCP Server

**Wat het is:**
Een query-time systeem. Nieuwe informatie wordt direct en getrouw opgeslagen in een gestructureerde SQL-database: getagd, gecategoriseerd, doorzoekbaar. Er wordt géén synthese gedaan bij ingestie. De zware denkarbeid vindt plaats op het moment dat een agent een vraag stelt — dan doorzoekt de AI de relevante rijen en produceert een verse, precieze synthese op basis van de ruwe data.

**Fundamentele toegevoegde waarde:**
- Feiten zijn traceerbaar tot aan de originele bron met tijdstempel.
- Ondersteunt gelijktijdige toegang door meerdere agents.
- Schaalt naar duizenden entries over tientallen categorieën.
- Precieze gestructureerde queries mogelijk (filteren, sorteren, combineren).
- Tegenstrijdigheden blijven bewaard als aparte rijen — worden niet automatisch opgelost.
- Duurzaam: verouderde feiten zijn herkenbaar als verouderd, niet als actieve misinformatie.

**Wanneer het uitblinkt:**
- Operationele vragen met precieze filters ("alle vergadernotities uit Q1 over prijsstelling").
- Hoog-volume data: contacten, taken, events, transacties, documenten tegelijkertijd.
- Multi-agent workflows waarbij meerdere agents tegelijk lezen én schrijven.
- Situaties waarin traceerbaarheid en audit-gereedheid vereist zijn.
- Vragen over feiten met datum, categorie, eigenaar of andere metadata.

**Beperkingen om te onthouden:**
- Geen pre-gebouwde syntheses: elke complexe vraag kost rekentijd.
- Minder geschikt voor diep narratief begrip zonder extra compilatiestap.
- Standaard niet "contradiction-aware" (vereist expliciete query of plugin).

---

## DEEL 2 — JOUW SCANOPDRACHT

### Stap 1: Inventariseer de OpenClaw-omgeving

Scan de volledige OpenClaw-omgeving en documenteer het volgende:

```
INVENTARISATIE CHECKLIST:
[ ] Welke agent-rollen zijn actief in OpenClaw?
[ ] Welke tools en MCP-servers zijn gekoppeld aan elke agent?
[ ] Welke datatypen worden momenteel verwerkt? (teksten, vergadernotities, onderzoeksartikelen, taken, contacten, transacties, etc.)
[ ] Hoe vaak en door hoeveel agents tegelijk wordt data gelezen en/of geschreven?
[ ] Zijn er bestaande instructies over hoe agents data zoeken? Zo ja, documenteer die.
[ ] Welke vragen stellen agents het meest? Zijn dit narratieve/conceptuele vragen of precieze/feitelijke queries?
[ ] Hoe groot is het huidige datavolume in elk systeem (geschat)?
```

### Stap 2: Classificeer bestaande data en zoekopdrachten

Maak voor elke gevonden data-categorie en elk type zoekopdracht een classificatie:

| Data/Vraagtype | Beschrijving | Wiki-Vault of Open Brain? | Reden |
|---|---|---|---|
| [vul in na scan] | | | |

Gebruik de classificatielogica uit Deel 3 hieronder als leidraad.

### Stap 3: Identificeer hiaten en conflicten

Documenteer:
- Gevallen waar data momenteel in het verkeerde systeem zit.
- Gevallen waar agents zoeken in het verkeerde systeem.
- Gevallen waar geen duidelijke routing bestaat en agents "blind" zoeken.
- Gevallen waar meerdere agents tegelijk naar dezelfde wiki-pagina schrijven (risico).

### Stap 4: Stel de nieuwe routeringsregels op

Op basis van de scan en de classificatielogica schrijf je een compleet regelset (zie Deel 3 voor het format). De regels moeten direct inzetbaar zijn als system prompt voor alle agents.

---

## DEEL 3 — CLASSIFICATIELOGICA & REGELFORMAT

### De beslisboom voor agents

Gebruik deze logica bij elke informatiebehoefte:

```
VRAAG 1: Is de gevraagde informatie een FEIT met specifieke metadata
         (datum, bedrag, categorie, eigenaar, status)?
  → JA  → Gebruik OPEN BRAIN MCP
  → NEE → Ga naar vraag 2

VRAAG 2: Heb je een PRECISIEZE QUERY nodig
         (filteren, sorteren, vergelijken, combineren van gestructureerde velden)?
  → JA  → Gebruik OPEN BRAIN MCP
  → NEE → Ga naar vraag 3

VRAAG 3: Zijn er MEERDERE AGENTS die tegelijk schrijven naar hetzelfde kennisgebied?
  → JA  → Gebruik OPEN BRAIN MCP (schrijven), Wiki-Vault alleen voor lezen
  → NEE → Ga naar vraag 4

VRAAG 4: Gaat de vraag over VERBANDEN TUSSEN IDEEËN, narratief begrip,
         conceptuele synthese, of evolutie van inzichten over tijd?
  → JA  → Gebruik KARPATHY WIKI-VAULT
  → NEE → Ga naar vraag 5

VRAAG 5: Is de data HOOG-VOLUME (>1000 items in deze categorie)?
  → JA  → Gebruik OPEN BRAIN MCP
  → NEE → Gebruik KARPATHY WIKI-VAULT voor rijke synthese,
           OPEN BRAIN MCP voor precieze feiten
```

---

### Het regelformat voor de agents

Na je scan schrijf je de regels in het volgende format. Dit is het format dat direct als instructie aan agents meegegeven kan worden:

---

```
## KENNISROUTING REGELS — [OpenClaw Omgeving Naam]
## Gegenereerd door: Scan-agent op [datum]
## Gebaseerd op: Karpathy Wiki vs Open Brain analyse

---

### GEBRUIK DE KARPATHY WIKI-VAULT VOOR:

1. **Conceptuele en thematische vragen**
   Voorbeeld: "Wat is onze huidige visie op [onderwerp]?"
   Voorbeeld: "Hoe heeft ons denken over [thema] zich ontwikkeld?"
   Voorbeeld: "Wat zijn de verbanden tussen [concept A] en [concept B]?"

2. **Synthese van meerdere bronnen**
   Voorbeeld: "Vat de inzichten samen uit alle onderzoeksartikelen over [X]."
   Voorbeeld: "Combineer wat we geleerd hebben uit meetings over [project]."

3. **Narratief begrip en achtergrond**
   Voorbeeld: "Geef context over [onderwerp] voor een nieuw teamlid."
   Voorbeeld: "Wat is de redenering achter beslissing [Y]?"

4. **Evolutie van inzichten**
   Voorbeeld: "Hoe is onze strategie voor [X] veranderd over de afgelopen maanden?"

5. **Kruisverwijzingen en tegenstrijdigheden in onderzoek**
   Voorbeeld: "Welke bronnen spreken elkaar tegen over [thema]?"

**WAARSCHUWING VOOR AGENTS:**
- Vertrouw wiki-inhoud als startpunt, niet als absolute waarheid.
- Controleer kritieke feiten altijd via Open Brain MCP op traceerbaarheid.
- Schrijf NOOIT rechtstreeks naar de Wiki-Vault als andere agents tegelijk actief zijn.
- De Wiki-Vault wordt gegenereerd vanuit Open Brain — bewerk nooit de wiki direct.

---

### GEBRUIK DE OPEN BRAIN MCP SERVER VOOR:

1. **Precieze feitenopvragingen met filters**
   Voorbeeld: "Geef alle vergadernotities van Q1 waarin 'prijs' werd besproken."
   Voorbeeld: "Toon de drie meest recente updates over concurrent [X]."
   Voorbeeld: "Vind alle actiepunten toegewezen aan [naam] in de laatste twee weken."

2. **Operationele data met metadata**
   Voorbeeld: "Wat is de status van taak [Y]?"
   Voorbeeld: "Welke contactpersonen zijn gelinkt aan project [Z]?"
   Voorbeeld: "Toon alle transacties boven €10.000 uit het afgelopen kwartaal."

3. **Schrijven van nieuwe informatie**
   Nieuwe feiten, vergadernotities, onderzoeksbevindingen, taken, contacten →
   ALTIJD eerst naar Open Brain MCP. De wiki wordt hieruit gegenereerd.

4. **Multi-agent en parallelle toegang**
   Als meerdere agents tegelijk actief zijn → gebruik uitsluitend Open Brain MCP
   voor schrijfoperaties.

5. **Audit en traceerbaarheid**
   Wanneer de bron van een feit belangrijk is → Open Brain MCP,
   want elk feit heeft tijdstempel en bronverwijzing.

6. **Hoog-volume queries**
   Bij >500 items in een categorie → altijd Open Brain MCP.

**ONTHOUD:**
- Open Brain is de ENIGE bron van waarheid.
- De wiki is een gegenereerde VIEW op Open Brain, geen aparte bron.
- Tegenstrijdigheden in Open Brain zijn bewust bewaard — vraag expliciet
  naar tegenstrijdigheden als dat relevant is.

---

### HYBRIDE GEVALLEN — gebruik BEIDE systemen:

Situatie: Je wilt zowel diep begrip ALS precieze feiten.
Aanpak:
  Stap 1 → Raadpleeg Open Brain MCP voor de ruwe feiten en data.
  Stap 2 → Raadpleeg Wiki-Vault voor de synthese en context.
  Stap 3 → Combineer beide in je antwoord.
  Stap 4 → Bij conflict: Open Brain MCP heeft altijd voorrang.

Situatie: Je doet onderzoek en wilt inzichten opslaan.
Aanpak:
  Stap 1 → Sla ruwe bevindingen op in Open Brain MCP (getagd, gecategoriseerd).
  Stap 2 → Laat de compilatie-agent de Wiki-Vault updaten op schema.
  Stap 3 → Bewerk de wiki NOOIT handmatig.
```

---

## DEEL 4 — OUTPUTFORMAAT VAN DE SCAN

Na het voltooien van de scan lever je de volgende documenten op:

### Document 1: Scanrapport
```
Titel: OpenClaw Kennissysteem Scanrapport
Inhoud:
- Gevonden agent-rollen en hun huidige data-routing
- Geïnventariseerde datatypen per systeem
- Geïdentificeerde hiaten en conflicten
- Volumeschattingen per categorie
- Aanbevelingen voor datamigratie (indien nodig)
```

### Document 2: Routeringsregels (direct inzetbaar)
```
Titel: OpenClaw Agent Routeringsregels v1.0
Inhoud:
- Ingevuld regelformat uit Deel 3
- Specifiek gemaakt voor de gevonden datatypen in DEZE omgeving
- Voorbeeldvragen per categorie die passen bij de werkelijke use cases
- Lijst van categorieën met hun toegewezen systeem
```

### Document 3: Implementatieplan
```
Titel: Implementatieplan Kennisrouting
Inhoud:
- Welke system prompts moeten worden bijgewerkt?
- Welke agents krijgen welke regels?
- Is er data die gemigreerd moet worden?
- Wanneer en hoe wordt de Wiki-Vault compilatie-agent ingesteld?
- Hoe wordt naleving gemonitord?
```

---

## DEEL 5 — KERNPRINCIPES DIE ALTIJD GELDEN

Ongeacht de uitkomst van de scan zijn deze principes absoluut:

1. **Open Brain is de enige bron van waarheid.** De Wiki-Vault is altijd een afgeleide.

2. **Nieuwe informatie gaat altijd eerst naar Open Brain MCP.** Nooit direct naar de wiki.

3. **De Wiki-Vault wordt nooit handmatig bewerkt.** Alleen de compilatie-agent schrijft naar de wiki, op basis van Open Brain data.

4. **Bij conflicterende informatie wint Open Brain altijd.** De wiki kan verouderd zijn; de database niet.

5. **Meerdere agents schrijven altijd naar Open Brain, nooit gelijktijdig naar de wiki.**

6. **Traceerbaarheid vereist Open Brain.** Als je de bron van een feit moet kunnen verantwoorden, gebruik je Open Brain.

7. **Narratief begrip vereist de Wiki-Vault.** Als je snel context of synthese nodig hebt zonder opnieuw alles te hercalculeren, gebruik je de wiki.

---

## START HIER

Begin de scan nu. Voer Deel 2 stap voor stap uit. Documenteer alles wat je vindt. Lever aan het einde de drie documenten op uit Deel 4.

Onthoud: het doel is niet alleen beschrijven hoe het nu werkt — het doel is concrete, direct inzetbare regels opstellen zodat beide systemen operationeel zijn en elkaar aanvullen.
