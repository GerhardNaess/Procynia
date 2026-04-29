```text
## Anbefalt modell:

## 1. H1/H2 er alltid ytre struktur
   H1 og H2 brukes som faste rammer. AI får aldri lov til å flytte innhold på tvers av H2.
   Det betyr at hvert H2-kapittel behandles isolert.
   Innhold fra ett H2-kapittel skal aldri blandes med innhold fra neste H2-kapittel.

## 2. AI brukes bare når H2 er for stor
   Små H2-kapitler beholdes som én chunk.
   AI kjøres kun hvis H2 overstiger en definert terskel, for eksempel 1 500–2 500 ord.
   Dette reduserer kostnad, kjøretid og kompleksitet.

## 3. AI skal ikke returnere tekst
   AI skal ikke lage ferdige chunks.
   AI skal ikke omskrive, forkorte, forbedre eller gjengi dokumenttekst.
   Originaltekst skal aldri komme fra AI.

   AI skal bare returnere temagrenser mellom eksisterende blokker.
   En temagrense sier hvilke blokker som hører sammen i samme tema.

   Eksempel på AI-svar:

   [
     {
       "topic_title": "Dokumentasjon før overtakelse",
       "start_block_id": 1,
       "end_block_id": 8
     },
     {
       "topic_title": "Vedlikehold av dokumentasjon",
       "start_block_id": 9,
       "end_block_id": 14
     }
   ]

   Dette betyr:
   - Tema 1 består av blokk 1 til 8.
   - Tema 2 består av blokk 9 til 14.

   AI bestemmer altså ikke selve teksten.
   AI peker bare på hvor et tema starter og slutter.
   Backend henter deretter originalteksten fra blokkene og bygger chunkene selv.

   Dette gjør prosessen tryggere fordi AI ikke kan:
   - miste tekst
   - finne på tekst
   - forkorte tekst
   - endre formuleringer
   - slå sammen tekst på tvers av feil struktur
   - lage chunks som ikke finnes i originaldokumentet

## 4. Avsnitt nummereres før AI
   Backend splitter først H2-kapitlet i blokker.
   En blokk er minste trygge dokumentdel:
   én heading, én paragraf, én punktliste, én tabell eller ett bilde med bildetekst.

   Hver blokk får en stabil ID:

   block_id: 1
   type: heading
   text: Dokumentasjonskrav

   block_id: 2
   type: paragraph
   text: Før applikasjonen overtas til drift...

   block_id: 3
   type: list
   text:
   - applikasjonens logiske og fysiske arkitektur
   - maskinvare, operativsystemer og plattformkomponenter

   AI får denne blokklisten, ikke hele dokumentet som fri tekst.
   AI skal bare vurdere hvilke blokk-ID-er som hører sammen tematisk.
   Da blir input mindre, output mindre og resultatet enklere å validere.

## 5. Chunker bygges etterpå i backend
   Backend lager chunkene basert på AI-forslagene.
   Backend bruker start_block_id og end_block_id til å hente originaltekst fra blokkene.
   Hvis AI returnerer ugyldige grenser, forkastes forslaget.
   Da bruker backend en deterministisk fallback, for eksempel avsnittsbasert split innenfor samme H2.

## 6. Bruk størrelsesgrenser etter temadeling
   Hver tematisk chunk må fortsatt kontrolleres mot soft max og hard max.
   Hvis en tematisk chunk fortsatt er for stor, deles den videre deterministisk.
   Splittingen skal skje på blokkgrenser så langt det er mulig.
   Backend skal ikke splitte midt i en paragraf, liste eller tabell med mindre blokken i seg selv er ekstremt stor.

