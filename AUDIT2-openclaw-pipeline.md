# OpenClaw Multi-Agent Audit

Gebruik deze vragenlijst om je huidige OpenClaw setup te beoordelen. Vink af wat aanwezig is (✅), markeer wat ontbreekt (❌), of geef een gedeeltelijke status aan (⚠️). Gebruik de resultaten onderaan om een verbeterplan op te stellen.

---

## 1. Orchestrator — coördinatie

De orchestrator is de centrale agent die de workflow aanstuurt en sub-agents activeert. Zonder orchestrator werken agents in isolatie en stopt de pipeline bij de eerste fout.

| # | Controlevraag | Status |
|---|---------------|--------|
| 1.1 | Is er één agent aangewezen als orchestrator die de workflow coördineert? | |
| 1.2 | Weet de orchestrator welke sub-agent op welk moment geactiveerd moet worden? | |
| 1.3 | Heeft de orchestrator toegang tot de huidige status van het workflow-proces? | |
| 1.4 | Is er een duidelijke handoff-logica tussen de orchestrator en de sub-agents? | |

---

## 2. Sub-agents & rolverdeling

Elke sub-agent moet precies één taak hebben. Agents met meerdere verantwoordelijkheden zijn moeilijker te debuggen en minder betrouwbaar.

| # | Controlevraag | Status |
|---|---------------|--------|
| 2.1 | Heeft elke sub-agent precies één specifieke taak (single responsibility)? | |
| 2.2 | Werken sub-agents in isolatie, of is er bewustzijn van elkaars acties? | |
| 2.3 | Is er een duidelijke definitie van wat elke sub-agent wel en niet mag doen? | |

---

## 3. Workflow & pipeline (Lobster)

De workflow-laag zorgt voor deterministische, stap-voor-stap uitvoering met de mogelijkheid om te hervatten na een onderbreking.

| # | Controlevraag | Status |
|---|---------------|--------|
| 3.1 | Gebruik je Lobster (of een gelijkwaardige shell) als workflow-laag voor multi-step tool sequences? | |
| 3.2 | Is de pipeline deterministisch: levert dezelfde input altijd dezelfde stappenvolgorde op? | |
| 3.3 | Ondersteunt de pipeline resumable state — kan een gestopte workflow verder gaan waar hij gebleven was? | |
| 3.4 | Is er monitoring of logging van pipeline-status tijdens uitvoering? | |

---

## 4. Artifacts & completion markers

Completion markers voorkomen dat werk dubbel wordt gedaan en stellen de orchestrator in staat te weten waar de pipeline gebleven was.

| # | Controlevraag | Status |
|---|---------------|--------|
| 4.1 | Schrijft elke sub-agent een completion marker (bijv. een markdown-bestand) na het afronden van een stap? | |
| 4.2 | Leest de orchestrator deze markers vóór het starten van de volgende stap? | |
| 4.3 | Wordt hiermee voorkomen dat dezelfde stap twee keer wordt uitgevoerd? | |

---

## 5. Approval gates

Gates zijn pauzemomenten vóór onomkeerbare acties. Een fout terugdraaien kost altijd meer tijd dan even controleren.

| # | Controlevraag | Status |
|---|---------------|--------|
| 5.1 | Is er een approval gate vóór elke actie met een bijwerking (e-mail versturen, database aanpassen, externe API aanroepen)? | |
| 5.2 | Wordt de 30-seconden-regel gehanteerd: acties die meer dan 30 seconden kosten om terug te draaien, krijgen altijd een gate? | |
| 5.3 | Kan een mens een gate-stap goedkeuren of afwijzen zonder de hele pipeline opnieuw te starten? | |

---

## 6. Memory & continuïteit

Zonder persistent geheugen begint elke run vanaf nul. De agent herhaalt werk, slaat stappen over en maakt dezelfde fouten.

| # | Controlevraag | Status |
|---|---------------|--------|
| 6.1 | Schrijven agents een samenvatting naar persistent geheugen na elke significante actie? | |
| 6.2 | Leest de orchestrator het geheugen aan het begin van elke nieuwe run? | |
| 6.3 | Bevat het geheugen informatie over wat eerder geslaagd en wat mislukt is? | |
| 6.4 | Herhaalt de pipeline werk dat eerder al succesvol afgerond werd? (dit zou niet mogen) | |

---

## Scoreoverzicht

Vul dit in na afronding van de audit.

| Onderdeel | Aanwezig (✅) | Ontbreekt (❌) | Gedeeltelijk (⚠️) |
|-----------|-------------|--------------|-----------------|
| 1. Orchestrator (4 vragen) | | | |
| 2. Sub-agents (3 vragen) | | | |
| 3. Workflow / Lobster (4 vragen) | | | |
| 4. Artifacts (3 vragen) | | | |
| 5. Approval gates (3 vragen) | | | |
| 6. Memory (4 vragen) | | | |
| **Totaal (20 vragen)** | | | |

---

## Verbeterplan (in te vullen na audit)

Gebruik de ontbrekende punten (❌) als input voor je verbeterplan. Prioriteer in deze volgorde:

1. **Orchestrator ontbreekt** → Geen enkele andere verbetering heeft zin zonder coördinatie. Begin hier.
2. **Memory ontbreekt** → De pipeline is niet continuïteitsgericht en herhaalt werk.
3. **Approval gates ontbreken** → Onomkeerbare acties draaien zonder menselijke controle. Hoog risico.
4. **Artifacts ontbreken** → Stappen kunnen dubbel lopen en de pipeline weet niet waar hij is.
5. **Workflow-laag ontbreekt** → Pipeline is niet hervatbaar na een onderbreking.
6. **Rolverdeling onduidelijk** → Sub-agents doen te veel of werken langs elkaar heen.

### Concrete stappen per onderdeel

#### Orchestrator
- [ ] Definieer één agent met alleen coördinatietaken (geen uitvoerende taken)
- [ ] Leg handoff-logica vast: welke sub-agent volgt op welke conditie?
- [ ] Laat de orchestrator de workflow-status lezen vóór elke beslissing

#### Sub-agents
- [ ] Maak een overzicht van alle huidige agent-taken en splits ze op naar één taak per agent
- [ ] Documenteer de verantwoordelijkheidsgrenzen per sub-agent

#### Workflow / Lobster
- [ ] Implementeer Lobster als workflow-shell voor alle multi-step sequenties
- [ ] Zorg dat elke stap een duidelijke in- en uitvoerdefinitie heeft
- [ ] Activeer resumable state in de pipeline-configuratie

#### Artifacts
- [ ] Voeg aan elke sub-agent een schrijfstap toe die een completion marker aanmaakt
- [ ] Laat de orchestrator de markers inlezen als eerste actie van elke stap

#### Approval gates
- [ ] Inventariseer alle acties met bijwerkingen in de huidige pipeline
- [ ] Voeg een gate toe vóór elke actie die de 30-seconden-regel overschrijdt
- [ ] Test of de pipeline hervaatbaar is na een gate-goedkeuring

#### Memory
- [ ] Kies een persistent memory-mechanisme (bijv. bestand, database, vector store)
- [ ] Voeg aan elke sub-agent een schrijfstap toe voor een actie-samenvatting
- [ ] Laat de orchestrator het geheugen raadplegen aan het begin van elke run

---

*Gebaseerd op het WRAM-framework: Workflow · Roles · Artifacts · Rules · Memory*