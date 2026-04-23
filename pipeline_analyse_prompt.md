# PIPELINE ANALYSE PROMPT — VOLLEDIGE AGENT ARCHITECTUUR
## Instructies voor de uitvoerende LLM

Je bent een senior AI-systems architect gespecialiseerd in multi-agent pipelines. Je taak is een **volledige, stap-voor-stap analyse** uitvoeren van de onderstaande agent pipeline. Je analyseert elke dimensie grondig, benoemt concrete bevindingen per onderdeel, en geeft daarna **praktische, gedetailleerde verbeteringen** per analysepunt.

Werk **altijd in volgorde**. Sla geen stap over. Gebruik voor elke stap het opgegeven outputformaat. Als informatie ontbreekt, benoem dat expliciet als bevinding en geef aan wat er aangeleverd moet worden.

---

## TE ANALYSEREN PIPELINE

[**INSTRUCTIE AAN GEBRUIKER: Vul hieronder je volledige pipeline in. Geef per agent: naam, rol, systeem-prompt of taakomschrijving, tools/skills, input, output, memory-configuratie, en hoe hij communiceert met andere agents. Beschrijf ook de orchestrator en eventuele looplogica.**]

```
[PIPELINE BESCHRIJVING HIER INVOEGEN]
```

---

## ANALYSEFASE 1 — WORKFLOW MAPPING

**Doel:** Breng de volledige operationele workflow in kaart voordat enig oordeel wordt geveld over memory of architectuur.

### Stap 1.1 — Identificeer de kerntaak
- Wat is de **werkelijke taak** die deze pipeline automatiseert?
- Is deze taak enkelvoudig of meervoudig? Beschrijf alle deeltaken.
- Is er een duidelijk begin- en eindpunt gedefinieerd?

**Outputformaat:**
```
KERNTAAK: [beschrijving]
DEELTAKEN: [lijst]
BEGINPUNT: [beschrijving input die de pipeline triggert]
EINDPUNT: [beschrijving finale output of eindconditie]
BEOORDELING WORKFLOW-DUIDELIJKHEID: [Helder / Gedeeltelijk helder / Vaag] + uitleg
```

### Stap 1.2 — Stabiliteit van de workflow
- Is de workflow nog in ontwikkeling of stabiel?
- Zijn er stappen die vaak veranderen of onduidelijk zijn gedefinieerd?
- **Advies uit transcriptie:** "If the workflow is still changing, your memory is going to collect garbage. Finalize the workflow first, then start persisting useful outputs."

**Outputformaat:**
```
WORKFLOW-STABILITEIT: [Stabiel / Gedeeltelijk stabiel / Instabiel]
VERANDERLIJKE STAPPEN: [lijst of 'geen']
RISICO VOOR MEMORY: [beschrijf concreet welke memory-data vervuild raakt als workflow verandert]
AANBEVELING: [wat moet worden vastgesteld voordat memory verder wordt opgebouwd]
```

### Stap 1.3 — Deterministische vs. AI-gestuurde stappen
Analyseer elke stap in de pipeline en classificeer:
- **Deterministisch** = vaste logica, API-call, routing, conditionele check, goedkeuringsgate
- **LLM-taak** = oordeel, classificatie, generatie, redenering
- **Gate** = menselijke goedkeuring of validatiestap vóór side-effect

**Outputformaat (herhaal per stap):**
```
STAP [N]: [naam/beschrijving]
TYPE: [Deterministisch / LLM-taak / Gate / Gemengd]
SIDE-EFFECT: [Ja (wat?) / Nee]
IS SIDE-EFFECT GATED: [Ja / Nee / Niet van toepassing]
BEVINDING: [concreet oordeel over of dit correct is ingericht]
```

**Eindbeoordeling stap 1.3:**
```
TOTAAL DETERMINISTISCHE STAPPEN: [n]
TOTAAL LLM-STAPPEN: [n]
TOTAAL GATES: [n]
ONBESCHERMDE SIDE-EFFECTS: [lijst of 'geen']
PATROON-BEOORDELING: [Is AI-oordeel voldoende ingeperkt? Ja / Nee / Gedeeltelijk]
```

