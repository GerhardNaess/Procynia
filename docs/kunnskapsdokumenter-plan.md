# Plan for profesjonell håndtering av kunnskapsdokumenter i Procynia

## 1. Innledning

Kunnskapsdokumenter er et av de viktigste grunnlagene i Procynia. De bygger bro mellom virksomhetens erfaring, dokumentasjon og aktivt tilbudsarbeid, og gir et strukturert fundament for gjenbruk av kravbesvarelser, referanseprosjekter, metodikk, kvalitet, sikkerhet, prosjektinformasjon, saksinformasjon og AI-støttet tilbudsarbeid.

Kunnskapsdokumenter skal derfor ikke behandles som tilfeldige opplastede filer. De skal forvaltes som styrt kunnskap med eierskap, versjonering, godkjenning, kvalitetssikring, sporbarhet og kontrollert AI-bruk. Det er dette som skiller en profesjonell kunnskapsbase fra et vanlig filarkiv.

Målet er å gå fra et dokumentlager til en styrt kunnskapsbase som bidrar direkte til bedre anbudsarbeid, bedre AI-svar og tryggere gjenbruk av kunnskap.

## 2. Bakgrunn og utgangspunkt

Procynia har en teknisk kunnskapsmotor med støtte for opplasting av dokumenter, tekstekstraksjon, sammendrag, chunking, embeddings og kobling mot AI-funksjoner. Grunnstrukturen for Kunnskapsbase ble etablert tidlig, men manglet lenge en tydelig forvaltningsmodell: hvem som eier dokumentene, hva slags innhold de representerer, og hvilke dokumenter AI faktisk har lov til å bruke.

Per juni 2026 er grunnstrukturen på plass. Fase 1 — begrepsmodell, kundestyrte katalogverdier, eierskap og sporbarhet — er fullført. Se §27 for detaljert statusoversikt.

Det som gjenstår er ikke lenger grunnstrukturen. Det er neste lag: AI-policy per dokument, tydelig dokumentstatus og versjonering av dokumentinnhold. Disse er beskrevet som fase 2 i §28.

## 3. Grunnprinsipp

Det må være et tydelig skille mellom fire ulike nivåer:

- Kunnskapsdokument er det logiske dokumentet
- Opplastet fil er én versjon av dokumentet
- Filtype er det tekniske formatet, for eksempel Word, Excel, PDF eller PowerPoint
- Dokumentkategori er hva slags kunnskapsinnhold dokumentet representerer

Eksempel:

**Kunnskapsdokument:**  
`Referanseprosjekt - Kommuneplattform 2024`

**Versjoner:**  
- v1 - lastet opp 2024-03-01
- v2 - lastet opp 2025-01-15
- v3 - lastet opp 2026-06-14, gjeldende og godkjent

Hovedregelen skal være at AI bare bruker gjeldende, godkjent versjon. Historiske versjoner skal beholdes for sporbarhet, men ikke brukes som aktiv kunnskap med mindre det finnes en eksplisitt grunn og en tydelig policy for det.

## 4. Begrepsmodell

Procynia bør bruke en enkel og konsekvent begrepsmodell for kunnskapsdokumenter:

- Tilhørighet: hvem dokumentet hører til
- Tema: hva dokumentet handler om
- Dokumentkategori: hva slags kunnskapsinnhold dokumentet representerer
- Filtype: det tekniske filformatet
- Sak/anbudssak: den konkrete bid-saken i Procynia, normalt basert på en lagret kunngjøring
- Oppdragsgiver: den offentlige eller private innkjøperen som har publisert anskaffelsen
- Procynia-kunde: selskapet som bruker Procynia og eier dataene i løsningen

Eksempler:

- Tilhørighet: Selskap
  - Tema: Sikkerhet
  - Dokumentkategori: Standard
  - Filtype: PDF

- Tilhørighet: Sak/anbudssak
  - Sak/anbudssak: IT-driftstjenester for Norsk Vann
  - Oppdragsgiver: Norsk Vann BA
  - Tema: Leveransemodell
  - Dokumentkategori: Saksdokument
  - Filtype: Word

- Tilhørighet: Personlig
  - Tema: Erfaring
  - Dokumentkategori: CV
  - Filtype: PDF

Bruk dokumentkategori i brukergrensesnittet, fordi det beskriver innhold og formål uten å forveksles med teknisk filtype som Word, Excel, PDF eller PowerPoint.

