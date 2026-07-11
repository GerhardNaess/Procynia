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