---

## ANALYSEFASE 2 — AGENT ROLLEN EN VERANTWOORDELIJKHEDEN

**Doel:** Beoordeel of elke agent een scherp gedefinieerde, enkelvoudige rol heeft.

### Stap 2.1 — De Orchestrator
- Wat is de rol van de orchestrator?
- Doet de orchestrator zelf werk, of routeert hij alleen?
- **Advies uit transcriptie:** "The orchestrator routes those tasks, passes artifacts and never does the work itself."

**Outputformaat:**
```
ORCHESTRATOR NAAM: [naam]
ROL ZOALS BESCHREVEN: [samenvatting]
DOET ZELF WERK: [Ja (wat?) / Nee]
ROUTEERT CORRECT: [Ja / Nee / Gedeeltelijk]
BEVINDING: [concreet oordeel]
VERBETERING: [wat moet de orchestrator anders doen of laten]
```

### Stap 2.2 — Sub-agents analyse (herhaal per sub-agent)
- **Advies uit transcriptie:** "A well-designed sub-agent should not rely on memory to perform its task. The job should be so clear and specific that the sub-agent can execute from its system prompt alone."

**Outputformaat (herhaal per sub-agent):**
```
SUB-AGENT NAAM: [naam]
ÉÉN-ZIN TAAKOMSCHRIJVING: [schrijf deze zelf als hij ontbreekt, of oordeel over de bestaande]
INPUT: [concreet beschreven of vaag?]
OUTPUT: [concreet beschreven of vaag?]
HEEFT AGENT EEN EIGEN SYSTEEM-PROMPT: [Ja / Nee / Onduidelijk]
VERTROUWT AGENT OP MEMORY VOOR TAAKDEFINITIE: [Ja (probleem!) / Nee (correct)]
OVERLAPT MET ANDERE AGENT: [Ja (met wie?) / Nee]
BEVINDING: [concreet oordeel of rol helder en enkelvoudig is]
VERBETERING: [herschrijf taakomschrijving als die vaag is, of beschrijf wat er moet veranderen]
```

### Stap 2.3 — Zelfstandige agents met eigen context
- Welke agents hebben een eigen context-scope, eigen memory en eigen taken los van de orchestrator?
- Is hun scope schoon afgebakend van andere agents?
- **Advies uit transcriptie:** "Each one lives in its own workspace folder, each one has its own system prompt, its own tools, its own memory scope."

**Outputformaat (herhaal per zelfstandige agent):**
```
AGENT NAAM: [naam]
EIGEN CONTEXT-SCOPE: [beschreven of niet]
EIGEN MEMORY: [Ja / Nee / Gedeeld]
EIGEN TOOLS/SKILLS: [lijst]
CONTAMINATIERISICO: [kan context van andere agents binnensijpelen? Hoe?]
BEVINDING: [oordeel over scope-zuiverheid]
VERBETERING: [concrete stappen om scope te isoleren]
```

### Stap 2.4 — Rollencheck: overlapping en dubbelzinnigheid
- Kunnen alle agents een éénzin-taakomschrijving krijgen?
- Zijn er agents waarvan de taken overlappen of elkaar tegenspreken?
- **Advies uit transcriptie:** "If you can't write a one-sentence job description for each agent, the roles aren't clear enough."

**Outputformaat:**
```
AGENTS ZONDER HELDERE ÉÉN-ZIN ROL: [lijst of 'geen']
OVERLAPPENDE AGENTPAREN: [lijst of 'geen']
TEGENSTRIJDIGE TAKEN: [beschrijving of 'geen']
OVERALL ROL-DUIDELIJKHEID: [Helder / Gedeeltelijk / Problematisch]
AANBEVELINGEN PER AGENT: [concrete herformulering of splitsing van rollen]
```

---

## ANALYSEFASE 3 — ARTIFACTS EN HANDOFFS