## 5. Skillet mellom Procynia-kunde, oppdragsgiver og sak/anbudssak

Det er avgjørende å skille tydelig mellom kunde og sak/anbudssak.

**Procynia-kunde** er selskapet som bruker Procynia og eier dataene i løsningen.  
**Oppdragsgiver** er den offentlige eller private innkjøperen som har publisert anskaffelsen.  
**Sak/anbudssak** er den konkrete bid-saken i Procynia, normalt basert på en lagret kunngjøring.

Eksempel:

- Procynia-kunde: Leverandør AS
- Oppdragsgiver: Norsk Vann BA
- Sak/anbudssak: IT-driftstjenester for Norsk Vann

Saksnavnet kan ofte dannes fra kunngjøringens tittel og oppdragsgiver, men dokumentkoblingen skal alltid være en databasekobling til saken, ikke et manuelt skrevet navn.

Dokumenter knyttet til en sak/anbudssak skal kobles til `saved_notice_id` eller tilsvarende autoritativ saks-ID i Procynia.

## 6. Målbilde

Procynia bør ha en egen arbeidsflate kalt Kunnskapsbase. Dette skal ikke presenteres som «filer», «opplastinger» eller «dokumentlager», men som et strukturert bibliotek for styrt kunnskap.

Et kunnskapsdokument bør ha:

- Tittel
- Tilhørighet
- Tema
- Dokumentkategori
- Eier
- Status
- Gjeldende versjon
- Review-dato
- Synlighet
- AI-bruk
- Kvalitetsstatus
- Filtype på hver opplastede versjon
- Eventuell kobling til sak/anbudssak
- Eventuell kobling til oppdragsgiver via saken
- Eventuell kobling til avdeling/team
- Eventuell personlig eier

Den viktigste verdien ligger i at Procynia vet hvem dokumentet tilhører, hva det handler om, hvilken kategori det har, hvilken versjon som gjelder, hvem som eier det, og hvor AI har lov til å bruke det.

## 7. Tilhørighet

Kunnskapsdokumenter må kunne ha ulik tilhørighet. Tilhørighet skal minst støtte:

- Selskap
- Avdeling/team
- Sak/anbudssak
- Personlig

### Selskapsdokumenter

Dette er felles standarder og godkjent virksomhetskunnskap. Eksempler er sikkerhetsstandard, kvalitetssystem, standard gjennomføringsmodell, miljøpolicy, prismodell, standard SLA og databehandlerbeskrivelse.

### Avdelings- eller teamdokumenter

Dette er kunnskap som gjelder en bestemt del av selskapet. Eksempler er helse-teamets referanser, IT-drift sin leveransemodell, bygg-avdelingens metodikk og konsulentteamets CV-maler.

### Saksdokumenter

Dette er dokumenter knyttet til en konkret sak/anbudssak i Procynia. Eksempler er tidligere tilbud, prosjektplaner, kundespesifikke løsningsbeskrivelser, referanser fra lignende leveranser, avklaringer i pågående anbud, løsningskapitler, kravnotater og dokumenter som bare skal brukes i én bestemt bid-sak.

### Personlige dokumenter

Dette er dokumenter som tilhører én bruker. Eksempler er egne notater, egen CV, personlige erfaringer, utkast til svar og private arbeidsdokumenter.

Personlige dokumenter skal være private som standard.

Saksdokumenter skal bare brukes i den saken/anbudssaken de er knyttet til, med mindre de eksplisitt løftes til avdelings- eller selskapskunnskap gjennom godkjenningsflyt.

## 8. Automatisk kobling til aktiv sak/anbudssak

Når et dokument lastes opp fra en konkret sak/anbudssak i Procynia, skal dokumentet automatisk få tilhørighet «Sak/anbudssak» og knyttes til den aktive saken brukeren står i. Brukeren skal ikke skrive saksnavn manuelt.

Eksempel:

- Brukeren står inne på saken: `IT-driftstjenester for Norsk Vann`
- Oppdragsgiver: `Norsk Vann BA`

Når brukeren laster opp et dokument fra denne saken, lagres dokumentet med:

- Tilhørighet: Sak/anbudssak
- Sak/anbudssak: IT-driftstjenester for Norsk Vann
- Oppdragsgiver: Norsk Vann BA
- `owning_saved_notice_id`: ID-en til saken

Dette gir korrekt filtrering, tilgangsstyring, AI-kontekst og sporbarhet.

