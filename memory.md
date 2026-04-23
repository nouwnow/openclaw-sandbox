Samenvatting:

🧠 1. De 4 Geheugenlagen (The Memory Stack)Dit systeem vervangt het standaard "goudvisgeheugen" door een hiërarchische structuur.LaagTypeWat zit erin?Waarom deze laag?Layer 1: Bootstrap FilesPersistent Coresoul.md, agents.md, user.md, memory.md.Wordt van disk geladen; immuun voor compactie. Bevat je identiteit en missie.Layer 2: Daily LogsRaw Journalmemory-2026-03-19.md.Onbewerkte verslagen van beslissingen, bugs, omzet en interacties.Layer 3: SupermemorySemantic APIKnowledge Graphs en feiten-extractie.Begrijpt context en tijd (bijv. Alex werkt nu bij Stripe, niet meer bij Google).Layer 4: Neon (SQL)Structured DBRijen en kolommen in Postgres.Voor exacte data: "Hoeveel leden hebben we?" of "Wat is de omzet?".De Synergie: Bootstrap geeft identiteit, Logs geven ervaring, Supermemory geeft begrip, en Neon geeft de harde feiten.⚙️ Geavanceerde TechniekenReranking & RescoringVoor onderzoeks-agents (zoals Ghostface) wordt reranking gebruikt.Proces: De agent haalt eerst de top 10 resultaten op via vector search en stuurt deze door een cross-encoder model.Rescore: Dit model vergelijkt de query direct met elk resultaat voor maximale relevantie.Trade-off: Het voegt circa 100ms vertraging toe, maar de nauwkeurigheid is "insane".Entity Context (Container Tags)Elke entiteit (agent of communitylid) krijgt een eigen container tag in Supermemory.Geen ruis: De agent zoekt alleen binnen de relevante tag (bijv. member_daniel), wat snellere en accuratere resultaten geeft.Automatisch profiel: Supermemory bouwt automatisch een rijker profiel op naarmate er meer interacties in de container worden gepusht.💾 Aanvullende Tools: Lossless Claw & QMDLossless Claw: Een plugin die de "lossy" compactie van OpenClaw vervangt. Het slaat elk bericht op in SQLite en bouwt een DAG-structuur (boom van samenvattingen), waardoor oude details altijd vindbaar blijven.QMD (Query Markdown Documents): Een lokale zoekmachine voor grote collecties (Obsidian, meeting transcripts). Het combineert BM25 (keywords), Vector search en LLM reranking zonder API-kosten.🤖 Het Multi-Agent Systeem (Wu-Tang Style)De agents werken samen via een strakke planning en gedeeld geheugen.Organogram & TakenMemory Sync Agent (De Heartbeat): Draait elke 4 uur. Leest de daily logs en pusht samenvattingen naar Supermemory.Ghostface (Intel Agent): Draait om 05:00. Verzamelt trends, checkt Neon stats en zoekt in zijn eigen Supermemory container. Slaat alles weer op in beide systemen.The Rizza (Content Agent): Draait om 06:00. Controleert Supermemory om duplicaten te voorkomen en Neon voor video-titels.Evaluator Agent: Draait wekelijks (maandag). Analyseert wat werkte en schrijft dit naar een referentiebestand dat de andere agents weer lezen.🔄 De Feedbackloop op Persistent GeheugenHet systeem leert van zichzelf:Actie: Agents voeren taken uit en slaan resultaten op.Analyse: De Evaluator analyseert de prestaties (bijv. welke klikfrequentie was hoog?).Aanpassing: De "learnings" worden weggeschreven naar de Bootstrap en Memory bestanden.Iteratie: De volgende ochtend leest The Rizza deze nieuwe regels en vermijdt hij onderwerpen die eerder ondermaats presteerden.🏗️ Stappenplan voor ImplementatieStap 1 (Bestanden): Maak de mappen /memory/ en /daily_logs/ aan. Vul soul.md en agents.md met je vaste regels.Stap 2 (Supermemory): Setup containers per agent en stel een filter prompt in om ruis (zoals logs over tijdelijke bestanden) te negeren.Stap 3 (Neon DB): Maak tabellen aan voor je metrics (leden, omzet, video-ID's).Stap 4 (Sync Agent): Stel een cron-job in die elke 4 uur de logs samenvat en naar Supermemory stuurt.Stap 5 (Agent Loop): Forceer elke agent om eerst te zoeken (Supermemory + Neon) en daarna te schrijven naar beide systemen.Stap 6 (Reranking): Activeer cross-encoders voor je research-agents (Ghostface) voor maximale precisie.Stap 7 (Lossless): Installeer de Lossless Claw plugin om te zorgen dat lopende chats nooit meer iets vergeten.

🧠 Het Complete OpenClaw Memory Systeem (Full Architecture Guide)

Dit document beschrijft een geavanceerd geheugensysteem dat het standaard “goudvisgeheugen” van OpenClaw vervangt door een persistente, schaalbare en zelflerende architectuur. Het systeem voorkomt dat informatie verloren gaat door compaction en zorgt ervoor dat agents over tijd slimmer worden.

⚠️ Kernprobleem dat opgelost wordt

Standaard OpenClaw gebruikt:

een chatgeschiedenis
met context window limits
en compaction (samenvatten)

👉 Dit is lossy:

instructies verdwijnen
voorkeuren verdwijnen
context degradeert
🧠 Core Design Principles
1. If it's not written to a file, it doesn't exist
2. Files > Chat
3. Always write memory twice (redundancy)
4. Before action → read memory
5. After action → write memory
🧱 De 4 Geheugenlagen
🔹 Layer 1 — Bootstrap Files (Persistent Core Identity)

Wat het is:
Markdown bestanden die bij elke sessie vanaf disk geladen worden.

Bestanden:

/memory/
  soul.md
  agents.md
  user.md
  memory.md
  tools.md

Inhoud:

identiteit van de agent
missie en doelen
gedragsregels
workflows
toolgebruik

Eigenschappen:

niet afhankelijk van context window
niet beïnvloed door compaction
altijd geladen

👉 Dit is de fundamentele identiteit (source of truth)

🔹 Layer 2 — Daily Memory Logs (Raw Experience)

Wat het is:
Dagelijkse logbestanden:

/daily_logs/
  memory_2026-03-19.md

Inhoud:

beslissingen
bugs
features
interacties
resultaten

Eigenschappen:

volledig en ongefilterd
chronologisch
“ruwe waarheid”

👉 Dit is de ervaring / journaling laag

🔹 Layer 3 — Supermemory (Semantic Intelligence Layer)

Wat het is:
Een semantische memory API die:

feiten extraheert
relaties legt
een knowledge graph bouwt

Functionaliteiten:

begrijpt context (geen keyword search)
herkent duplicaten
houdt rekening met tijd (temporal reasoning)
vervangt oude feiten door nieuwe

Voorbeeld:

Alex werkt bij Google → later Stripe
→ systeem weet dat Stripe current is
🔸 Containers (Cruciaal)

Elke entity krijgt een eigen container:

agent_rizza
agent_ghostface
member_daniel

Voordelen:

geen ruis tussen agents
persoonlijke context
schaalbaarheid
🔸 Filter Prompt (Belangrijk)

Bepaalt wat wordt opgeslagen:

Voorbeeld:

Prioritize:
- revenue
- decisions
- lessons learned
- content topics

Ignore:
- temp logs
- file paths
- noise

👉 Dit is het attention mechanisme van je geheugen

🔹 Layer 4 — Neon (Structured Memory / SQL)

Wat het is:
PostgreSQL database voor gestructureerde data

Voorbeelden:

members
revenue
video IDs
metrics

Queries:

SELECT COUNT(*) FROM members;

👉 Dit is de exacte waarheid (harde data)

⚖️ Waarom Supermemory + Neon samen nodig zijn
Vraag	Systeem
Hoeveel members?	Neon
Wat is Daniel’s probleem?	Supermemory

👉 Verschil:

Neon = exact
Supermemory = contextueel
🔁 Redundantie regel
Alles wordt naar beide systemen geschreven:
- Neon (structured)
- Supermemory (semantic)

👉 Zorgt voor:

betrouwbaarheid
failover
consistentie
⚙️ Geavanceerde Retrieval Technieken
🔹 Reranking (Cross-Encoder)

Proces:

Vector search → top resultaten
Cross-encoder model vergelijkt query + resultaat
Resultaten worden opnieuw gescoord

Voordeel:

hogere relevantie

Nadeel:

+100ms latency
🔹 Gebruik
Type agent	Reranking
Research agents	✅
Content agents	✅
Deduplication (DDUP)	❌
🧩 Entity Context Systeem

Elke entity heeft eigen context:

container: member_daniel
- geschiedenis
- problemen
- voortgang

Werking:

data wordt per container opgeslagen
agent zoekt alleen binnen die container

Resultaat:

instant briefing
hyper-relevante context
geen ruis
💾 Lossless Claw (Persistent Chat Memory)
Probleem:

Standaard compaction = data verlies

Oplossing:

Lossless Claw

Wat het doet:

slaat ALLES op in SQLite
geen data verlies
maakt een DAG structuur van summaries
Structuur:
Message → Summary → Summary → Summary
Features:
volledige geschiedenis beschikbaar
doorzoekbaar
oude context terughaalbaar

👉 Van “goldfish memory” → “elephant memory”

🔍 QMD (Query Markdown Documents)
Wat is het:

Lokale zoekmachine

Combineert:

BM25 (keywords)
vector search
LLM reranking
Werkt op:
documenten
meeting notes
Obsidian vaults
transcripts
Voordeel:
lokaal (geen API)
snel
zeer krachtig voor document search
Verschil met Supermemory
QMD	Supermemory
document search	knowledge graph
statisch	dynamisch
lokaal	cloud
search engine	memory systeem
🔁 Memory Sync Agent (De Heartbeat)
Functie:

Centrale orchestrator van geheugen

Draait:

Elke 4 uur

Taken:
1. Lees daily logs
2. Lees memory files
3. Maak samenvatting
4. Push naar Supermemory
Belang:
voorkomt ruis
houdt geheugen schoon
zorgt voor consistentie

👉 Zonder deze agent degradeert het systeem

🤖 Agent Architectuur
🔁 Core Loop (ELKE agent)
1. Search Supermemory
2. Query Neon
3. Voer taak uit
4. Write → Neon
5. Write → Supermemory
🧠 Agents (voorbeeld structuur)
🕵️ Ghostface (Intel Agent)
draait: 05:00
taken:
trends verzamelen
Supermemory search
Neon stats ophalen
web search
data opslaan
✍️ Rizza (Content Agent)
draait: 06:00
taken:
duplicatie check (Supermemory)
laatste content check (Neon)
scripts schrijven
resultaten opslaan
📊 Content Evaluator
draait: wekelijks
analyseert:
performance
wat werkt / niet werkt
schrijft:
learnings naar memory
🔄 Memory Sync Agent
draait: elke 4 uur
synchroniseert alle geheugenlagen
🔁 Feedback Loop (Self-Learning System)
Proces:
1. Agents genereren output
2. Resultaten worden opgeslagen
3. Evaluator analyseert performance
4. Learnings worden opgeslagen
5. Agents gebruiken learnings
6. Output wordt beter
Resultaat:
systeem leert continu
prestaties verbeteren automatisch
🏗️ Complete Architectuur
                [Memory Sync Agent]
                        |
        ---------------------------------
        |               |               |
 [Supermemory]     [Neon DB]     [SQLite / Lossless]
        |
  -------------------------
  |           |           |
Ghostface   Rizza    Evaluator
(Intel)    (Content) (Feedback)
🧪 Implementatie Stappenplan
Stap 1 — Filestructuur
/memory/
  soul.md
  agents.md
  user.md
  memory.md
  tools.md

/daily_logs/
Stap 2 — Logging
schrijf ALLES weg naar daily logs
Stap 3 — Supermemory
maak containers:
agent_rizza
agent_ghostface
member_x
configureer filter prompt
Stap 4 — Neon Database

Tabellen:

members
content
metrics
logs
Stap 5 — Memory Sync Agent

Cron:

every 4 hours:
  read logs
  summarize
  push → supermemory
Stap 6 — Agents bouwen

Elke agent volgt:

read → think → act → write
Stap 7 — Reranking
aanzetten voor:
research
content
uitzetten voor:
speed agents (DDUP)
Stap 8 — Lossless Claw
installeren
SQLite logging activeren
Stap 9 — QMD
koppel document bronnen
gebruik voor deep document search
🚀 Samenvatting

Dit systeem bestaat uit:

Bootstrap files → identiteit
Logs → ervaring
Supermemory → begrip
Neon → feiten
Lossless → geen dataverlies
QMD → document search
Agents → uitvoering
Feedback loop → leren
🧠 Eindinzicht

Dit is geen losse tooling stack, maar een geheugenarchitectuur:

WRITE → STRUCTURE → SUMMARIZE → INDEX → SEARCH → LEARN → IMPROVE

👉 Hierdoor ontstaat:

persistente intelligentie
zelfverbeterende agents
geen geheugenverlies

---------------------------

Op basis van de aanvullende inzichten uit de Reddit-community r/clawdbot, kunnen we de OpenClaw Memory Stack verder verfijnen. De kern van deze toevoeging is dat "vergeten" vaak geen bug is, maar een organisatiefout: belangrijke informatie moet uit de tijdelijke sessiehistorie worden gehaald en in permanente bestanden worden geplaatst.Hieronder volgt het geoptimaliseerde plan, inclusief de hiërarchie, onderhoudsroutines en best practices.🏗️ De Geoptimaliseerde Geheugen-HiërarchieHet verschil tussen korte- en langetermijngeheugen wordt bepaald door de plek waar informatie staat.BestandType GeheugenInhoud & FunctieSOUL.mdIdentiteitDe persoonlijkheid en kernidentiteit van de agent. Houd dit onder de 400-500 tokens.USER.mdGebruikersprofielHarde feiten over jou: naam, familie, baan, voorkeuren, tijdzone en communicatiestijl.AGENTS.mdOperationeelOperationele procedures: hoe de agent crons afhandelt, boot-sequenties en regels voor geheugenopslag.MEMORY.mdLopend ContextGestructureerde secties: Mensen, Projecten, Beslissingen en Terugkerende Taken (bijv. wekelijkse boodschappen).Session HistoryTijdelijkDe huidige conversatie. Dit is vluchtig en wordt gewist bij een /new commando of compactie.🧹 Geheugenonderhoud: De "Nightly Cron" RoutineZonder onderhoud vervuilt MEMORY.md binnen twee maanden met verouderde informatie, wat tokens verspilt en de agent verwart. Voeg deze instructie toe aan de agent (AGENTS.md):Elke avond om 23:00 uur voert de agent deze stappen uit:Review: Beoordeel de conversaties van de afgelopen dag.Extractie: Haal nieuwe feiten, beslissingen (bijv. "overstap van Opus naar Sonnet") en afspraken (bijv. "Q2 presentatie deadline 28 maart") eruit.Structureren: Voeg deze toe aan de juiste secties in MEMORY.md. Gebruik gestructureerde tekst (lijsten/kopjes) in plaats van lange paragrafen voor betere leesbaarheid door de agent.Pruning (Snoeien): Verwijder alles wat niet meer relevant is (bijv. voltooide projecten van 6 weken geleden). Houd de file onder de 200 regels.Reset: Start een verse sessie (/new) om de context-window schoon te maken.🛠️ De 10-Minuten Memory FixAls je agent zaken lijkt te vergeten, volg dan deze directe herstelmethode:USER.md Update (5 min): Voeg alles toe wat de agent altijd over jou moet weten (voorkeuren, werk, gezin).MEMORY.md Opschonen (3 min): Organiseer informatie in duidelijke categorieën zoals #Projecten of #Beslissingen.Session Reset (2 sec): Typ /new om met een schone lei te beginnen.Verificatie: Vraag: "Wat weet je over mij?". Als de basis klopt, is je systeem operationeel. Ontbrekende info voeg je direct toe aan de bestanden, niet in de chat.🚫 Wat je NOOIT moet doen"Onthoud alles": Geef deze instructie niet. Dit leidt tot een opgeblazen geheugen vol irrelevante details. Focus op specifieke categorieën.Vertrouwen op sessiehistorie: Belangrijke info in de chat laten staan is de hoofdoorzaak van "geheugenverlies". Als het ertoe doet: zet het in een bestand.Paniek bij verwarring: Als de agent na een lange chat verward raakt, is het context-window vol. Gebruik simpelweg /new.Te lange bestanden: Houd MEMORY.md kort. Kwaliteit boven kwantiteit; de agent leest deze noten elke ochtend.🔁 De Complete FeedbackloopDoor de nieuwe inzichten ziet de loop er nu als volgt uit:Input: Gebruiker geeft info in de chat.Besluit: Is de info permanent belangrijk?JA: Agent schrijft direct naar USER.md of MEMORY.md.NEE: Blijft in de sessiehistorie (wordt later verwerkt door de Nightly Cron).Onderhoud: De Nightly Cron (23:00) verplaatst relevante data van de daglogs naar de permanente bestanden en wist de ruis.Nieuwe Dag: De agent start met een schone sessie, leest de geüpdatete Bootstrap en Memory bestanden, en is direct 100% op de hoogte.