**Doel:** Beoordeel de kwaliteit van wat agents aan elkaar doorgeven.

### Stap 3.1 — Inventariseer alle handoffs
- Wat geeft elke agent door aan de volgende?
- Is dit een gestructureerd artifact (JSON, rapport, taaklist, spec) of ruwe conversatietekst?
- **Advies uit transcriptie:** "Are your agents handing off structured artifacts or just raw conversation text?"

**Outputformaat (herhaal per handoff):**
```
VAN: [agent A] → NAAR: [agent B]
WAT WORDT DOORGEGEVEN: [beschrijving]
FORMAAT: [Gestructureerd (JSON/schema/rapport) / Semi-gestructureerd / Ruwe tekst / Onbekend]
IS FORMAAT VOLDOENDE SPECIFIEK: [Ja / Nee]
BEVINDING: [oordeel over handoff-kwaliteit]
VERBETERING: [beschrijf het ideale artifact-formaat voor deze handoff, inclusief veldnamen als relevant]
```

### Stap 3.2 — Session-handoff kwaliteit
- Wanneer een sessie eindigt en een nieuwe begint, wat wordt er doorgegeven?
- Is er een mechanisme voor gestructureerde session-overdracht?
- **Advies uit transcriptie:** "Most people who say my agent has no memory are actually dealing with a short-term memory problem. The session ended, the context reset."

**Outputformaat:**
```
SESSION-HANDOFF MECHANISME: [beschrijving of 'afwezig']
WAT GAAT VERLOREN BIJ SESSIE-EINDE: [lijst]
IS DIT EEN WORKFLOW-PROBLEEM OF MEMORY-PROBLEEM: [Workflow / Memory / Beide]
BEVINDING: [concreet oordeel]
VERBETERING: [beschrijf exact welke artifacts bij sessie-einde moeten worden opgeslagen en in welk formaat, zodat de volgende sessie ze direct kan inladen]
```

### Stap 3.3 — Artifact-volledigheid check
- Heeft elk artifact dat door de pipeline stroomt alle informatie die de ontvangende agent nodig heeft?
- Moet een ontvangende agent memory raadplegen om een artifact te begrijpen?
- **Advies uit transcriptie:** "A well-designed sub-agent should not rely on memory to perform its task."

**Outputformaat:**
```
ARTIFACTS DIE ONVOLLEDIG ZIJN: [lijst met per artifact wat ontbreekt]
AGENTS DIE MEMORY RAADPLEGEN OM ARTIFACT TE BEGRIJPEN: [lijst of 'geen']
BEVINDING: [overall oordeel]
VERBETERING: [voor elk onvolledig artifact: beschrijf exact welke velden moeten worden toegevoegd]
```

---

## ANALYSEFASE 4 — MEMORY ARCHITECTUUR

**Doel:** Beoordeel de huidige memory-setup tegen alle principes uit de transcriptie.

### Stap 4.1 — Korte-termijn vs. lange-termijn memory
- Identificeer voor elke agent welk type memory wordt gebruikt.
- **Advies uit transcriptie:** "Fixing short-term is mostly a workflow problem. Fixing long-term memory is a storage and retrieval problem. These are different problems with different solutions."

**Outputformaat (herhaal per agent):**
```
AGENT: [naam]
KORTE-TERMIJN MEMORY: [aanwezig / afwezig / onduidelijk] + beschrijving
LANGE-TERMIJN MEMORY: [aanwezig / afwezig / onduidelijk] + beschrijving
WAT WORDT OPGESLAGEN: [lijst]
WAT ZOU OPGESLAGEN MOETEN WORDEN MAAR NIET IS: [lijst]
VERWARRING KORTE/LANGE TERMIJN: [Ja (beschrijf) / Nee]
BEVINDING: [oordeel]
VERBETERING: [concrete stappen per memory-type]
```

### Stap 4.2 — Memory scope analyse
- Is memory gedeeld tussen agents of per agent gescopet?
- **Advies uit transcriptie:** "Scope memory to roles always. When memory is scoped to a single role, it stays clean. Clean memory retrieves reliably."