Hvis brukeren laster opp et dokument fra den generelle Kunnskapsbase-siden og velger tilhørighet Sak/anbudssak, må saken velges fra databaseoppslag. Det skal ikke være fritekst.

## 9. Tema

Tema beskriver hva dokumentet handler om. Det er mer fleksibelt enn organisatorisk plassering fordi det peker på innholdet, ikke bare hvor dokumentet hører hjemme i organisasjonen.

Eksempler på tema:

- Sikkerhet
- Kvalitet
- Referanseprosjekter
- Metodikk
- Prismodell
- Leveransemodell
- Miljø
- Universell utforming
- Helse
- Bygg
- IT-drift
- Integrasjoner
- Kundecase
- CV-er
- SLA
- Databehandleravtale

Kunden bør kunne bygge sin egen temastruktur.

En mulig hierarkisk temamodell kan være:

- Sikkerhet
  - ISO 27001
  - Beredskap
  - Databehandling
- Referanser
  - Helse
  - Kommune
  - Stat
- Leveranse
  - Prosjektmodell
  - SLA
  - Bemanning

Tema brukes for å finne riktig kunnskap og for å avgrense AI-kontekst.

## 10. Dokumentkategori

Dokumentkategori er hva slags kunnskapsinnhold dokumentet representerer.

Eksempler:

- Standard
- Referanse
- CV
- Metodikk
- Saksdokument
- Arbeidsnotat
- Mal
- Policy
- Prosedyre
- Sjekkliste
- Prismodell
- Leveransebeskrivelse
- Sikkerhetsdokumentasjon
- Kvalitetsdokumentasjon
- Kravnotat
- Løsningskapittel
- Avklaringsnotat

Dokumentkategori er ikke det samme som filtype.

Eksempler:

- Dokumentkategori: Standard
  - Filtype: PDF

- Dokumentkategori: Referanse
  - Filtype: Word

- Dokumentkategori: Prismodell
  - Filtype: Excel

- Dokumentkategori: Saksdokument
  - Filtype: Word

Dokumentkategori er et brukerrettet begrep for innhold og formål, mens filtype er teknisk format.

## 11. Filtype

Filtype er teknisk format og bør lagres separat fra dokumentkategori.

Eksempler på filtype:

- PDF
- Word
- Excel
- PowerPoint
- Tekstfil
- Bilde
- Annet

Teknisk kan dette representeres gjennom:

- `original_extension`
- `file_mime_type`

Filtype kan være nyttig som avansert filter, men bør ikke være hovedbegrepet i brukergrensesnittet. Brukeren skal primært forstå dokumentet gjennom tilhørighet, tema og dokumentkategori.

## 12. Brukerflate og menystruktur

Kunnskapsbase bør ligge i hovedmenyen i Procynia-applikasjonen, ikke bare i admin.

Foreslått hovedmeny:

- Oversikt
- Kunngjøringer
- Arbeidsliste
- Kunnskapsbase
- AI
- Innstillinger

Kunnskapsbase er en daglig arbeidsflate for bid-managere, fagpersoner og bidragsytere, ikke bare systemadministrasjon.

Admin bør bare brukes til konfigurasjon av kunnskapsbasen, for eksempel temaer, dokumentkategorier, review-regler, tilgangsregler og AI-policyer.

## 13. Faner i Kunnskapsbase

En enkel administrasjonsmodell for Kunnskapsbase kan ha disse hovedfanene:

- Bibliotek
- Mine dokumenter
- Saksdokumenter
- Til behandling
- Utløper snart
- Kvalitet
- Arkiv

### Bibliotek

Viser aktive og tilgjengelige kunnskapsdokumenter.

### Mine dokumenter

Viser brukerens egne personlige dokumenter og private arbeidsdokumenter.

### Saksdokumenter

Viser dokumenter som er knyttet til konkrete saker/anbudssaker. Når dokumenter lastes opp fra en åpen sak, kobles de automatisk til den saken.

### Til behandling

Viser nye eller endrede dokumenter som må vurderes eller godkjennes.

### Utløper snart

Viser dokumenter som må revideres innen kort tid.

### Kvalitet

Viser dokumenter med feil, mangler eller svak datakvalitet.

### Arkiv

Viser gamle dokumenter, arkiverte dokumenter og historiske versjoner.

## 14. Filtrering

Kunnskapsbase bør støtte filtrering på:

- Kunde
- Tilhørighet
- Avdeling/team
- Sak/anbudssak
- Oppdragsgiver
- Tema
- Dokumentkategori
- Status
- Eier
- AI-bruk
- Filtype

Filtype bør være et sekundært eller avansert filter.

Sak/anbudssak skal velges fra databaseoppslag, ikke skrives som fritekst.

## 15. Dokumentliste

Dokumentlisten bør vise tydelige kolonner eller badges for:

- Tittel
- Tilhørighet
- Tema
- Dokumentkategori
- Status
- Eier
- Gjeldende versjon
- AI-bruk
- Kvalitetsstatus
- Eventuell sak/anbudssak
- Eventuell oppdragsgiver

Eksempler:

- `Sikkerhetsstandard`
  - Tilhørighet: Selskap
  - Tema: Sikkerhet
  - Dokumentkategori: Standard
  - Status: Godkjent

- `Mine notater om prismodell`
  - Tilhørighet: Personlig
  - Tema: Pris
  - Dokumentkategori: Arbeidsnotat
  - Status: Utkast

- `Løsningskapittel - Norsk Vann`
  - Tilhørighet: Sak/anbudssak
  - Sak/anbudssak: IT-driftstjenester for Norsk Vann
  - Oppdragsgiver: Norsk Vann BA
  - Tema: Leveranse
  - Dokumentkategori: Løsningskapittel
  - Status: Aktiv

- `Referanseprosjekt - Kommuneplattform 2024`
  - Tilhørighet: Selskap
  - Tema: Referanser
  - Dokumentkategori: Referanse
  - Status: Godkjent

## 16. Dokumentvisning

Hvert dokument bør ha en tydelig toppseksjon med:

- Tittel
- Tilhørighet
- Tema
- Dokumentkategori
- Status
- Gjeldende versjon
- Eier
- Neste revisjon
- AI-bruk
- Eventuell sak/anbudssak
- Eventuell oppdragsgiver

Eksempel:

- Tittel: `Løsningskapittel - Norsk Vann`
- Tilhørighet: Sak/anbudssak
- Sak/anbudssak: IT-driftstjenester for Norsk Vann
- Oppdragsgiver: Norsk Vann BA
- Tema: Leveranse
- Dokumentkategori: Løsningskapittel
- Status: Aktiv
- Gjeldende versjon: v1
- Eier: Alsan Senel
- Neste revisjon: Ikke satt
- AI-bruk: Tillatt kun i denne saken

Dokumentvisningen bør også ha seksjoner for:

- Sammendrag
- Filer og versjoner
- Bruk i anbud
- Kravlinjer der dokumentet er brukt
- Historikk
- Kommentarer
- AI-sporbarhet
- Kvalitetsstatus

## 17. Roller og ansvar

En enkel rollemodell kan være:

- Bidragsyter: kan laste opp dokumenter og foreslå endringer
- Dokumenteier: har ansvar for innhold, gyldighet og review
- Godkjenner: kan godkjenne dokumenter for bruk og AI-bruk
- System Owner: konfigurerer dokumentkategorier, temaer, regler og tilgang
- Bid Manager: bruker kunnskapsdokumentene i konkrete anbudsprosesser
- Sakseier eller kommersiell eier: har ansvar for at saksdokumenter i en konkret anbudssak brukes riktig og ikke løftes til bredere kunnskap uten vurdering

Bidragsytere skal ikke måtte forstå embeddings, chunks eller tekniske AI-prosesser.

## 18. Enkel bidragsflyt

### Fra generell Kunnskapsbase

1. Velg tilhørighet
2. Velg tema
3. Velg dokumentkategori
4. Last opp dokument
5. Foreslå eier
6. Send til vurdering

### Fra aktiv sak/anbudssak

1. Brukeren står inne i en sak/anbudssak
2. Brukeren laster opp dokument
3. Procynia setter automatisk tilhørighet til Sak/anbudssak
4. Procynia kobler dokumentet til aktiv `saved_notice_id`
5. Procynia viser saksnavn og oppdragsgiver som låst kontekst
6. Brukeren velger tema og dokumentkategori
7. Brukeren sender eventuelt dokumentet til vurdering

Procynia håndterer deretter tekstekstraksjon, sammendrag, chunking, embeddings og teknisk status automatisk.

## 19. Versjonering

Versjonering skal være streng.

Regel:
Ingen gammel versjon skal slettes når en ny versjon lastes opp.

