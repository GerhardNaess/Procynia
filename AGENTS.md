Procynia strategidoktrine – må følges i all videre utvikling

Procynia skal ikke posisjoneres som en enkel AI-skriveassistent eller et gratis anbudsvarsel-produkt.

Procynia skal posisjoneres som:
“Styringssystemet for virksomheter som lever av anbud.”

Konkurrentbildet:
Cobrief er tydelig posisjonert rundt AI for anbud, gratis anbudsvarsling, rask oppstart og tidsbesparelse. De kommuniserer blant annet KI-løsning for anbud, gratis varsling, raskere arbeid og AI-støttet tilbudsproduksjon. Procynia skal ikke kopiere dette budskapet direkte.

Procynias differensiering:
Procynia skal eie hele den operative tilbudsprosessen:
- fra mulighet
- til vurdering
- til ansvar
- til kravarbeid
- til dokumentasjon
- til kvalitetssikring
- til levert tilbud
- til sporbarhet og gjenbruk

Hovedbudskap:
Anbudsarbeid feiler ikke bare fordi man mangler tekst.
Det feiler fordi ansvar, krav, dokumentasjon, beslutninger, frister og eierskap ligger spredt.

Procynia skal derfor vektlegge:
- kontroll
- ansvar
- struktur
- sporbarhet
- beslutningsstøtte
- kravstyring
- dokumentasjonskontroll
- teamarbeid
- enterprise/sikkerhet/compliance
- trygg bruk av AI som støtte, ikke som erstatning for faglig vurdering

Unngå:
- generisk “spar tid med AI”
- “superkrefter”
- “AI skriver tilbudet for deg”
- for mye teknisk språk
- å fremstå som et enkelt søke-/varslingsverktøy
- å kopiere Cobrief sin posisjon

Bruk heller formuleringer som:
- bedre kontroll på neste anbud
- fra kunngjøring til levert tilbud
- samle krav, ansvar og dokumentasjon
- prioriter riktige muligheter
- reduser risiko før tilbudet sendes
- styr hele tilbudsarbeidet på ett sted
- AI-støtte med struktur, kilder og menneskelig kontroll

Alle nye sider, tekster og funksjoner skal vurderes mot denne posisjoneringen før implementering.

## Enterprise Wiki architecture invariants

For alt arbeid som berører Enterprise Wiki, retrieval, Q&A, requirement research eller wiki-vedlikehold, er disse reglene bindende:

- Enterprise Wiki er Procynias authoritative knowledge layer for Wiki-basert kunnskapsarbeid.
- Arkitekturen er Karpathy-inspirert: kunnskap kompilert til eksplisitte Wiki-sider, konsepter, entities, summaries og relasjoner som en LLM kan lese og navigere i.
- Flyten skal være `map/index → select/read → navigate → evidence → grounded answer`, ikke `query → lexical top-N hard gate → answer`.
- AI skal få et lesbart index/kart over current Wiki-sider og kunne orientere seg i Wiki-en.
- Lexical ranking er bare et prioriterings-, observability- eller tie-breaker-signal; det skal aldri skjule semantisk relevante score-0-sider.
- `Spør Wiki` og anbud/requirement-research skal bruke samme felles Wiki-navigation/retrieval-kjerne.
- Q&A og anbud skiller seg primært etter evidence selection: Q&A gir grounded Wiki-svar, requirement/anbud gir grounded requirement/proposal-svar.
- Wiki-navigation skal bruke current, eligible canonical Wiki-kunnskap. Ikke bypass Enterprise Wiki ved å hente rå dokumentchunks som alternativ knowledge layer.
- Grounding skal ikke svekkes for å få flere svar. Hvis Wikien ikke støtter påstanden, er `insufficient evidence` et gyldig utfall.
- System- eller kundespesifikk evidens skal ikke generaliseres til organisasjons- eller policy-nivå uten eksplisitt støtte.
- Wiki-relasjoner og wikilinks er navigation-signaler, ikke automatisk evidence.
- Navigation og traversal skal være bounded og observerbar. Ingen ukontrollerte recursive agent-loops uten eksplisitt arkitekturbeslutning.
- Ikke innfør stor domenespesifikk synonymtabell som erstatning for semantisk navigation.
- Ikke innfør embeddings/vector database som standardløsning. Slike endringer krever eksplisitt arkitekturbeslutning og dokumentert behov.
- Wiki-ingest og maintenance skal kompilere sammenhenger inn i Wiki-en: canonical concept pages, entity pages, topic/synthesis pages, summaries, cross-references og current state.
- Når en oppgave berører Enterprise Wiki ingest, page generation, patching, knowledge ownership, retrieval, semantic navigation, graph, Q&A, requirement research eller answer generation, skal agenten lese den autoritative Wiki-arkitekturen før implementasjon.
- Hvis en foreslått løsning bryter disse prinsippene, stopp før kodeendring og rapporter konflikten eksplisitt.

Autoritativ arkitekturdetalj: [docs/enterprise-wiki-architecture.md](/Applications/XAMPP/xamppfiles/htdocs/procynia/docs/enterprise-wiki-architecture.md)

# Autonomous programming workflow

For programming tasks in this repository:

- Do not ask for approval for routine implementation decisions.
- Do not present technical options and wait for the user to choose when one reasonable solution can be selected.
- Inspect the codebase, make reasonable engineering decisions, implement the requested change, run the relevant tests, and commit the completed work.
- Do not stop before commit unless the user explicitly says not to commit.
- Report decisions, assumptions, changed files, tests, and commit hash after completion.
- Prefer the smallest coherent solution that satisfies the requested behavior.
- Preserve unrelated local changes.
- Do not perform unrelated refactors.

Only stop and ask the user when the task would require:

- destructive or irreversible operations
- modifying or deleting production data
- deploying to production
- exposing, rotating, or changing secrets or credentials
- incurring material external cost
- resolving a genuine product ambiguity that materially changes intended behavior

Routine code structure, naming, tests, migrations, queue configuration, and implementation details are engineering decisions the agent should make autonomously.

## Test regime

- Use targeted tests for the files and flows that changed.
- Do not rerun the full Wiki suite after routine changes just to inspect aggregate counts.
- Tests that normally take more than 15 minutes require a clear milestone, a broad architecture change, a concrete regression suspicion, or an explicit user request.
- Stop a broad suite once it has answered the current question; do not continue only because the final numbers are interesting.