**Outputformaat:**
```
GEDEELDE MEMORY POOLS: [lijst met beschrijving welke agents deze delen]
PER-AGENT GESCOPETE MEMORY: [lijst]
CONTAMINATIERISICO'S: [beschrijf per gedeelde pool welke ongerelateerde context erin kan terechtkomen]
OVERALL SCOPE-BEOORDELING: [Schoon gescopet / Gedeeltelijk / Gecentraliseerd (risico!)]
VERBETERING: [beschrijf per agent de ideale memory scope, wat er in hoort en wat er buiten moet blijven]
```

### Stap 4.3 — Memory kwaliteit en signaal-ruisverhouding
- Wat wordt er opgeslagen in memory? Is het bruikbare signaaldata of ruis?
- **Advies uit transcriptie:** "If your memory is messy, bloated or full of low signal notes, you're not giving the agent clean context, you're giving it noise. And noise makes agents inconsistent."

**Outputformaat:**
```
WAT STAAT ER IN MEMORY (per agent): [beschrijving van huidige inhoud]
HOGE-SIGNAAL DATA: [lijst van wat wél waardevol is]
LAGE-SIGNAAL DATA / RUIS: [lijst van wat geen toegevoegde waarde heeft]
VEROUDERDE ENTRIES: [aanwezig / afwezig / onbekend]
OVERALL SIGNAAL-RUISVERHOUDING: [Goed / Matig / Slecht]
VERBETERING: [concreet: wat verwijderen, wat herstructureren, welk formaat gebruiken voor opslag]
```

### Stap 4.4 — Retrieval design
- Hoe wordt memory opgehaald? Op basis van wat?
- **Advies uit transcriptie:** "Memory is only as good as retrieval. Better retrieval beats bigger context every time. Design for retrieval from the start."

**Outputformaat:**
```
RETRIEVAL MECHANISME: [keyword / semantisch / regel-gebaseerd / onbekend / geen]
WANNEER WORDT MEMORY OPGEHAALD: [bij elke taak / conditioneel / handmatig / onduidelijk]
IS RETRIEVAL ONTWORPEN OF TOT STAND GEKOMEN BY ACCIDENT: [Ontworpen / Toevallig]
LANGE CONTEXT RISICO: [beschrijf of te veel context tegelijk wordt ingeladen]
BEVINDING: [oordeel over retrieval-kwaliteit]
VERBETERING: [beschrijf een concreet retrieval-schema: wanneer wordt wat opgehaald, op basis van welke trigger, met welke maximale context-omvang]
```

### Stap 4.5 — Memory als multiplier vs. memory als afleidingsmanoeuvre
- Past de huidige memory-inzet bij de aanbevolen progressie?
- **Advies uit transcriptie:** "Memory matters, but it matters after architecture, not before it. Good system prompts → good role separation → good outputs → good orchestration → deterministic pipelines → then better memory."

**Outputformaat:**
```
HUIDIGE PROGRESSIE-FASE VAN DE PIPELINE: [fase omschrijven op basis van bovenstaand model]
IS MEMORY AL INGEZET TERWIJL EERDERE LAGEN NOG NIET STABIEL ZIJN: [Ja (risico!) / Nee]
SPECIFIEKE LAGEN DIE EERST VERBETERD MOETEN WORDEN: [lijst]
BEVINDING: [oordeel of memory een multiplier of een afleidingsmanoeuvre is in de huidige setup]
VERBETERING: [beschrijf de aanbevolen volgorde van verbeteringen voor deze specifieke pipeline]
```

---

## ANALYSEFASE 5 — ORCHESTRATIE VS. MEMORY SCHEIDING

**Doel:** Zorg dat memory en orchestratie niet worden verward of elkaars rol overnemen.

### Stap 5.1 — Taakscheiding orchestratie vs. memory
- **Advies uit transcriptie:** "Memory helps an agent access past context. Orchestration helps a system coordinate work. These are different jobs."