Når en bruker laster opp ny fil på et eksisterende dokument, skal Procynia spørre:

> Er dette en ny versjon av eksisterende dokument?

Hvis ja, skal systemet opprette ny versjon med:

- Nytt versjonsnummer
- Status: Til vurdering
- AI-bruk: Ikke aktiv ennå

Først når dokumenteier eller godkjenner godkjenner versjonen, skal den bli gjeldende versjon.

Eksempel:

- v3 erstattes som gjeldende
- v4 blir gjeldende
- chunks og embeddings for v4 blir aktive
- chunks og embeddings for v3 blir historiske

Dette hindrer at halvferdige, feilaktige eller ikke-godkjente dokumenter påvirker AI-svar.

Saksdokumenter skal også ha versjonering, men godkjenningsnivået kan være enklere enn for selskapsstandarder dersom dokumentet bare brukes i én sak.

## 20. Deling og løfting av dokumenter

Personlige dokumenter og saksdokumenter må kunne deles eller løftes til et høyere nivå.

Eksempler:

- Personlig dokument kan deles med sak/anbudssak
- Personlig dokument kan foreslås som avdelings- eller selskapsdokument
- Saksdokument kan løftes til referanse eller standard
- Saksdokument kan løftes til avdelingsdokument
- Avdelingsdokument kan foreslås som selskapsdokument

Deling eller løfting skal alltid gå gjennom riktig godkjenningsflyt før dokumentet kan brukes bredere av AI.

Eksempel:

Et godt løsningskapittel fra saken `IT-driftstjenester for Norsk Vann` kan foreslås som selskapsstandard eller referansedokument, men det skal da gå gjennom godkjenning før det brukes bredt av AI.

Løfting skal ikke flytte originaldokumentet ukontrollert. Den bør opprette en styrt kandidat eller en ny versjon som kan godkjennes.

## 21. AI-bruk og policy

Hvert dokument bør ha en tydelig AI-policy.

Mulige valg:

- Ikke bruk i AI
- Bruk i søk
- Bruk i kravsvar
- Bruk i sammendrag
- Bruk bare i bestemte temaer
- Bruk bare i bestemt sak/anbudssak
- Bruk bare for dokumenteier
- Bruk bare for avdeling/team
- Bruk bredt i selskapet

AI må alltid respektere tilhørighet, synlighet, status, gjeldende versjon og AI-policy.

Regler:

- Selskapsdokumenter kan brukes bredt hvis de er godkjent
- Avdelingsdokumenter kan brukes innen riktig avdeling eller team
- Saksdokumenter kan brukes i riktig sak/anbudssak
- Personlige dokumenter kan bare brukes av eieren, med mindre de deles eksplisitt
- AI skal ikke bruke arkiverte, utdaterte, ikke-godkjente eller historiske versjoner som aktiv kunnskap
- AI skal ikke bruke saksdokumenter fra én sak i en annen sak
- AI skal ikke bruke personlige dokumenter i felles svar uten eksplisitt deling

AI-svar bør kunne vise hvilke dokumenter og versjoner svaret bygger på.

Eksempel:

Dette svaret bygger på:

- Referanseprosjekt X, v3
- Sikkerhetsmetodikk, v2
- Kvalitetssystem, v5
- Løsningskapittel - Norsk Vann, v1, bare brukt i denne saken

## 22. AI-filtrering

AI-retrieval må filtrere deterministisk før rangering.

AI skal bare hente fra:

- Riktig `customer_id`
- Aktive chunks
- Gjeldende godkjent versjon
- Riktig tilhørighet
- Riktig tema der relevant
- Riktig `saved_notice_id` ved saksdokumenter
- Riktig bruker ved personlige dokumenter
- Riktig avdeling/team ved avdelingsdokumenter
- Riktig AI-policy
- Riktig synlighet

pgvector-rangering skal skje først etter at tilgang, tilhørighet, status, versjon og AI-policy er filtrert.

Dette hindrer at semantisk søk finner riktig tekst i feil kontekst.

## 23. Kvalitetskontroll

Procynia bør automatisk varsle om:

- Dokument mangler eier
- Dokument mangler review-dato
- Dokument er utløpt
- Dokument utløper snart
- Tekstekstraksjon feilet
- Ingen chunks er opprettet
- Embeddings mangler
- Mulig duplikat finnes
- Dokument er ikke godkjent for AI-bruk
- Personlig dokument er forsøkt brukt uten deling
- Saksdokument er forsøkt brukt utenfor riktig sak/anbudssak
- Dokument mangler tema
- Dokument mangler dokumentkategori
- Dokument mangler gyldig tilhørighet
- Dokument har uavklart versjonsstatus

