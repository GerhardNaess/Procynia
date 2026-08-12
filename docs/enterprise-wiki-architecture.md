# Enterprise Wiki Architecture

Authoritative architecture source of truth for Enterprise Wiki in Procynia.

## A. Purpose

Enterprise Wiki er en LLM-lesbar, eksplisitt kunnskapsmodell.

Informasjon skal:

- kompileres fra kildedokumenter
- organiseres i canonical pages
- forbindes med relasjoner og wikilinks
- holdes current
- brukes som kunnskapsgrunnlag for Q&A og anbud

Enterprise Wiki er ikke en ren dokumentdump, ikke en claim-liste og ikke et alternativt navn for tradisjonell RAG.

## B. Core Architecture

### Ingest and maintenance

```text
source documents
→ extract/understand
→ decide ownership
→ create/amend/patch canonical Wiki
→ cross-link/synthesize
→ verify/lint/QA
→ current Wiki
```

### Query and research

```text
question/requirement
→ AI-readable Wiki index
→ semantic reading/navigation plan
→ read selected current pages
→ bounded Wiki traversal
→ semantic evidence selection
→ grounded answer
```

## C. Why Lexical Top-N Is Not the Architecture

Den konkrete læringen fra Q&A-regresjonen er enkel: en side kan være semantisk relevant selv om lexical score er 0 når brukeren bruker annen terminologi enn Wiki-en.

Derfor betyr ikke `lexical score = 0` at siden er semantisk irrelevant.

Lexical ranking kan bidra som signal, men kan ikke være hard gate som bestemmer hva AI-en får lov til å se.

## D. Wiki Index

Navigation-AI-en skal få et kompakt kart over current Wiki-en.

Typiske felt:

- `page_id`
- `title`
- `page_type`
- `slug`
- `scope`
- `short summary`
- `headings`
- bounded relations / wikilinks

Indexen er et navigation map, ikke evidence i seg selv.

## E. Navigation

Skill mellom:

- seed selection
- page reading
- graph traversal
- evidence selection

En valgt seed er ikke automatisk evidence.

En graph neighbour er ikke automatisk evidence.

Final evidence må vurderes semantisk og scoped.

## F. Shared Retrieval

Samme navigation/retrieval-kjerne skal brukes av:

- `Spør Wiki`
- requirement research
- Wiki-basert requirement/proposal answer generation

Ikke bygg parallelle retrieval-motorer uten tung arkitekturbegrunnelse.

## G. Grounding

Grounding-reglene er:

- current Wiki only
- customer isolation
- no unsupported generalisation
- insufficient evidence er legitimt
- conflicting evidence skal kunne håndteres
- general modellkunnskap skal ikke bli faktakilde i grounded answer

## H. Knowledge Compilation

Karpathy-premisset gjelder ikke bare query.

Ingest og maintenance skal også gjøre Wiki-en lettere å navigere ved å bygge og vedlikeholde:

- concept pages
- entity pages
- topic/synthesis pages
- summaries
- cross-links
- canonical ownership
- current-state content

Målet er at viktige kunnskapsforbindelser skal være kompilert inn i Wiki-en på forhånd.

## I. Non-Goals and Guardrails

- ikke raw-document RAG som primær kunnskapsmodell
- ikke stor synonymtabell
- ikke domain/customer hardcoding
- ikke embeddings/vector DB uten eksplisitt designbeslutning
- ikke ukontrollert graph traversal
- ikke full-Wiki fulltext i hvert answer-call dersom index/navigation kan brukes
- ikke parallelle Q&A/requirement retrieval engines

## J. Design Checklist

Bruk denne sjekklisten før Wiki-relaterte endringer:

1. Bruker denne endringen Enterprise Wiki som authoritative knowledge layer?
2. Kan AI fortsatt orientere seg i Wiki-en?
3. Innfører vi en ny hard gate som kan skjule semantisk relevant kunnskap?
4. Bruker Q&A og requirement research samme mekanisme der de bør?
5. Er navigation bounded?
6. Er grounding bevart?
7. Er løsningen generisk og domenefri?
8. Kompilerer vi kunnskap inn i Wiki-en når det er riktig, i stedet for å reparere alt ved query-time?
9. Krever endringen eksplisitt arkitekturavgjørelse?
10. Hvis noe bryter invariants: stopp før implementasjon.