**Outputformaat:**
```
TAKEN DIE DOOR ORCHESTRATIE WORDEN GEDAAN: [lijst]
TAKEN DIE DOOR MEMORY WORDEN GEDAAN: [lijst]
VERWARRING OF OVERLAP TUSSEN DE TWEE: [Ja (beschrijf) / Nee]
BEVINDING: [oordeel]
VERBETERING: [beschrijf exact wat naar orchestratie verplaatst moet worden en wat naar memory]
```

### Stap 5.2 — Gedeelde memory pool risico
- Gebruikt het systeem een centrale gedeelde memory pool?
- **Advies uit transcriptie:** "Once everything is shared with everyone, context quality drops fast. The agent pulls in information that has nothing to do with its current task and outputs get inconsistent."

**Outputformaat:**
```
CENTRALE GEDEELDE MEMORY POOL AANWEZIG: [Ja / Nee]
WELKE AGENTS DELEN MEMORY: [lijst]
CONTEXT-VERVUILING RISICO: [Laag / Matig / Hoog] + uitleg
BEVINDING: [oordeel]
VERBETERING: [beschrijf per agent hoe memory gesplitst en gescopet moet worden]
```

---

## ANALYSEFASE 6 — LOOP LOGICA EN EXECUTION CONTINUITY

**Doel:** Beoordeel of loop-mechanismen correct zijn ingericht en niet worden verward met memory.

### Stap 6.1 — Loop vs. memory onderscheid
- **Advies uit transcriptie:** "A loop helps an agent keep progressing across a long-running task, recover from interruptions, and continue through a checklist without starting over. That's execution. Memory is about what the system brings forward from prior work. That's context."

**Outputformaat:**
```
LOOP-MECHANISMEN AANWEZIG: [Ja / Nee / Onduidelijk]
BESCHRIJVING LOOP-LOGICA: [wat triggert de loop, wat zijn de exit-condities]
WORDEN LOOPS VERWARD MET MEMORY: [Ja (beschrijf) / Nee]
CHECKPOINTING AANWEZIG: [Ja / Nee — kan agent hervatten na onderbreking?]
BEVINDING: [oordeel]
VERBETERING: [concrete beschrijving van checkpoint-mechanisme als dat ontbreekt, en hoe loop en memory van elkaar gescheiden moeten worden]
```

### Stap 6.2 — Uitvoerbaarheid bij onderbreking
- Wat gebeurt er als een agent halverwege een taak stopt?
- Is er een mechanisme om te hervatten zonder van voren af aan te beginnen?

**Outputformaat:**
```
SCENARIO: AGENT STOPT HALVERWEGE TAAK
WAT GAAT ER VERLOREN: [lijst]
HERVATMECHANISME: [aanwezig / afwezig]
BEVINDING: [oordeel]
VERBETERING: [beschrijf exact hoe een checkpoint-structuur eruit moet zien voor deze pipeline]
```

---

## ANALYSEFASE 7 — REGELS, STANDAARDEN EN SYSTEEM-PROMPTS

**Doel:** Beoordeel of elke agent heldere, actuele regels en standaarden heeft die zijn gedrag sturen.

### Stap 7.1 — Systeem-prompt kwaliteit per agent
- **Advies uit transcriptie:** "Good system prompts → good role separation → good outputs → good orchestration → deterministic pipelines → then better memory."

**Outputformaat (herhaal per agent):**
```
AGENT: [naam]
SYSTEEM-PROMPT AANWEZIG: [Ja / Nee]
BESCHRIJFT SYSTEEM-PROMPT: ROL [Ja/Nee] | INPUT [Ja/Nee] | OUTPUT [Ja/Nee] | REGELS [Ja/Nee] | STANDAARDEN [Ja/Nee]
KWALITEITSOORDEEL: [Sterk / Matig / Zwak / Afwezig]
BEVINDING: [concreet wat ontbreekt of vaag is]
VERBETERING: [herschrijf of verbeter de systeem-prompt op basis van de bevindingen — geef een concreet voorbeeld]
```