En enkel kvalitetsscore kan for eksempel være:

- Kvalitet: 82 %
- Mangler: Review-dato og dokumenteier

Kvalitetsscoren skal ikke være komplisert. Den skal være nyttig og handlingsorientert.

## 24. Foreslått datamodell

En konseptuell modell kan være:

### KnowledgeDocument

- id
- customer_id
- title
- document_category_id
- primary_topic_id
- ownership_type
- owner_user_id
- owning_department_id
- owning_saved_notice_id
- status
- current_version_id
- review_due_at
- ai_usage_policy
- visibility_policy
- created_by
- updated_by

`ownership_type` skal støtte:

- `company`
- `department`
- `case`
- `personal`

Brukerrettet visning av `ownership_type`:

- `company` = Selskap
- `department` = Avdeling/team
- `case` = Sak/anbudssak
- `personal` = Personlig

### KnowledgeDocumentVersion

- id
- knowledge_document_id
- version_number
- original_filename
- original_extension
- file_mime_type
- storage_path
- extracted_text
- summary
- extraction_status
- chunking_status
- embedding_status
- approved_by
- approved_at
- created_by
- change_summary

### KnowledgeDocumentRevisionEvent

- id
- knowledge_document_id
- version_id
- event_type
- old_value
- new_value
- created_by
- created_at

### KnowledgeTopic

- id
- customer_id
- name
- parent_id
- sort_order

### KnowledgeDocumentCategory

- id
- customer_id
- name
- description
- sort_order
- is_active

### KnowledgeCollection

- id
- customer_id
- name
- type
- owner_user_id

### KnowledgeDocumentCollection

- knowledge_document_id
- knowledge_collection_id

Denne modellen gir enkel administrasjon og en ryddig teknisk grunnmur.

`owning_saved_notice_id` brukes som autoritativ saksreferanse, fordi Procynia allerede har anbudssaken som den styrende sakskonteksten.

## 25. Statusmodell

En enkel statusmodell kan være:

- Utkast
- Til vurdering
- Godkjent
- Aktiv
- Utløpt
- Arkivert

Ikke alle dokumenter trenger samme godkjenningsnivå.

- Selskapsdokumenter bør ha strengest godkjenning
- Avdelingsdokumenter bør ha moderat godkjenning
- Saksdokumenter kan ha enklere flyt, men skal være avgrenset til riktig sak
- Personlige dokumenter kan være private utkast uten godkjenning, så lenge de bare brukes av eieren

## 26. Admin-konfigurasjon

Admin skal ikke være den primære arbeidsflaten for daglig dokumentarbeid.

Admin bør brukes til konfigurasjon av:

- Temaer
- Dokumentkategorier
- Review-regler
- AI-policyer
- Standard synlighetsregler
- Tilgangsregler
- Varslingsfrister
- Kvalitetsregler

Daglig bruk skjer i Kunnskapsbase i appen, mens System Owner konfigurerer reglene i admin.

## 27. Fase 1: Ferdig grunnstruktur (fullført juni 2026)

### Datamodell

Følgende er etablert:

- `knowledge_items` med `ownership_type` (company/personal/case), `owner_user_id`, `owning_saved_notice_id`, `uploaded_by_user_id`, `document_category_id`, `document_topic_id`
- `knowledge_document_categories` — kundescopede, soft delete, aktiv/inaktiv, alfabetisk sorterbar, case-insensitiv unik navnebegrensning per kunde
- `knowledge_document_topics` — samme struktur
- `knowledge_document_category_topic` — pivotet for lovlige temaer per dokumentkategori
- `knowledge_item_revisions` — append-only revisjonslogg med snapshot ved hver endring
- `knowledge_item_chunks` — AI-chunks med metadata, embeddings og review-status

Legacy-feltene `document_type` og `document_theme_term_id` er beholdt og fungerer som fallback.

### Adminkonfigurasjon

System Owner kan via Kundemiljø → Kunnskapsbase:

- Opprette, redigere og deaktivere dokumentkategorier
- Opprette, redigere og deaktivere temaer
- Knytte lovlige temaer til dokumentkategorier
- Administrere aktive og inaktive verdier