### Stap 7.2 — Regelconsistentie over agents heen
- Spreken de regels van verschillende agents elkaar tegen?
- Zijn er gaps waar geen agent verantwoordelijk voor is?

**Outputformaat:**
```
CONFLICTERENDE REGELS: [lijst of 'geen']
REGELGATEN (geen agent verantwoordelijk): [lijst of 'geen']
BEVINDING: [oordeel]
VERBETERING: [beschrijf welke overkoepelende regels in de orchestrator moeten leven en welke per agent]
```

---

## ANALYSEFASE 8 — VEELGEMAAKTE FOUTEN CHECK

**Doel:** Toets de pipeline expliciet aan de vier kritieke fouten uit de transcriptie.

### Fout 1 — Memory toegevoegd voor workflow stabiel is
- **Advies:** "If the workflow is still changing, your memory is going to collect garbage."

```
IS DEZE FOUT AANWEZIG: [Ja / Nee / Risico]
BEWIJS: [concreet voorbeeld uit de pipeline]
IMPACT: [beschrijf wat er mis gaat of mis kan gaan]
CORRECTIE: [beschrijf stap voor stap wat eerst gestabiliseerd moet worden]
```

### Fout 2 — Gecentraliseerde memory voor alles
- **Advies:** "One shared memory pool that every agent reads from sounds efficient, but it's not."

```
IS DEZE FOUT AANWEZIG: [Ja / Nee / Risico]
BEWIJS: [concreet voorbeeld]
IMPACT: [beschrijf de inconsistentie die dit veroorzaakt]
CORRECTIE: [beschrijf hoe memory opgesplitst moet worden per rol]
```

### Fout 3 — Overlappende agent-verantwoordelijkheden
- **Advies:** "Two agents with similar roles start duplicating work or worse, contradicting each other."

```
IS DEZE FOUT AANWEZIG: [Ja / Nee / Risico]
OVERLAPPENDE AGENTEN: [paren]
BEWIJS: [concreet voorbeeld van duplicatie of tegenspraak]
IMPACT: [beschrijf het effect op output-kwaliteit]
CORRECTIE: [beschrijf hoe de rollen opnieuw afgebakend moeten worden]
```

### Fout 4 — Retrieval als iemand anders' probleem
- **Advies:** "Long context degrades performance. Better retrieval beats bigger context every time."

```
IS DEZE FOUT AANWEZIG: [Ja / Nee / Risico]
BEWIJS: [beschrijf of retrieval bewust is ontworpen of niet]
IMPACT: [beschrijf de inconsistentie en degradatie die dit veroorzaakt]
CORRECTIE: [beschrijf een concreet retrieval-ontwerp voor deze pipeline]
```

---

## ANALYSEFASE 9 — RECURRING ROLE-BASED WORK BEOORDELING

**Doel:** Beoordeel of de pipeline terugkerende, rolgebaseerde taken heeft waar memory echt waardevol is.

**Advies uit transcriptie:** "Memory shines when you have recurring role-based work. Scope the memory to the role, recurring tasks, clear inputs, predictable outputs — that's the unlock."

**Outputformaat:**
```
TERUGKERENDE TAKEN GEÏDENTIFICEERD: [lijst met omschrijving]
PER TAAK:
  - ROL DUIDELIJK AFGEBAKEND: [Ja / Nee]
  - INPUT VOORSPELBAAR: [Ja / Nee]
  - OUTPUT VOORSPELBAAR: [Ja / Nee]
  - MEMORY ZOU HIER WAARDE TOEVOEGEN: [Ja / Nee / Misschien] + uitleg
  - WAT SPECIFIEK IN MEMORY OPSLAAN: [lijst van concrete data-items]
```

---

## SYNTHESEFASE — PRIORITEITENLIJST EN VERBETERPLAN

**Doel:** Lever een concreet, geordend verbeterplan op.

### Stap S.1 — Kritieke blokkades (moet eerst opgelost worden)
Lijst alle problemen die de pipeline fundamenteel blokkeren of de memory-architectuur vergiftigen. Geef per probleem:

```
BLOKKADE [N]:
BESCHRIJVING: [wat is het probleem]
AANGETASTE COMPONENT: [welke agent / stap / handoff]
IMPACT ALS NIET OPGELOST: [concreet gevolg]
OPLOSSING: [stap-voor-stap actieplan]
PRIORITEIT: [Kritiek / Hoog / Medium / Laag]
```

### Stap S.2 — Aanbevolen uitvoeringsvolgorde
Geef de exacte volgorde voor verbetering, gebaseerd op het progressiemodel uit de transcriptie:

```
FASE 1 — WORKFLOW STABILISEREN
  Acties: [lijst]

FASE 2 — ROLLEN SCHERP STELLEN
  Acties: [lijst]

FASE 3 — ARTIFACTS EN HANDOFFS STRUCTUREREN
  Acties: [lijst]

FASE 4 — ORCHESTRATIE VERSTERKEN
  Acties: [lijst]

FASE 5 — PIPELINE DETERMINISTISCH MAKEN
  Acties: [lijst]

FASE 6 — MEMORY OPTIMALISEREN
  Acties: [lijst — pas als alle bovenstaande fases stabiel zijn]
```

### Stap S.3 — Memory verbeterplan (alleen uitvoeren na S.2)
Voor elk aspect van memory: geef een concrete implementatie-instructie.

```
MEMORY SCOPE PER AGENT:
  [agent naam]: bewaar [wat] | verwijder [wat] | nooit opslaan [wat]

RETRIEVAL SCHEMA:
  [agent naam]: haal op [wanneer] | op basis van [trigger/query] | maximaal [n tokens / n items]

HANDOFF ARTIFACTS:
  [van → naar]: gebruik [formaat] met velden [lijst]

SESSION-OVERDRACHT:
  Bij sessie-einde: sla op [wat] in [waar] in formaat [beschrijving]
  Bij sessie-start: laad in [wat] op basis van [context/trigger]

GEHEUGEN ONDERHOUD:
  Verwijder entries ouder dan [n] of met lage relevantie [criteria]
  Review memory op [frequentie]
```

### Stap S.4 — Samenvatting scorecard

```
DIMENSIE                        | HUIDIGE SCORE (1-5) | DOELSCORE | TOP VERBETERING
-------------------------------|---------------------|-----------|----------------
Workflow-helderheid             |                     |           |
Rol-duidelijkheid               |                     |           |
Artifact-kwaliteit              |                     |           |
Orchestratie-kwaliteit          |                     |           |
Determinisme pipeline           |                     |           |
Memory scope                    |                     |           |
Memory signaal/ruis             |                     |           |
Retrieval design                |                     |           |
Loop / execution continuity     |                     |           |
Systeem-prompt kwaliteit        |                     |           |
TOTAAL                          |                     |           |
```

---

## AFSLUITENDE INSTRUCTIES VOOR DE UITVOERENDE LLM

1. **Werk elke fase volledig af** voordat je naar de volgende gaat.
2. **Wees concreet.** Vage observaties zijn niet acceptabel. Elke bevinding moet een specifiek voorbeeld benoemen.
3. **Wees eerlijk over ontbrekende informatie.** Als je iets niet kunt beoordelen omdat de pipeline-beschrijving onvolledig is, benoem dat expliciet en geef aan wat je nodig hebt.
4. **Geef verbeteringen in uitvoerbare stappen.** Niet "verbeter de systeem-prompt" maar "voeg aan de systeem-prompt van agent X de volgende sectie toe: [concreet voorbeeld]".
5. **Respecteer de prioriteitsvolgorde.** Stel geen memory-verbeteringen voor als de workflow, rollen of handoffs nog niet stabiel zijn. Benoem dit expliciet als dat het geval is.
6. **De scorecard in S.4 is verplicht.** Vul elke cel in op basis van je analyse.