Verdiene er strengt kundescoped. Tilhørighet er fortsatt systemstyrt og ikke konfigurerbar.

### Frontend

- Opplastingsskjema bruker Dokumentkategori og Tema med cascading filtrering
- Tema-valget er begrenset til de temaene som er lovlige for valgt dokumentkategori
- Skifte av dokumentkategori nullstiller tema-valget dersom gjeldende tema ikke lenger er lovlig
- Redigeringsskjema preutfyller og validerer kategori og tema
- Listevisning viser kategori og tema med fallback til legacy-felter
- Detaljside viser kategori og tema med fallback
- Filtre på dokumentkategori og tema er tilgjengelige i listevisningen

### Backend og validering

- Valgt dokumentkategori må tilhøre kundens egne aktive kategorier
- Valgt tema må tilhøre valgt dokumentkategori — validert server-side via pivot-tabellen
- Kryss-kunde-verdier og inaktive verdier avvises
- `null`-clearing av begge felt er støttet
- Gamle dokumenter uten nye katalogverdier fungerer uten feil

### Skille mot Saksdokumenter

- `KnowledgeItem` og `SavedNoticeAiDocument` er to separate modeller og to separate flater
- Retrieval bruker kun `KnowledgeItemChunk` via `knowledge_items` — ingen `SavedNoticeAiDocument` involveres
- Retrieval filtrerer i dag på `customer_id`, `ownership_type = company`, `is_active = true` og `extraction_status = completed`
- `case`- og `personal`-dokumenter eksisterer i modellen, men brukes ikke av retrieval

### Tester

- 68 feature-tester i `KnowledgeBaseControllerTest` dekker payload-eksponering, validering, filtrering, update/clear og edit-preutfylling
- 7 unit-tester i `KnowledgeDocumentCatalogTest` dekker modellrelasjoner, kundescoping, navnebegrensning og soft delete
- 5 feature-tester i `KnowledgeBaseSettingsControllerTest` dekker System Owner-tilgang, CRUD, lovlige temaer per kategori og kryss-kundeblokk

---

## 28. Fase 2: Neste prioriteringer

Fase 2 kan ikke starte før fase 1 er stabilt i produksjon. Elementene nedenfor er listet i anbefalt rekkefølge.

### 28.1 AI-policy per dokument

Et kunnskapsdokument må kunne angi om det kan brukes som AI-grunnlag eller ikke. Dette skal ikke blandes med `is_active` alene — et dokument kan være aktivt uten at AI skal bruke det.

Foreslått retning: et eget felt, for eksempel `ai_usage_enabled` (boolean), eller en enkel enumtype dersom finere kontroll ønskes på sikt.

Retrieval skal ikke endres før dette feltet er på plass og testet. Når feltet er etablert, kan retrieval-laget oppdateres i én kontrollert endring.

### 28.2 Dokumentstatus

Kunnskapsbase trenger en tydeligere status enn bare `is_active`. En enkel statusmodell kan være:

- `draft` — Utkast: under utarbeidelse, ikke aktivt
- `pending_review` — Til vurdering: klar for gjennomgang
- `active` — Aktiv: godkjent og aktivt
- `expired` — Utløpt: har passert revisjonsdato
- `archived` — Arkivert: tas ut av aktiv bruk

Statusmodellen bør vurderes i sammenheng med AI-policy, fordi status og AI-tilgang henger tett sammen. En beslutning her setter også rammene for en eventuell fremtidig godkjenningsflyt.

### 28.3 Revisjon og gyldighet

For at Kunnskapsbase skal opprettholde datakvalitet over tid, trengs:

- `last_reviewed_at` — sist vurdert dato
- `review_due_at` — neste revisjonsdato
- Eventuell `expires_at` — utløpsdato
- Varsling eller filtrering for dokumenter som nærmer seg eller har passert revisjonsdato

Dette hindrer at utdaterte dokumenter stille forblir aktive og påvirker AI-svar.

### 28.4 Versjonering av dokumentinnhold

Det er viktig å skille mellom to ulike konsepter:

**Revisjonshistorikk (finnes nå):**
`knowledge_item_revisions` er en append-only logg over metadataendringer. Den gir sporbarhet for hvem som endret hva og når, men erstatter ikke dokumentinnholdet.

**Dokumentversjonering (fremtidig behov):**
Fullstendig versjonering innebærer at en bruker kan laste opp en ny fil på et eksisterende dokument uten å miste den forrige versjonen. Systemet skal da:

- Oppbevare alle versjoner med egne filer, chunks og embeddings
- Kun aktivere chunks fra gjeldende versjon i AI-retrieval
- Støtte en trygg «last opp ny versjon»-flyt med statusovergang

Dette er en vesentlig utvidelse av datamodellen og bør planlegges som et eget arbeid, ikke som en del av fase 2.

---

## 29. Fase 3 og videre: Utsatt til senere

Følgende er identifisert som verdifullt på sikt, men skal ikke implementeres nå. De innebærer større endringer i datamodell, flyt eller forretningslogikk, og krever at fase 2 er avklart og stabil først.

### Avdeling/team-tilhørighet

`ownership_type` støtter i dag `company`, `personal` og `case`. Avdeling/team-tilhørighet krever `owning_department_id`, utvidet tilgangsstyring og oppdatering av retrieval.

### Hierarkiske temaer

Temaer er i dag flate. En hierarkisk modell med `parent_id` gir mer presis kategorisering, men øker kompleksiteten i admin-UI og retrieval.

### Godkjenningsflyt

En godkjenn-/avvis-flyt krever en godkjenner-rolle, statusoverganger og varsling. Forutsetter at statusmodellen fra fase 2 er på plass.

### Kvalitetsscore

Automatisk vurdering av dokumentkvalitet basert på manglende felt, utløpsdato, ekstraksjonsstatus og lignende.

### Faner i brukerflaten

Bibliotek, Mine dokumenter, Saksdokumenter, Til behandling, Utløper snart, Kvalitet og Arkiv som separate faner i Kunnskapsbase.

### Retrieval-filtrering på dokumentkategori og tema

AI-retrieval kan på sikt filtrere på `document_category_id` og `document_topic_id` for mer presis kontekststyring. Dette bør ikke gjøres før AI-policy og dokumentstatus er avklart og på plass.

### Deling og løfting av dokumenter

Funksjonalitet for å løfte personlige dokumenter eller saksdokumenter til avdelings- eller selskapsnivå, gjennom en kontrollert kandidat-/godkjenningsflyt.

---

## 30. Grunnregler for hva som ikke gjøres nå

Disse reglene gjelder frem til det tas en eksplisitt beslutning om å endre dem.

**Datamodell og legacy**
- `document_type` fjernes ikke nå. Det er beholdt som et required backend-felt og som legacy-fallback i UI.
- `document_theme_term_id` fjernes ikke nå. Det er beholdt som legacy-fallback i UI.
- Gamle data migreres ikke fra legacy-felter til nye katalogfelt nå.

**Tilhørighet**
- Tilhørighet (`ownership_type`) gjøres ikke kundestyrt. Det er et systemstyrt felt.
- Avdeling/team-tilhørighet innføres ikke nå.

**Saksdokumenter**
- `SavedNoticeAiDocument` flyttes ikke til `KnowledgeItem`.
- Saksdokumenter og Kunnskapsbase blandes ikke i UI, retrieval eller datamodell.

**Standardvokabular**
- Standardvokabular og `document_theme_term_id` er ikke primærflatene for Dokumentkategori og Tema.
- Standardvokabular beholdes uendret til en eventuell avviklingsbeslutning tas.

**Retrieval**
- Retrieval, chunking, embeddings og pgvector endres ikke nå.
- Retrieval-filtrering på `document_category_id` og `document_topic_id` innføres ikke før AI-policy og dokumentstatus er på plass.

---

## 31. Konklusjon

Kunnskapsbase har gått fra et åpent dokumentlager til en strukturert kunnskapsbase med eierskap, kategorisering, temastruktur, sporbarhet og kontrollert AI-bruk.

Fase 1 — begrepsmodell, kundestyrte katalogverdier, frontend-integrasjon og validering — er fullført per juni 2026.

Neste prioritet er å etablere tydelig dokumentstatus og AI-policy per dokument. Disse er forutsetninger for mer presis AI-retrieval og for å bygge videre på godkjenningsflyt og kvalitetskontroll i en fremtidig fase.

Saksdokumenter og Kunnskapsbase er to distinkte områder og skal fortsette å være det.

Procynia er nå et sted der Kunnskapsbase ikke lenger er et filarkiv. Det neste steget er å gjøre det til et dokumentsystem der kunden selv styrer hva AI har lov til å bruke.
