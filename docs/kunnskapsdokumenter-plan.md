# Plan for profesjonell håndtering av kunnskapsdokumenter i Procynia

## 1. Innledning

Kunnskapsdokumenter er et av de viktigste grunnlagene i Procynia. De bygger bro mellom virksomhetens erfaring, dokumentasjon og aktivt tilbudsarbeid, og gir et strukturert fundament for gjenbruk av kravbesvarelser, referanseprosjekter, metodikk, kvalitet, sikkerhet, prosjektinformasjon, saksinformasjon og AI-støttet tilbudsarbeid.

Kunnskapsdokumenter skal derfor ikke behandles som tilfeldige opplastede filer. De skal forvaltes som styrt kunnskap med eierskap, versjonering, godkjenning, kvalitetssikring, sporbarhet og kontrollert AI-bruk. Det er dette som skiller en profesjonell kunnskapsbase fra et vanlig filarkiv.

Målet er å gå fra et dokumentlager til en styrt kunnskapsbase som bidrar direkte til bedre anbudsarbeid, bedre AI-svar og tryggere gjenbruk av kunnskap.

## 2. Bakgrunn og utgangspunkt

Procynia har en teknisk kunnskapsmotor med støtte for opplasting av dokumenter, tekstekstraksjon, sammendrag, chunking, embeddings og kobling mot AI-funksjoner. Grunnstrukturen for Kunnskapsbase ble etablert tidlig, men manglet lenge en tydelig forvaltningsmodell: hvem som eier dokumentene, hva slags innhold de representerer, og hvilke dokumenter AI faktisk har lov til å bruke.

Per juni 2026 er grunnstrukturen på plass. Fase 1 — begrepsmodell, kundestyrte katalogverdier, eierskap og sporbarhet — er fullført. Se §27 for detaljert statusoversikt.

Fase 2.1, 2.2, 2.3, 2.4, 2.5 og 2.6 er fullført. AI-policy per dokument, dokumentstatus, revisjon og gyldighet, versjonering av dokumentinnhold, kontroll og godkjenning av kunnskapsdokumenter, og innsyn i Kunnskapsbase-kilder sendt til AI, inkludert global oversikt i Kunnskapsbase → Bruk i AI, er alle implementert. Disse er beskrevet i §28.

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
- Retrieval filtrerer i dag på `customer_id`, `ownership_type = company`, `is_active = true`, `ai_usage_enabled = true`, `document_status = active` og `extraction_status = completed`
- `case`- og `personal`-dokumenter eksisterer i modellen, men brukes ikke av retrieval

### Tester

- 68 feature-tester i `KnowledgeBaseControllerTest` dekker payload-eksponering, validering, filtrering, update/clear og edit-preutfylling
- 7 unit-tester i `KnowledgeDocumentCatalogTest` dekker modellrelasjoner, kundescoping, navnebegrensning og soft delete
- 5 feature-tester i `KnowledgeBaseSettingsControllerTest` dekker System Owner-tilgang, CRUD, lovlige temaer per kategori og kryss-kundeblokk

### AI-policy per dokument (fullført juni 2026)

`ai_usage_enabled` (boolean, default `true`) er lagt til på `knowledge_items`. Feltet styrer om et kunnskapsdokument kan brukes som AI-grunnlag i retrieval.

- `ai_usage_enabled` erstatter ikke `is_active`. De to feltene er separate:
  - `is_active` styrer om dokumentet er aktivt og synlig i Kunnskapsbase
  - `ai_usage_enabled` styrer om AI har tillatelse til å bruke dokumentet som grunnlag
- Retrieval filtrerer nå på `ai_usage_enabled = true` i tillegg til `is_active = true`
- Feltet er eksponert i listevisningen som en dedikert «AI-bruk»-kolonne (Ja/Nei) separat fra Status-kolonnen
- Opplastings- og redigeringsskjema har et eget felt med label «Kan brukes av AI»
- Detaljsiden viser «Kan brukes av AI: Ja / Nei» i dokumentinfo-kortet
- 3 nye feature-tester dekker store-default, eksplisitt false ved store, og update/payload-eksponering (totalt 71 tester, 800 assertions)

### Dokumentstatus (fullført juni 2026)

`document_status` (string, default `active`) er lagt til på `knowledge_items`. Feltet styrer dokumentets livsløp og er den eneste brukerrettede statusen.

Gyldige verdier:

- `draft` — Utkast
- `pending_review` — Til vurdering
- `active` — Aktiv
- `expired` — Utløpt
- `archived` — Arkivert

- `is_active` er ikke lenger brukerrettet status. Det avledes automatisk: `document_status = active` gir `is_active = true`; alle andre verdier gir `is_active = false`.
- Retrieval filtrerer nå på `document_status = active` i tillegg til `is_active = true` og `ai_usage_enabled = true`.
- Status vises i listevisning og på detaljside med fargekoder.
- Opplastings- og redigeringsskjema har ett statusfelt med label «Status» — det gamle synlige `is_active`-checkboxen er fjernet.
- 5 nye feature-tester dekker store-default, eksplisitt verdi, ugyldig verdi, update/payload-eksponering og skjema-payload (totalt 76 tester, 819 assertions).

### Revisjon og gyldighet (fullført juni 2026)

`last_reviewed_at` og `review_due_at` (nullable datofelt) er lagt til på `knowledge_items`.

- `last_reviewed_at` viser når dokumentet sist ble faglig vurdert.
- `review_due_at` viser når dokumentet bør vurderes på nytt.
- `review_state` beregnes i alle payload-metoder og gir enkel synlighet:
  - `not_set` — ingen revisjonsfrist er satt
  - `ok` — revisjonsdato er mer enn 30 dager frem i tid
  - `due_soon` — revisjonsdato er innen 30 dager
  - `overdue` — revisjonsdato er passert

Revisjonsstatus er en kontroll- og synlighetsmekanisme, ikke automatisk styring:
- `review_due_at` påvirker ikke `document_status` automatisk.
- `review_due_at` påvirker ikke `ai_usage_enabled` automatisk.
- `review_due_at` påvirker ikke `is_active` automatisk.
- Retrieval er ikke endret.

Revisjons-badge (`review_state`) er synlig i listevisningen for dokumenter med revisjonsfrist. Filter for revisjonsstatus er tilgjengelig under «Flere filtre». Opplastings- og redigeringsskjema har datofelter for begge felt. Detaljsiden viser datoene der de er satt.

9 nye feature-tester dekker store/update-lagring, nullstilling, bevaring av eksisterende verdier og alle fire review_state-verdier (totalt 86 tester, 861 assertions).

---

## 28. Fase 2: Neste prioriteringer

Fase 2 kan ikke starte før fase 1 er stabilt i produksjon. Elementene nedenfor er listet i anbefalt rekkefølge.

### 28.1 AI-policy per dokument ✓ Fullført juni 2026

Implementert. `ai_usage_enabled` er lagt til på `knowledge_items` og respekteres av retrieval. Se §27 for fullstendig beskrivelse.

### 28.2 Dokumentstatus ✓ Fullført juni 2026

Implementert. `document_status` er lagt til på `knowledge_items` og respekteres av retrieval. Se §27 for fullstendig beskrivelse.

### 28.3 Revisjon og gyldighet ✓ Fullført juni 2026

Implementert. `last_reviewed_at` og `review_due_at` er lagt til på `knowledge_items` som nullable datofelt. `review_state` beregnes i alle payloads. Se §27 for fullstendig beskrivelse.

### 28.4 Versjonering av dokumentinnhold ✓ Fullført juni 2026

Fullstendig dokumentversjonering er implementert for Kunnskapsbase / `KnowledgeItem`. Det er viktig å skille mellom to konsepter som begge nå er på plass:

**Revisjonshistorikk:**
`knowledge_item_revisions` er en append-only logg over metadataendringer og filbytter. `file_replaced`-revisjoner inkluderer `knowledge_item_version_id` og `knowledge_item_version_no` i snapshot.

**Dokumentversjonering:**
`knowledge_item_versions` er en egen tabell med én rad per filversjon. Hvert `KnowledgeItem` har én `is_current = true`-versjon til enhver tid.

Følgende er nå på plass:

- `knowledge_item_versions` som egen versjonstabell med id, version_no, is_current, original_filename, storage_path, mime_type, file_size_bytes, extracted_text, extraction_status, uploaded_by_user_id og uploaded_at
- Eksisterende filer ble backfyllt til versjon 1 ved migrering
- `knowledge_item_chunks` peker på `knowledge_item_version_id` (nullbar for bakoverkompatibilitet, men alltid satt for nye chunks)
- Retrieval henter bare chunks fra current version via INNER JOIN mot `knowledge_item_versions.is_current = true` — chunks fra gamle versjoner ekskluderes automatisk
- Ny fil på eksisterende `KnowledgeItem` oppretter ny `KnowledgeItemVersion` med `is_current = false`; gammel versjon, gammel filreferanse og gamle chunks beholdes
- Ny versjon aktiveres atomisk kun etter vellykket tekstuttrekk, chunking og embedding — ved mislykket tekstuttrekk beholdes eksisterende versjon som aktiv
- `KnowledgeItem` legacy-felter (original_filename, storage_path, mime_type osv.) oppdateres alltid til å reflektere gjeldende versjon
- `GenerateKnowledgeChunkMetadataForDocument` kan scopes til en spesifikk `knowledge_item_version_id` slik at metadatajobb ikke prosesserer gamle versjoners chunks
- Backend detail payload eksponerer en read-only `versions`-liste sortert nyeste først, med versjonsnummer, is_current, filnavn, filtype, filstørrelse, ekstraksjonsstatus, opplastingsinfo og chunk-antall — uten extracted_text, embeddings eller chunk-innhold
- UI viser dokumentversjoner read-only i historikk-fanen på detaljsiden
- UI lar bruker laste opp ny dokumentversjon fra detaljsiden via eksisterende `replaceFile()`-backend

**Avgrensning i denne fasen:**

- `SavedNoticeAiDocument` er ikke endret — versjonering gjelder kun Kunnskapsbase / `KnowledgeItem`
- Rollback til gammel versjon er ikke bygget
- Sletting av enkeltversjoner er ikke bygget
- Sammenligning av versjoner er ikke bygget
- Extracted_text, chunk-innhold og embeddings vises ikke i vanlig UI
- `document_type` og `document_theme_term_id` er beholdt som legacy-felter
- Standardvokabular er ikke endret
- Retrieval-kriteriene er beholdt, men er nå versjonsbevisste (INNER JOIN mot gjeldende versjon)
- pgvector-oppsettet er ikke endret

### 28.5 Kontroll og godkjenning av kunnskapsdokumenter ✓ Fullført juni 2026

Versjonsbasert kontroll og godkjenning er implementert for Kunnskapsbase / `KnowledgeItem`. Nye dokumentversjoner aktiveres ikke lenger automatisk — de krever eksplisitt godkjenning før de brukes av AI.

**Hva som er levert:**

- `approval_status` er lagt til på `knowledge_item_versions` med verdiene `pending_review`, `approved`, `rejected` og `superseded`
- Tilhørende felt: `approved_at`, `approved_by_user_id`, `rejected_at`, `rejected_by_user_id`, `rejection_reason`, `submitted_for_review_at`, `submitted_for_review_by_user_id`
- Read-only visning av approval-status per versjon er synlig i UI under Historikk → Dokumentversjoner

**Opplasting av ny filversjon (`replaceFile()`):**

- Ny erstatningsversjon opprettes med `approval_status = pending_review`
- Ny versjon får ikke `is_current = true` automatisk
- Gammel current-versjon forblir `is_current = true` og brukes av AI mens ny versjon venter
- Metadatajobb dispatches ikke for pending-versjoner — kun ved godkjenning

**Godkjenning (`approveVersion()`):**

- Autorisert bruker kan godkjenne en pending-versjon
- Godkjent versjon aktiveres og settes til `is_current = true`
- Gammel current-versjon settes til `approval_status = superseded`
- `KnowledgeItem` legacy-felter (original_filename, storage_path, mime_type osv.) oppdateres ved godkjenning
- `GenerateKnowledgeChunkMetadataForDocument` dispatches etter godkjenning
- Revisjonsspor skrives med `change_type = file_replaced`

**Avvisning (`rejectVersion()`):**

- Autorisert bruker kan avvise en pending-versjon med obligatorisk begrunnelse (min. 3 tegn, maks. 2000)
- Avvist versjon settes til `approval_status = rejected` med `rejected_at`, `rejected_by_user_id` og `rejection_reason`
- Avvisning endrer ikke current-versjon — gammel current-versjon forblir aktiv og brukes av AI
- Ingen metadatajobb dispatches ved avvisning
- Revisjonsspor skrives med `change_type = version_rejected`

**Tilgangsstyring:**

- Viewers (`bid_role = viewer`) kan ikke godkjenne eller avvise versjoner
- `approve_url` og `reject_url` settes kun i payload for autoriserte brukere og kun for pending-versjoner
- Backend håndhever autorisering uavhengig av frontend

**UI:**

- Show-siden har en tydelig statusboks over fanene som viser aktiv versjon, AI-status og eventuelle pending-versjoner
- Godkjenn/avvis-handlinger er tilgjengelige under Historikk → Dokumentversjoner
- Avvisning krever begrunnelse via modal med textarea
- Pending, rejected og superseded versjoner fremstår tydelig som ikke aktive

**Retrieval er ikke endret:**

`MetadataCandidateRetrievalService` er uendret. Retrieval henter fortsatt kun chunks fra current version via INNER JOIN mot `knowledge_item_versions.is_current = true`, og filtrerer på `customer_id`, `ownership_type = company`, `is_active = true`, `document_status = active`, `ai_usage_enabled = true` og `extraction_status = completed`. Pending, rejected og superseded versjoner ekskluderes automatisk fordi de ikke har `is_current = true`.

**Skille mot Saksdokumenter:**

`SavedNoticeAiDocument` og Saksdokumenter er ikke berørt. Godkjenningsflyten gjelder utelukkende `KnowledgeItem` og `KnowledgeItemVersion` i Kunnskapsbase. `KnowledgeItem` og `SavedNoticeAiDocument` er separate modeller, separate flater og separate retrieval-stier.

**Tester:**

26 nye feature-tester i `KnowledgeBaseControllerTest` dekker approve-flyt (C1–C8), reject-flyt (D1–D8), retrieval-konsekvenser, payload-eksponering, viewer-sperring og revisjonsspor (totalt 137 tester, 1104 assertions).

### 28.6 Innsyn i Kunnskapsbase-kilder sendt til AI ✓ Fullført juni 2026

Fase 2.6 er fullstendig implementert (2.6B–F). Brukere kan se hvilke Kunnskapsbase-kilder som ble sendt til AI som grunnlag for et krav-svar, og navigere direkte til riktig Kunnskapsbase-dokument fra innsynsvisningen. I tillegg finnes en global oversikt på `Kunnskapsbase → Bruk i AI` som viser hvordan Kunnskapsbase-kilder er sendt til AI som grunnlag på tvers av saker.

**Viktig begrepsavklaring:**
Dette innsynet viser hvilke kilder som ble sendt inn som kontekst ved AI-kallet. Det er ikke en garanti for at alle kildene faktisk ble brukt av modellen internt. Begrepet «sendt til AI som grunnlag» brukes gjennomgående — ikke «faktisk brukt av AI» eller «AI brukte disse kildene».

**2.6B — Versjonssporing på evidence:**

- `knowledge_item_version_id` er lagt til på `saved_notice_ai_evidence` som nullable fremmednøkkel mot `knowledge_item_versions` med `nullOnDelete`
- Retrieval-pathen (`MetadataCandidateRetrievalService`) viderefører `knowledge_item_version_id` og `knowledge_item_version_no` fra chunken via `selectColumns()` og `chunkRowFromModel()`
- `RequirementKnowledgeMatcher::match()` bevarer `knowledge_item_version_id` gjennom matching-trinnet
- `AiController::knowledgeChunksForMatching()` og `syncRequirementEvidence()` viderefører og lagrer versjonspekeren
- Evidence-raden peker stabilt på den dokumentversjonen som lå til grunn da svaret ble generert — selv om dokumentet etterpå oppdateres eller versjonen supersedes

**2.6C — Backend-payload per krav:**

- `aiRequirementPayload()` inkluderer et `knowledge_sources_sent_to_ai`-felt — en array med én rad per evidence-kobling
- Hvert element inkluderer: `evidence_id`, `knowledge_item_id`, `knowledge_item_show_url`, `knowledge_item_version_id`, `knowledge_item_version_no`, `original_filename`, `document_type`, `document_type_label`, `chunk_id`, `chunk_index`, `chunk_type`, `section_title`, `heading_path`, `match_score`, `match_rank`, `match_type`, `is_primary`, `selection_status`, `version_approval_status`, `version_is_current_now`
- Feltet er tom array `[]` når ingen Kunnskapsbase-kilder er tilknyttet kravet — aldri `null`
- Full chunk content, embeddings og raw storage paths er ikke inkludert i payload

**2.6D — Frontend-visning:**

- En kompakt, read-only «Kilder sendt til AI»-seksjon vises nederst i det scrollbare krav-svar-panelet i `Show.jsx`
- Viser per kilde: filnavn, versjonsnummer (`v{n}`), dokumentkategori, utdrag/chunk-indeks, rangering og seksjonstittel
- Kilde med `version_is_current_now = false` markeres med en diskret amber-advarsel «Tidligere dokumentversjon»
- Seksjonen er usynlig dersom ingen Kunnskapsbase-kilder er tilknyttet kravet
- Alle UI-strenger er i18n-nøkler i `lang/no/procynia.php` og `lang/en/procynia.php` under `ai.sources_sent_to_ai_*`

**2.6E — Lenke til Kunnskapsbase-dokument:**

- Filnavnet i «Kilder sendt til AI» er en klikkbar lenke til `KnowledgeItem`-detaljsiden når `knowledge_item_show_url` finnes
- Backend genererer URL med `route('app.ai.knowledge-base.show', ...)` — samme mønster som `KnowledgeBaseController`
- URL er `null` dersom `KnowledgeItem` ikke finnes (edge case) — frontend viser da plain text
- Lenken peker alltid til `KnowledgeItem`-detaljsiden, ikke til en versjonside og ikke til Saksdokumenter
- Lenken er diskret: hover-effekt til violet, ingen handling for godkjenning, redigering eller opplasting

**2.6F — Global oversikt over Kunnskapsbase-kilder sendt til AI:**

- Ny app-side finnes under `Kunnskapsbase → Bruk i AI`
- Siden viser en global oversikt over Kunnskapsbase-kilder sendt til AI som grunnlag, på tvers av saker
- Oversikten bygger på `saved_notice_ai_evidence` og presenterer to hovedaggregater:
  - dokumentaggregat per kunnskapsdokument og versjon
  - chunkaggregat per chunk / kildeutdrag
- Brukeren kan filtrere oversikten på `version_status`, `primary_only`, `date_from` og `date_to`
- Dokumentnavn lenker til Kunnskapsbase-detaljsiden der det er mulig
- Full chunktekst, embeddings og storage path vises ikke
- Dette viser hvilke kilder som ble sendt som grunnlag til AI, ikke hva modellen faktisk brukte internt
- `SavedNoticeAiEvidence` fungerer her som brukslogg for Kunnskapsbase-kilder sendt til AI
- `SavedNoticeAiDocument` brukes ikke av denne oversikten
- Saksdokumenter og Kunnskapsbase er fortsatt adskilt

**Retrieval er ikke endret:**
`MetadataCandidateRetrievalService` og retrieval-logikken er uendret. Det eneste som er tilføyd er at `knowledge_item_version_id` og `knowledge_item_version_no` eksponeres som sporbarhetsinformasjon i select-kolonner og `chunkRowFromModel()` — ikke som filter, men for å videreføre versjonspeker til evidence-raden.

**Prompt er ikke endret:**
Malen for krav-svar-generering (`RequirementAnswerDraftService`) er ikke endret. Innsynet berører ikke hva som sendes til AI — kun sporbarhet og synliggjøring for brukeren etterpå.

**Skille mot Saksdokumenter:**
`SavedNoticeAiDocument` og Saksdokumenter er ikke berørt. `knowledge_sources_sent_to_ai` inneholder utelukkende Kunnskapsbase-evidence (`SavedNoticeAiEvidence` → `KnowledgeItemChunk` → `KnowledgeItemVersion` → `KnowledgeItem`). Saksdokumenter (`SavedNoticeAiDocument`) er ikke involvert og ikke eksponert.

**Tester:**

- 6 nye feature-tester i `AiControllerTest` dekker 2.6B/2.6C: versjonssporing på evidence (2 tester) og payload-eksponering av `knowledge_sources_sent_to_ai` (4 tester) — alle passerer
- 2 nye unit-tester i `MetadataCandidateRetrievalServiceTest` bekrefter at `knowledge_item_version_id` og `knowledge_item_version_no` videreføres fra retrieval-query — begge passerer
- 3 pre-existing failures i metadata ordering-tester er urelatert til dette arbeidet
- `npm run build` kjørt uten feil for 2.6D og 2.6E

**Videreføring — Master-detail-funksjon, sortering og interaksjonskvalitet (commits `bf96e71`, `eed8e03`, `cea1dbd`, `46674fb`):**

Etter den innledende implementeringen ble `Kunnskapsbase → Bruk i AI`-siden videreutviklet med fullt master-detail-grensesnitt, sorterbarhet i begge tabeller og rettinger av kritiske rendering-feil. Dette er en videreføring av 2.6F — ikke en ny fase.

Teknisk URL: `/app/ai/knowledge-base/ai-usage`  
Rutenavn: `app.ai.knowledge-base.ai-usage`

*Master-detail-grensesnitt (commit `bf96e71`):*

- Dokumenttabellen fungerer som masterliste. Én rad er alltid valgt.
- Første dokument velges automatisk ved sidelast (sortert på «Sendt til AI» synkende som standard).
- Valgt rad markeres visuelt med violet bakgrunn og «Valgt»-badge.
- Utdragstabellen fungerer som detaljvisning og viser bare utdrag for valgt dokument.
- Begge tabeller er sorterbare etter alle kolonner. Sortering nullstiller ikke valgt dokument dersom det fortsatt finnes i listen.
- Utdragstabellen filtreres på `knowledge_item_id` fra valgt dokument — samme nøkkel som dokumenttabellen.
- Summary-kort viser antall dokumenter, utdrag og ganger sendt til AI på tvers av alle tilgjengelige rader.

*Radklikk (commit `eed8e03`):*

- Hele dokumentraden er klikkbar — ikke bare «Vis utdrag»-knappen.
- `onClick` på `<tr>` kaller `setSelectedDocumentKey` med stabil `knowledge_item_id` via `getDocumentRowKey(row)`.
- «Vis utdrag»-knappen brukte tidligere hele row-objektet som nøkkel — rettet til `getDocumentRowKey(row)`.
- `<Link>` til Kunnskapsbase-detaljsiden har `e.stopPropagation()` slik at klikk på dokumentnavnet bare navigerer — radvalg utløses ikke.
- «Vis utdrag»-knappen har tilsvarende `e.stopPropagation()` for å forhindre dobbel state-oppdatering.
- Navigasjon til dokumentdetaljer og radvalg er dermed to adskilte handlinger.

*Inertia-komponentnavn (commit `cea1dbd`):*

- Controlleren rendret feilaktig `AI/KnowledgeBase/AiUsage`.
- Riktig komponent er `App/AI/KnowledgeBase/AiUsage`, som tilsvarer `resources/js/Pages/App/AI/KnowledgeBase/AiUsage.jsx`.
- Uten denne rettingen lastet siden ikke i det hele tatt.

*Svarutkast-rendering (commit `46674fb`):*

- Valgt requirement kunne ende med tom lokal draft-state som skjulte backend-payload-feltet `answer_draft.text`.
- Rettingen er i `resources/js/Pages/App/AI/Show.jsx`.
- Requirement med både `answer_draft_text` og `SavedNoticeAiEvidence` viser nå lagret svarutkast korrekt, samtidig som «Kilder sendt til AI» vises i samme panel.
- Diagnosen ble verifisert på kravlabel `1-1.S.1`, `saved_notice_ai_requirements.id = 5974`, `saved_notice_id = 8153`, med 5 evidence-rader og eksisterende svarutkast i DB og payload.

*Avgrensning — ikke endret:*

Retrieval, promptbygging, AI-modellkall, chunking, embeddings, pgvector, `SavedNoticeAiDocument`, Saksdokumenter og datamodellen er ikke endret. AI Usage-siden viser hvilke kilder som ble sendt som kontekst — ikke hva modellen faktisk brukte internt.

*Skille mot Saksdokumenter:*

Kunnskapsbase og Saksdokumenter er fortsatt to adskilte flyter:

- Kunnskapsbase bruker `KnowledgeItem` → `KnowledgeItemVersion` → `KnowledgeItemChunk` → `SavedNoticeAiEvidence`
- Saksdokumenter bruker `SavedNoticeAiDocument`, er knyttet til én konkret `SavedNotice` og ligger i AI-arbeidsflaten for den saken
- AI Usage-oversikten leser fra `saved_notice_ai_evidence` — den leser ikke fra `SavedNoticeAiDocument`
- Oversikten er dobbelt tenant-scopet: via `knowledge_items.customer_id` og via `saved_notices.customer_id`
- Rejected evidence telles ikke

### 28.7 Kontrollert legacy-isolering i Kunnskapsbase ✓ Fullført juni 2026

Fase 2.7 er fullført som en kontrollert legacy-isolering, ikke som fysisk databaseopprydding. Legacy-feltene er ikke fjernet, og kolonner er ikke droppet. I stedet er de autoritative kildene tydeliggjort og lesing er sentralisert slik at nye og gamle felt kan eksistere side om side med klarere ansvarslinjer.

`document_type` er fortsatt den autoritative kilden for dokumentkategori/type, og `document_status` er fortsatt den autoritative kilden for aktiv/inaktiv-status. `KnowledgeItemVersion` er autoritativ kilde for gjeldende filidentitet, filmetadata og ekstraksjonsdata. `KnowledgeItem`s filidentitetsfelt (`original_filename`, `storage_path`, `mime_type`, `file_size_bytes`) er fysisk fjernet (fase 28.9G, juni 2026). Ekstraksjonsfeltene (`extraction_status`, `extraction_error`, `extracted_text`) og `content` beholdes foreløpig som legacy-fallback.

### 28.7L — Kartlegging av gjenværende legacy-speilfelter (2.7L)

Denne kartleggingen oppsummerer de feltene som fortsatt fungerer som legacy-speil eller fallback i Kunnskapsbase. De er fortsatt i bruk i payloads, UI, retrieval, AI-strømmer eller tester, og kan derfor ikke fjernes før alle konsumenter er flyttet helt over til versjons- og metadata-kildene.

**Autoritativ kilde for filidentitet:** `KnowledgeItemVersion` for gjeldende versjon. `KnowledgeItem`s filidentitetsfelt er fysisk fjernet (fase 28.9G, juni 2026).

**Autoritativ kilde for ekstraksjonsstatus:** gjeldende `KnowledgeItemVersion.extraction_status`. `KnowledgeItem.extraction_status` er fortsatt et legacy-speil som brukes som fallback i flere payloads og tester.

| Felt | Autoritativ kilde | Skrives fortsatt? | Leses fortsatt? | Brukt i payload/UI/retrieval/AI/tests? | Risiko ved fjerning | Anbefalt neste steg |
| --- | --- | --- | --- | --- | --- | --- |
| `storage_path` | `KnowledgeItemVersion.storage_path` | ~~Ja~~ | ~~Ja~~ | **Fysisk fjernet juni 2026 (fase 28.9G). Kolonnen eksisterer ikke lenger i `knowledge_items`.** | — | Fullført. Se §28.9. |
| `original_filename` | `KnowledgeItemVersion.original_filename` | ~~Ja~~ | ~~Ja~~ | **Fysisk fjernet juni 2026 (fase 28.9G). Kolonnen eksisterer ikke lenger i `knowledge_items`.** | — | Fullført. Se §28.9. |
| `mime_type` | `KnowledgeItemVersion.mime_type` | ~~Ja~~ | ~~Ja~~ | **Fysisk fjernet juni 2026 (fase 28.9G). Kolonnen eksisterer ikke lenger i `knowledge_items`.** | — | Fullført. Se §28.9. |
| `file_size_bytes` | `KnowledgeItemVersion.file_size_bytes` | ~~Ja~~ | ~~Ja~~ | **Fysisk fjernet juni 2026 (fase 28.9G). Kolonnen eksisterer ikke lenger i `knowledge_items`.** | — | Fullført. Se §28.9. |
| `extraction_status` | Gjeldende `KnowledgeItemVersion.extraction_status`; `KnowledgeItem.extraction_status` er fortsatt fallback | Ja | Ja | Ja: payload, banner-/statuslogikk, retrieval-sjekker og tester | Høy — kan bryte statusvisning og statusbasert filtrering | Behold til status er hentet konsekvent fra current version i alle konsumenter |
| `extraction_error` | Gjeldende `KnowledgeItemVersion.extraction_error`; `KnowledgeItem.extraction_error` som fallback | Ja | Ja | Ja: payload, UI-feilvisning og tester | Middels/høy — feilvisning kan bli tom eller feil | Fase ut når alle feilvisninger leser fra versjonspayload |
| `extracted_text` | `KnowledgeItemVersion.extracted_text` for gjeldende versjon; `KnowledgeItem.extracted_text` som legacy fallback | Ja | Ja | Ja: AI-grunnlag, sammendrag, payload-fallback og tester | Høy — kan påvirke AI-kontekst og fallback-tekster | Behold til alle AI-/oppsummeringskall er versjonsbaserte |
| `content` | Ingen enkelt kilde; brukes som siste fallback i `textForKnowledgeProcessing()` når versjonstekst og `KnowledgeItem.extracted_text` mangler | Ja, ved opprettelse og noen manuelle flyter | Ja | Ja: AI-/tekstgrunnlag og tester; ikke primær UI-kilde | Høy — kan fjerne siste tekstfallback for gamle dokumenter | Flytt all lesing til versjonstekst før denne kan ryddes bort |
| `content_type` | `document_type` | ~~Ja, via modellens synkronisering~~ | ~~Ja~~ | **Fysisk fjernet juni 2026 (fase 2.8A). Kolonnen eksisterer ikke lenger i `knowledge_items`.** | — | Fullført. Se §28.8A. |
| `is_active` | `document_status` | ~~Ja, via modellens synkronisering~~ | ~~Ja~~ | **Fysisk fjernet juni 2026 (fase 2.8A). Kolonnen eksisterer ikke lenger i `knowledge_items`.** | — | Fullført. Se §28.8A. |

**Oppsummert:**

- Filidentitetsfeltene (`original_filename`, `storage_path`, `mime_type`, `file_size_bytes`) er fysisk fjernet fra `knowledge_items` (fase 28.9G, juni 2026). Autoritativ kilde er `knowledge_item_versions`.
- `document_type` og `document_status` er de autoritative styringsfeltene. `content_type` og `is_active` er fysisk fjernet fra `knowledge_items` (fase 2.8A, juni 2026).
- Ekstraksjonsfeltene (`extraction_status`, `extraction_error`, `extracted_text`) beholdes foreløpig som legacy-speil — se fase 28.10 for videre plan.
- `content` er den svakeste delen av kjeden og bør ryddes sist, etter at all tekstbruk er versjonsbasert.
- Ingen gjenværende kolonner skal slettes før alle lesere, payloads og tester er flyttet over til de nye kildene.

### 28.7M — Sentralisert filidentitetslesing (fullført)

Fase 2.7M sentraliserte lesing av filidentitet og filmetadata via `KnowledgeItem`-resolvers uten å endre payload-formatet.

Følgende resolver-metoder finnes nå på `KnowledgeItem`:

- `resolvedStoragePath()`
- `resolvedOriginalFilename()`
- `resolvedMimeType()`
- `resolvedFileSizeBytes()`

Resolverne leser fra gjeldende `currentVersion`. Fallback til legacy-feltene på `KnowledgeItem` ble fjernet i fase 28.9F, og de underliggende legacy-kolonnene ble fysisk droppet i fase 28.9G. Resolverne returnerer `null` dersom gjeldende versjon mangler feltet.

### 28.7N — Sentralisert ekstraksjonsstatuslesing (fullført)

Fase 2.7N sentraliserte lesing av ekstraksjonsstatus, ekstraksjonsfeil og extracted text via `KnowledgeItem`-resolvers uten å endre payload-format eller brukeropplevelse.

Følgende resolver-metoder finnes nå på `KnowledgeItem`:

- `resolvedExtractionStatus()`
- `resolvedExtractionError()`
- `resolvedExtractedText()`

`resolvedExtractionError()` behandler `null` på current version som en meningsbærende verdi og faller derfor ikke tilbake til gammel legacy-feil når gjeldende versjon finnes uten feil. `textForKnowledgeProcessing()` bruker nå `resolvedExtractedText()` og beholder `content` som siste fallback.

Fysisk opprydding for `content_type` og `is_active` ble gjennomført som fase 2.8A (juni 2026). Se §28.8A. Fysisk opprydding for filidentitetsfeltene (`original_filename`, `storage_path`, `mime_type`, `file_size_bytes`) ble gjennomført som fase 28.9 (juni 2026). Se §28.9. Videre opprydding for ekstraksjonsfeltene kan eventuelt vurderes som fase 28.10, med tilsvarende datakontroll og avvikling av resterende fallback-/speilskriving.

### 28.8 Drop-readiness for `content_type` og `is_active` (kontrollert juni 2026)

Fase 2.8F er en readiness-kontroll, ikke en migrasjon. Målet var å avgjøre om `content_type` og `is_active` faktisk var klare for fysisk dropp fra `knowledge_items`.

**Konklusjon på kontrolltidspunktet: ikke klar ennå. Fase 2.8A gjennomførte det fysiske droppet etter at resterende blokkere ble fjernet. Se §28.8A.**

**Status for `content_type`:**

- Ikke klar for fysisk dropp.
- `KnowledgeItem` har fortsatt `content_type` som model-default i `$attributes`, så en ny modellinstans forventer fortsatt feltet som legacy-mirror.
- `KnowledgeBaseController` eksponerer fortsatt `content_type` og `content_type_label` i payloads for liste, detalj og edit.
- `AiController`, `RequirementAnswerDraftService`, `MetadataCandidateRetrievalService`, `KnowledgeChunkMetadataGenerationService` og `KnowledgeChunkBoundaryService` leser fortsatt `document_type ?? content_type` eller sender `content_type` videre som legacy-alias.
- Audit-kommandoen leser fortsatt `content_type` for å rapportere legacy-driftsavvik.
- Tester og testfixtures setter fortsatt `content_type` eksplisitt i legacy-/kompatibilitetsscenarier.

**Status for `is_active`:**

- Ikke klar for fysisk dropp.
- `KnowledgeItem` har fortsatt en `is_active`-cast, og Kunnskapsbase-payloadene eksponerer fortsatt `is_active` som legacy-speil for dokumentstatus.
- `KnowledgeBaseController` bruker fortsatt `is_active` i payloads og enkelte interne oppslag.
- Audit-kommandoen leser fortsatt `is_active` for å rapportere legacy-avvik mot `document_status`.
- Tester og testfixtures setter fortsatt `is_active` eksplisitt i legacy-/kompatibilitetsscenarier.

**Legitime legacy-rester som kan bli stående frem til selve droppet:**

- audit-kommandoen og audit-testene som dokumenterer legacy-drift
- testfixtures som eksplisitt setter `content_type`/`is_active` for å simulere gammelt dataavvik
- dokumentasjonen som beskriver overgang fra legacy-speil til autoritative felter

**Hva som må bort før fysisk kolonnedropp kan være trygg:**

- alle aktive lesere i Kunnskapsbase-/AI-kjeden som fortsatt bruker `content_type` eller `is_active`
- model-default / modellkontrakt som fortsatt antar `content_type`
- payloadfelt som fortsatt eksponerer legacy-speil til frontend
- audit-kommandoen må bli schema-aware, slik at den tåler både overgangsform og fysisk droppet skjema
- tester som fortsatt behandler feltene som relevante kompatibilitetsfelter må ryddes eller flyttes til rene legacy-/audit-tester

**Anbefalt neste mikrostep:**

- Fjern siste lesere og payload-speil for `content_type`/`is_active` i Kunnskapsbase og AI-kjeden.
- Gjør `knowledge:legacy-audit` schema-aware før fysisk migrasjon.
- Når ingen aktive konsumenter lenger trenger feltene, kan en egen migrasjon som dropper kolonnene vurderes som separat steg.

**Ikke gjort i 2.8F:**

- ingen migrasjon
- ingen kolonnedropp
- ingen produksjonsdataendring
- ingen frontend-endring
- ingen retrieval- eller AI-logikkendring

### 28.8G Fjerning av aktive app-lesere av `content_type` og `is_active` (kontrollert juni 2026)

Fase 2.8G fjerner de siste aktive app-konsumentene av legacy-speilene `content_type` og `is_active` i Kunnskapsbase-/AI-kjeden. Felt og legacy audit-fixtures forblir i databasen, men brukerflaten og AI-payloadene leser nå autoritative felter:

- `document_type` i stedet for `content_type`
- `document_status` i stedet for `is_active`

**Hva som ble ryddet i denne fasen:**

- Kunnskapsbase-payloadene for liste, detalj, redigering og revisjonssnapshots eksponerer ikke lenger `content_type`, `content_type_label` eller `is_active`.
- AI-arbeidsflyten bruker `document_type` i alle aktive leser- og prompt-/AI-payloadene.
- `Show.jsx` normaliserer og viser `document_type` i stedet for `content_type`.

**Hva som fortsatt står igjen bevisst:**

- Legacy-feltene finnes fortsatt i databasen og kan fortsatt leses av audit-/legacy-tester.
- `KnowledgeItem` beholder foreløpig legacy-kompatibilitet der opprettelsesflyten fortsatt trenger det.
- Ingen migrasjon eller kolonnedropp er gjort.

**Videre retning:**

- Når det ikke finnes aktive app-lesere igjen og audit er schema-aware, kan fysisk kolonnedropp vurderes som et separat steg.

### 28.8H Schema-awareness i `knowledge:legacy-audit` for `content_type` og `is_active` (juni 2026)

Fase 2.8H gjør `knowledge:legacy-audit` robust mot fremtidig kolonnedropp. Audit-kommandoen sjekker nå om `content_type` og `is_active` faktisk finnes i `knowledge_items`-skjemaet før den forsøker å lese dem.

**Hva som ble endret:**

- `AuditKnowledgeLegacyFields` har to nye beskyttede metoder: `hasContentTypeColumn()` og `hasIsActiveColumn()`, begge delegerer til `Schema::hasColumn('knowledge_items', ...)`.
- SELECT-listen bygges dynamisk — `content_type` og `is_active` inkluderes kun når kolonnene finnes.
- Mismatch-sjekkene for disse feltene kjøres kun når kolonnen finnes.
- Output skiller tydelig mellom `column: present` (mismatch telles og rapporteres som expected legacy finding) og `column: absent, skipped` (ingen feil, kommandoen fortsetter normalt).

**Overgangsperiodens atferd:**

- Kolonnene finnes: audit rapporterer `content_type column: present` og `is_active column: present`, eventuelle mismatches logges som expected legacy findings.
- Kolonnene er droppet: audit rapporterer `content_type column: absent, skipped` og `is_active column: absent, skipped`, ingen krasj, `OK_FOR_NEXT_STEP` kan fortsatt returneres dersom ingen andre funn finnes.

**Tester:**

Tre nye tester dekker schema-aware-atferden:
1. `content_type`-kolonnen mangler → audit krasjer ikke, rapporterer skipped.
2. `is_active`-kolonnen mangler → audit krasjer ikke, rapporterer skipped.
3. Begge mangler → `OK_FOR_NEXT_STEP` returneres.

Eksisterende tester verifiserer fortsatt at mismatch rapporteres korrekt som expected legacy finding når kolonnene finnes.

**Ikke gjort i 2.8H:**

- ingen migrasjon
- ingen kolonnedropp
- ingen produksjonsdataendring
- ingen frontend-endring
- ingen retrieval- eller AI-logikkendring

### 28.8A Fysisk fjerning av `content_type` og `is_active` fra `knowledge_items` ✓ Fullført juni 2026

Fase 2.8A er fullført juni 2026. Legacy-kolonnene `content_type` og `is_active` er fysisk fjernet fra `knowledge_items` etter at fase 2.7 isolerte all aktiv legacy-bruk og fase 2.8H gjorde audit-kommandoen schema-aware.

**Hva som ble ryddet:**

- `KnowledgeItem.$attributes`-default for `content_type` er fjernet — forhindret INSERT-feil etter kolonnedropp
- `is_active`-cast er fjernet fra `KnowledgeItem::casts()`
- Død `content_type`-fallback i `RequirementAssessmentService` er fjernet (to steder)
- `forceFill`-blokker for `content_type` og `is_active` i `createKnowledgeItem()`-helper i `AiControllerTest` er fjernet
- Assertions på `content_type` og `is_active` i `KnowledgeBaseControllerTest` og `KnowledgeBaseAiUsageControllerTest` er fjernet
- Defensiv migrasjon `2026_06_25_160000_drop_legacy_columns_from_knowledge_items_table.php` er lagt til med `Schema::hasColumn()`-sjekk i `up()` og reversibel `down()` som legger tilbake kolonnene som nullable

**`knowledge:legacy-audit` etter kolonnedropp:**

- `content_type column: absent, skipped`
- `is_active column: absent, skipped`
- `OK_FOR_NEXT_STEP`

Audit-kommandoen er schema-aware (fase 2.8H) og krasjer ikke ved fraværende kolonner.

**Historiske migrasjoner** kan fortsatt inneholde feltnavnene `content_type` og `is_active`, men de er ikke aktive app-avhengigheter.

**Ikke endret:**

- `SavedNoticeAiDocument` og Saksdokumenter
- AI-svarutkastflyt og `answer_draft_coverage`
- `SavedNoticeAiEvidence`
- Retrieval-logikk
- Frontend/UI

**Autoritative felt etter fjerning:**

- `document_type` — autoritativ kilde for dokumentkategori/type
- `document_status` — autoritativ kilde for aktiv/inaktiv-status

**Commit:** `ad3e3b7`

---

### 28.9 Fysisk fjerning av filidentitetsfeltene fra `knowledge_items` ✓ Fullført juni 2026

Fase 28.9 er fullført juni 2026. Filidentitetsfeltene `original_filename`, `storage_path`, `mime_type` og `file_size_bytes` er fysisk fjernet fra `knowledge_items`. Autoritativ kilde for filidentitet er nå utelukkende `knowledge_item_versions`.

**Progresjon gjennom underfaser:**

- **28.9C** — Aktiv skriving til legacy-speil (`KnowledgeItem.original_filename` osv.) ble stoppet. Upload-/godkjenningsflyten skriver nå kun til `KnowledgeItemVersion`.
- **28.9D** — Direkte PHP-lesing fra legacy-feltene ble flyttet til versjonstabell-resolvers i alle app-kontrollere og tjenester.
- **28.9E** — SQL/query builder-lesing (scope-filtre, joins) ble flyttet fra `knowledge_items`-kolonnene til `knowledge_item_versions`.
- **28.9F** — Resolver-fallback til legacy-feltene på `KnowledgeItem` ble fjernet fra alle fire resolver-metoder (`resolvedOriginalFilename()`, `resolvedStoragePath()`, `resolvedMimeType()`, `resolvedFileSizeBytes()`). Resolverne returnerer nå `null` dersom `currentVersion` mangler feltet.
- **28.9G** — Schema-aware audit og fysisk dropp. `knowledge:legacy-audit` utvidet med `Schema::hasColumn()`-sjekker for alle seks legacy-felt. Defensiv migrasjon `2026_06_25_214118_drop_legacy_file_fields_from_knowledge_items_table.php` droppet alle fire kolonner med `hasColumn()`-guard i `up()` og reversibel `down()`. Alle tester ble ryddet for de fire feltene.

**`knowledge:legacy-audit` etter kolonnedropp:**

```
original_filename column: absent, skipped
storage_path column: absent, skipped
mime_type column: absent, skipped
file_size_bytes column: absent, skipped
content_type column: absent, skipped
is_active column: absent, skipped
OK_FOR_NEXT_STEP
```

**Commit:** `62a9221`

**Ikke endret:**

- `SavedNoticeAiDocument` og Saksdokumenter
- AI-svarutkastflyt og `answer_draft_coverage`
- `SavedNoticeAiEvidence`
- Retrieval-logikk
- Frontend/UI
- `KnowledgeItemVersion`-felter

**Autoritative felt etter fjerning:**

- `knowledge_item_versions.original_filename` — autoritativ kilde for filnavn
- `knowledge_item_versions.storage_path` — autoritativ kilde for filsti
- `knowledge_item_versions.mime_type` — autoritativ kilde for MIME-type
- `knowledge_item_versions.file_size_bytes` — autoritativ kilde for filstørrelse

---

### 28.10 Plan for ekstraksjonsfeltene

Gjelder:

- `extraction_status`
- `extraction_error`
- `extracted_text`

Autoritativ retning: disse feltene hører hjemme på `knowledge_item_versions`, ikke som aktiv sannhet på `knowledge_items`. `KnowledgeItemVersion` er allerede autoritativ kilde. `KnowledgeItem` er fortsatt et legacy-speil med aktive fallbacks og aktive skrive- og leseveier som må ryddes før fysisk dropp.

### 28.10A Kartlegging av aktive runtime-avhengigheter (juni 2026)

Kartlegging gjennomført juni 2026. Ingen kodeendringer gjort i denne delsteget — kun dokumentasjon.

**Akseptable treff (allerede versjonsstyrt):**

- `KnowledgeItemVersion.$fillable` — feltene er autoritative i versjonstabellen.
- `KnowledgeBaseController.documentListPayload()`, `documentDetailPayload()`, `documentFormPayload()` — bruker `$knowledgeDocument->resolvedExtractionStatus()` og `$knowledgeDocument->resolvedExtractionError()` fra resolver.
- `KnowledgeBaseController` — ny versjon upload (linje ~620-626): skriver kun til `KnowledgeItemVersion`, ikke til `knowledge_items`.
- `KnowledgeBaseController` — versjonshistorikk-payload (linje ~2036-2037): leser fra `$version->extraction_status`, `$version->extraction_error`.
- `KnowledgeMetadataMapService` og `MetadataCandidateRetrievalService`: filtrerer på `knowledge_item_versions.extraction_status` — korrekt versjonskilde.
- `AiController` — alle `$document->extracted_text`-referanser gjelder `SavedNoticeAiDocument`, ikke `KnowledgeItem`. Utenfor scope.
- `KnowledgeVocabularyExtractionService` linje 203: bruker `'extracted_text'`-nøkkel i en AI-prompt-payload; verdien hentes via `textForKnowledgeProcessing()` resolver, ikke direkte KI-kolonne.
- Migrasjoner (`2026_04_07_140001`, `2026_06_19_000001`) — historisk.
- Audit-kommandoen: leser `item.extraction_status`, `item.extraction_error`, `item.extracted_text` mot `currentVersion` for drift-sammenligning. Bevisst legacy-speil-sporingsfunksjonalitet.

**Aktive KI-avhengigheter som må ryddes før drop:**

| Avhengighet | Fil | Linje(r) | Beskrivelse |
|---|---|---|---|
| KI write (initial upload) | `KnowledgeBaseController::store()` | ~383–390 | Oppretter `KnowledgeItem` med `extracted_text`, `extraction_status`, `extraction_error` ved opplasting |
| KI read for KIV-opprettelse | `KnowledgeBaseController::store()` | ~401–403 | Leser `$knowledgeDocument->extraction_status`, `$knowledgeDocument->extraction_error` fra nettopp opprettet KI, for å skrive til KIV |
| KI write (versjonsgodkjenning) | `KnowledgeBaseController::activateKnowledgeItemVersion()` | ~812–817 | `$document->forceFill(['extracted_text' => ..., 'extraction_status' => ..., 'extraction_error' => ...])->save()` — speil synkroniseres til KI ved godkjenning |
| KI read (resolver fallback) | `KnowledgeItem::resolvedExtractionStatus()` | ~257 | `return $this->currentVersion?->extraction_status ?? $this->extraction_status;` — faller tilbake til KI-felt |
| KI read (resolver fallback) | `KnowledgeItem::resolvedExtractionError()` | ~266–272 | Faller tilbake til `$this->extraction_error` når `currentVersion` er null |
| KI read (resolver fallback) | `KnowledgeItem::resolvedExtractedText()` | ~281–295 | Faller tilbake til `$this->extracted_text` når `currentVersion?.extracted_text` er tom |
| KI read (SQL-filter) | `KnowledgeVocabularyController::representativeDocumentsPayload()` | ~569 | `->where('extraction_status', ...)` — filtrerer direkte på `knowledge_items.extraction_status` uten JOIN mot versjonstabellen |

**Hva som må ryddes i påfølgende delsteg (rekkefølge veiledende):**

1. Stopp skriving av ekstraksjonsfelt til `knowledge_items` i initial upload — skriv kun til `KnowledgeItemVersion`.
2. Stopp synkronisering av ekstraksjonsfelt til `knowledge_items` i `activateKnowledgeItemVersion()` — KIV er allerede autoritativ kilde, KI trenger ikke oppdateres.
3. Flytt `KnowledgeVocabularyController::representativeDocumentsPayload()` til å filtrere på `knowledge_item_versions.extraction_status` via subquery eller JOIN.
4. Fjern fallback til KI-felt i de tre resolver-metodene — etter at alle KI-rader har gyldig current version med ekstraksjonsdata.
5. Gjør `knowledge:legacy-audit` schema-aware for `extraction_status`, `extraction_error` og `extracted_text` (tilsvarende som ble gjort for `content_type`/`is_active` i fase 28.8H og filidentitetsfeltene i fase 28.9G).
6. Fysisk dropp etter at alle konsumenter er verifisert rene.

**Ikke endret:**

- `SavedNoticeAiDocument` og Saksdokumenter
- AI-svarutkastflyt og `answer_draft_coverage`
- `SavedNoticeAiEvidence`
- Retrieval-logikk
- Frontend/UI
- Ingen migrasjon er lagt til

### 28.10B Stopp aktiv skriving til ekstraksjonsfelt på `knowledge_items` (juni 2026)

Fase 28.10B fjerner aktiv runtime-skriving av ekstraksjonsfelt fra `knowledge_items`. `KnowledgeItemVersion` er nå eneste sted disse verdiene lagres ved ny opplasting og ved versjonsgodkjenning.

**Hva som ble endret:**

- `KnowledgeBaseController::store()`: Fjernet `extracted_text`, `extraction_status`, `extraction_error` fra `KnowledgeItem::create()`. Lokale variabler (`$versionExtractionStatus`, `$versionExtractionError`) brukes nå direkte i `KnowledgeItemVersion::create()`, uten mellomlagring på KI.
- `KnowledgeBaseController::activateKnowledgeItemVersion()`: Fjernet speil-synkronisering av `extracted_text`, `extraction_status`, `extraction_error` fra KIV tilbake til KI ved versjonsgodkjenning. Kun `uploaded_by_user_id` speiles fortsatt.
- Tre tester i `KnowledgeBaseControllerTest` oppdatert til å sjekke ekstraksjonsfelt på `KnowledgeItemVersion` i stedet for på `KnowledgeItem`.

**Gjenværende akseptable skrivinger:**

- `KnowledgeBaseController` ny versjon upload (~linje 620–626): skriver til `KnowledgeItemVersion`, ikke til `KnowledgeItem`. Uendret og korrekt.

**Fortsatt igjen (midlertidig):**

- Resolver-fallback i `KnowledgeItem::resolvedExtractionStatus()`, `resolvedExtractionError()` og `resolvedExtractedText()` leser fortsatt fra KI-feltene som fallback. Fjernes i et later steg etter at data er verifisert.
- `KnowledgeVocabularyController::representativeDocumentsPayload()` filtrerer fortsatt på `knowledge_items.extraction_status` direkte. Flyttes til versjonskilde i et later steg.
- Ingen kolonner er droppet.

**Ikke endret:**

- `SavedNoticeAiDocument` og Saksdokumenter
- AI-svarutkastflyt og `answer_draft_coverage`
- Retrieval-logikk
- Frontend/UI

### 28.10C Flytt aktiv SQL/query-read fra `knowledge_items.extraction_status` til current version (juni 2026)

Fase 28.10C flytter det siste aktive SQL-filteret på `knowledge_items.extraction_status` over til `knowledge_item_versions.extraction_status`.

**Hva som ble endret:**

- `KnowledgeVocabularyController::representativeDocumentsPayload()`: Fjernet `->where('extraction_status', KnowledgeItem::EXTRACTION_STATUS_COMPLETED)` på `knowledge_items`. Filteret er nå lagt til i den eksisterende `whereExists`-subqueryen mot `knowledge_item_versions`, som allerede filtrerte på `is_current = true` og `storage_path IS NOT NULL`. `knowledge_item_versions.extraction_status = 'completed'` er nå en del av den subqueryen.

**Gjenværende akseptable treff:**

- `KnowledgeItem.$fillable` — feltet finnes fortsatt i DB og er fillable. Akseptabelt inntil kolonnedropp.
- `KnowledgeBaseController` ny versjon upload (~linje 621): skriver `extraction_status` til `KnowledgeItemVersion`. Korrekt.
- `KnowledgeBaseController` payload-metoder (~linje 1870, 1926): bruker `$extractionStatus` fra `resolvedExtractionStatus()` resolver. Korrekt.
- `KnowledgeBaseController` versjonshistorikk (~linje 2034): `$version->extraction_status` fra `KnowledgeItemVersion`. Korrekt.
- Kommentarer i `AiController`, `KnowledgeMetadataMapService`, `MetadataCandidateRetrievalService`. Akseptable.

**Fullført:** Commit `"Read extraction status from knowledge item versions"`. 157/157 tester passerte.

**Fortsatt igjen (til 28.10D):**

- Resolver-fallback i `KnowledgeItem::resolvedExtractionStatus()`, `resolvedExtractionError()` og `resolvedExtractedText()` leser fortsatt fra KI-feltene som fallback.
- Ingen kolonner er droppet.

**Ikke endret:**

- `SavedNoticeAiDocument` og Saksdokumenter
- AI-svarutkastflyt og `answer_draft_coverage`
- Retrieval-logikk
- Frontend/UI

### 28.10D Fjern resolver-fallback for ekstraksjonsfeltene på `KnowledgeItem` (juni 2026)

Fase 28.10D fjerner de tre resolver-fallbackene som leste direkte fra `knowledge_items`-feltene dersom `currentVersion` manglet. Resolverne leser nå utelukkende fra `currentVersion`.

**Hva som ble endret:**

- `KnowledgeItem::resolvedExtractionStatus()`: Fjernet `?? $this->extraction_status`. Returnerer nå `$this->currentVersion?->extraction_status`.
- `KnowledgeItem::resolvedExtractionError()`: Fjernet `if ($this->currentVersion) ... return $this->extraction_error` fallback. Returnerer nå `$this->currentVersion?->extraction_error`.
- `KnowledgeItem::resolvedExtractedText()`: Fjernet sjekk mot `$this->extracted_text`. Returnerer nå trim av `currentVersion?.extracted_text`, eller `null` om det er tomt eller ingen versjon finnes.
- Docblock oppdatert: prioritet er nå `currentVersion.extracted_text → KnowledgeItem.content → null`.
- `tests/Unit/KnowledgeItemOwnershipTest.php`: Oppdatert test `test_text_for_knowledge_processing_returns_content_when_no_version` — reflekterer at uten `currentVersion` returnerer `textForKnowledgeProcessing()` nå `KnowledgeItem.content` (ikke `KnowledgeItem.extracted_text`).

**Gjenværende treff etter 28.10D:**

- `KnowledgeItem.$fillable` — `extraction_status`, `extraction_error`, `extracted_text` finnes fortsatt i fillable/DB. Akseptabelt inntil kolonnedropp.
- `knowledge:legacy-audit` rapporterer `OK_FOR_NEXT_STEP` etter endringen.

**Ikke endret:**

- `SavedNoticeAiDocument` og Saksdokumenter
- AI-svarutkastflyt og `answer_draft_coverage`
- Retrieval-logikk
- Frontend/UI
- Ingen kolonner er droppet.

**Fullført:** Commit `"Remove extraction fallback from knowledge items"`. Alle tester passerte.

### 28.10E Etterkontroll — bekreft at ingen aktiv runtime-avhengighet gjenstår (juni 2026)

Fase 28.10E er en ren kontroll- og dokumentasjonsfase etter at 28.10A–28.10D er ferdige. Ingen runtime-kodeendringer ble gjort.

**Startstatus:** Arbeidskopi ren. `knowledge:legacy-audit` rapporterer `OK_FOR_NEXT_STEP` (ingen blokkerende funn, ingen mirror-avvik).

**Søkeresultat — kategoriserte treff:**

| Kategori | Fil / plassering | Vurdering |
|---|---|---|
| Autoritativ KIV | `KnowledgeItemVersion.$fillable` — `extracted_text`, `extraction_status`, `extraction_error` | Riktig — KIV er autoritativ kilde |
| Autoritativ KIV | `KnowledgeBaseController.php:401–403` — skriver til KIV ved ny opplasting | Riktig |
| Autoritativ KIV | `KnowledgeBaseController.php:620–624` — skriver til KIV ved ny versjon | Riktig |
| Autoritativ KIV | `KnowledgeBaseController.php:697,2034–2035` — leser `$version->extraction_*` (KIV) | Riktig |
| Via currentVersion | `KnowledgeItem.php:251,256,261` — resolver-metoder leser kun fra `currentVersion` | Riktig etter 28.10D |
| Via currentVersion | `KnowledgeVocabularyExtractionService.php:203` — nøkkel `extracted_text` i payload, verdi fra `textForKnowledgeProcessing()` (ikke KI direkte) | Riktig |
| Saksdokumenter (utenfor scope) | `AiController.php:1600,1687,1688` — `SavedNoticeAiDocument $document` | Utenfor scope |
| Saksdokumenter (utenfor scope) | `RequirementExtractionRunService.php:438,687,835` — `SavedNoticeAiDocument` | Utenfor scope |
| Saksdokumenter (utenfor scope) | `RequirementExtractionPipeline.php:44` — `SavedNoticeAiDocument` | Utenfor scope |
| Saksdokumenter (utenfor scope) | `DocumentSplitPlanner.php:32,128` — `SavedNoticeAiDocument` | Utenfor scope |
| Saksdokumenter (utenfor scope) | `RequirementCandidateExtractor.php:214,622,2276` — `SavedNoticeAiDocument` | Utenfor scope |
| Saksdokumenter (utenfor scope) | `SavedNoticeAiDocument.$fillable` — `extracted_text` | Utenfor scope |
| Audit-kommando | `AuditKnowledgeLegacyFields.php:195–244` — leser `$currentVersion->extraction_*` og `$item->extraction_*` for speil-sammenligning | Akseptabelt — audit er bevisst klar over kolonnen |
| Tester | `AuditKnowledgeLegacyFieldsCommandTest.php:47–83` — oppretter KI med ekstraksjonsfelt for å teste audit-kommandoen | Akseptabelt — audit-tester krever dette |
| Tester | `AiControllerTest.php:7973` — `$knowledgeItem->extracted_text` direkte lesing i testhjelpemetode `syncKnowledgeItemChunks` | Se pre-drop-liste nedenfor |
| Modellkonfigurasjon | `KnowledgeItem.$fillable:129,131,132` — `extracted_text`, `extraction_status`, `extraction_error` fortsatt i fillable | Se pre-drop-liste nedenfor |

**Funn: Ingen aktiv runtime-lesing eller -skriving til `knowledge_items.extraction_*` fra produksjonskode.**

Alle treff i `app/` der `->extracted_text`/`->extraction_status`/`->extraction_error` brukes er enten på `KnowledgeItemVersion`, på `SavedNoticeAiDocument` (Saksdokumenter), via resolver/currentVersion, eller i audit-kommandoen. Ingen resolver faller tilbake til KI-felter etter 28.10D.

**Pre-drop-liste for 28.10F:**

Følgende tre punkter blokkerer ikke runtime, men må ryddes som del av eller før kolonnedropp i 28.10F:

1. **`KnowledgeItem.$fillable`** — `extracted_text`, `extraction_status`, `extraction_error` ligger fortsatt i `$fillable`. Bør fjernes fra fillable samtidig med kolonnedroppmigrasjon. Kan ikke fjernes isolert uten å bryte `AuditKnowledgeLegacyFieldsCommandTest` (som skriver disse verdiene til KI for å teste audit-kommandoen).

2. **`AiControllerTest.php:7973`** — `$knowledgeItem->extracted_text` direkte lesing i testhjelpemetode `syncKnowledgeItemChunks`. Bør byttes til `$knowledgeItem->textForKnowledgeProcessing()` eller tilsvarende. Vil krasje med kolonnen fjernet.

3. **`AuditKnowledgeLegacyFields.php`** — sammenligner `$currentVersion->extraction_*` med `$item->extraction_*` for å finne mirror-avvik. Når kolonnen droppes er disse sammenligningene meningsløse og bør fjernes/justeres i audit-kommandoen (og tilsvarende i testen).

**Ingen kodeendringer i 28.10E** — etterkontroll bekrefter at produksjonskoden er klar. Pre-drop-rydding gjøres som del av 28.10F.

**Neste steg: 28.10F** — fysisk drop av `knowledge_items.extraction_status`, `extraction_error`, `extracted_text` etter at pre-drop-listen over er håndtert. Se §28.10E pre-drop-liste.

**Fullført:** Commit `"Document extraction field drop readiness"`.

### 28.10F Fysisk drop av ekstraksjonsfelt fra `knowledge_items` (juni 2026)

Fase 28.10F fjerner fysisk kolonnene `extraction_status`, `extraction_error` og `extracted_text` fra `knowledge_items`. `KnowledgeItemVersion` er nå eneste sted disse verdiene lagres og leses.

**Hva som ble endret:**

- **`app/Models/KnowledgeItem.php`**: Fjernet `extracted_text`, `extraction_status`, `extraction_error` fra `$fillable`. Ingen `$casts`- eller `$attributes`-endringer nødvendig for disse feltene.
- **`app/Console/Commands/AuditKnowledgeLegacyFields.php`**: Audit-kommandoen er gjort schema-aware for ekstraksjonsfeltene. Tilsvarende mønster som for filidentitetsfeltene (fase 28.9G):
  - Lagt til `hasExtractionStatusColumn()`, `hasExtractionErrorColumn()`, `hasExtractedTextColumn()` metoder.
  - `selectColumns` er betinget — ekstraksjonsfeltene velges bare dersom de finnes i skjemaet.
  - Speil-sammenligningene i chunk-løkken er betinget på kolonnetilstedeværelse.
  - Review findings og Extraction mirrors i output rapporterer `absent, skipped` ved fravær.
- **`database/migrations/2026_06_26_000001_drop_legacy_extraction_fields_from_knowledge_items_table.php`**: Defensiv migrasjon med `Schema::hasColumn()`-sjekk i `up()` og `down()`. `down()` gjenskaper kolonnene som nullable uten default eller index.
- **`tests/Feature/App/AiControllerTest.php`**: Testhjelpemetoden `syncKnowledgeItemChunks` byttet fra `$knowledgeItem->extracted_text` til `$knowledgeItem->textForKnowledgeProcessing()`.
- **`tests/Feature/Console/AuditKnowledgeLegacyFieldsCommandTest.php`**:
  - `createKnowledgeItemVersion` hjelpemetode: byttet standardverdier fra `$knowledgeItem->extraction_*` (null etter drop) til `''` / `EXTRACTION_STATUS_COMPLETED` / `null`.
  - `test_command_reports_mirror_drift_and_does_not_write_any_rows`: fjernet `DB::table('knowledge_items')->update(...)` med ekstraksjonsfelt; endret assertions fra `legacy_extraction_status_mismatches=1` til `extraction_status column: absent, skipped` o.l.; `content_fallback_candidates=1` → `content_fallback_candidates=0`.
- **`tests/Unit/KnowledgeItemOwnershipTest.php`**: `test_knowledge_item_version_is_backfilled_with_file_fields` — byttet `$document->extracted_text/extraction_status/extraction_error` til eksplisitte litereraler i KIV-opprettelse.
- **`tests/Feature/App/KnowledgeVocabularyControllerTest.php`**: `createKnowledgeItem` hjelpemetode — byttet `$item->extracted_text/extraction_status/extraction_error` til `$overrides['...'] ?? $content/COMPLETED/null`.
- **`tests/Feature/App/KnowledgeBaseControllerTest.php`**:
  - `createKnowledgeItemPayloadFixture`: KIV-opprettelse byttet fra `$item->extracted_text/extraction_status/extraction_error` til `$overrides['extracted_text'] ?? $content`, `$overrides['extraction_status'] ?? COMPLETED`, `$overrides['extraction_error'] ?? null`.
  - `createCurrentVersionFor`: byttet fra `$item->extracted_text/extraction_status/extraction_error` til `$item->content / COMPLETED / null`.
  - `test_payload_falls_back_to_legacy_extraction_state_when_current_version_is_missing` omdøpt til `test_payload_reads_extraction_state_from_current_version` — tester nå at KIV-verdier returneres i payload (korrekt atferd etter 28.10D/F).
  - `test_knowledge_document_summary_falls_back_to_cleaned_raw_text_without_toc_noise`: erstattet `createCurrentVersionFor` med direkte KIV-opprettelse for å bevare den strukturerte `extracted_text`-verdien som TOC-rensing testes mot.

**Søk etter aktiv KI-bruk etter drop:** Ingen aktiv runtime-lesing eller -skriving til `knowledge_items.extraction_*` funnet. Alle gjenværende treff er `KnowledgeItemVersion`, `SavedNoticeAiDocument` (utenfor scope), resolver-metoder via `currentVersion`, eller audit-kommandoen.

**Audit etter drop:**
```
Extraction mirrors
- extraction_status column: absent, skipped
- extraction_error column: absent, skipped
- extracted_text column: absent, skipped
Recommendation
OK_FOR_NEXT_STEP
```

**Tester:** 145/145 i `KnowledgeBaseControllerTest`, 35/35 i `KnowledgeVocabularyControllerTest` + `KnowledgeItemOwnershipTest` + `AuditKnowledgeLegacyFieldsCommandTest`. Kjent PDF-bilde-/figureutvinningsfeil er pre-eksisterende infrastrukturproblem og ikke relatert til 28.10F.

**Ikke endret:**
- `SavedNoticeAiDocument` og Saksdokumenter
- AI-svarutkastflyt og `answer_draft_coverage`
- `knowledge_items.content`
- Retrieval-logikk
- Frontend/UI

**Fullført:** Commit `"Drop legacy extraction fields from knowledge items"`.

### 28.10G Etterkontroll og formell lukking av fase 28.10 (juni 2026)

Fase 28.10G er en ren kontroll- og dokumentasjonsfase som formelt lukker ekstraksjonsfelt-oppryddingen.

**Kontroller utført:**

- Arbeidskopi ren ved start.
- `knowledge:legacy-audit` kjørt — `OK_FOR_NEXT_STEP`. Alle tre ekstraksjonsfelt rapportert som `absent, skipped`.
- Søk på `knowledge_items.extraction_*` i `app/`, `tests/`, `database/`, `docs/` — ingen aktiv runtime-lesing eller -skriving mot `knowledge_items` funnet.
- `KnowledgeItem.php` bekreftet ren: `$fillable` inneholder ikke de droppede feltene, `$casts` har ingen legacy-casts, `$attributes` setter ingen defaults, resolverne leser utelukkende fra `currentVersion`.
- Migrasjon `2026_06_26_000001_drop_legacy_extraction_fields_from_knowledge_items_table.php` bekreftet defensiv: `up()` og `down()` bruker `Schema::hasColumn()`.

**Konkret feil funnet og rettet:**

Testhjelpemetoden `createKnowledgeItem` i `tests/Unit/Services/KnowledgeVocabularyAnalysisBatchServiceTest.php` leste `$item->extracted_text`, `$item->extraction_status`, `$item->extraction_error` fra KI for å sette KIV-verdier (samme klasse av feil som ble rettet i andre testfiler i 28.10F). Kolonnene returnerer null etter dropp, og KIV fikk feil verdier. Fikset ved å:
- Fjerne de droppede feltene fra `KnowledgeItem::query()->create()`-kallet
- Erstatte `$item->extracted_text/extraction_status/extraction_error` i KIV-opprettelsen med `$overrides['extracted_text'] ?? $content`, `$overrides['extraction_status'] ?? EXTRACTION_STATUS_COMPLETED`, `$overrides['extraction_error'] ?? null`

Testene passerte tilfeldig fordi `KnowledgeVocabularyExtractionService` er mocket og ikke filtrerer på `extraction_status`. Feilen er likevel rettet for konsistens og for å unngå stille feil ved fremtidige endringer.

**Akseptable gjenværende treff:**

- `KnowledgeItemVersion` — har kolonnene nativt
- `SavedNoticeAiDocument.extracted_text` — saksdokumenter, utenfor scope
- Requirements-tjenester (`RequirementCandidateExtractor`, `DocumentSplitPlanner`, `RequirementExtractionPipeline`, `RequirementExtractionRunService`) — leser `$document->extracted_text` fra `SavedNoticeAiDocument`
- `KnowledgeVocabularyExtractionService.php:203` — array-literal-nøkkelstreng, ikke modell-tilgang
- `KnowledgeMetadataMapService`, `MetadataCandidateRetrievalService` — kun kommentarer
- Audit-kommandoen — schema-aware
- Historiske migrasjoner
- Tester som verifiserer current-version-atferd

**Tester etter 28.10G-rettingen:**

- 4/4 `KnowledgeVocabularyAnalysisBatchServiceTest`
- 145/145 `KnowledgeBaseControllerTest`
- 35/35 kombinert `KnowledgeVocabularyControllerTest` + `KnowledgeItemOwnershipTest` + `AuditKnowledgeLegacyFieldsCommandTest`

**Fase 28.10 er formelt lukket.**

`KnowledgeItemVersion` er autoritativ kilde for `extraction_status`, `extraction_error` og `extracted_text`. `knowledge_items` har ikke lenger disse kolonnene. `knowledge:legacy-audit` er schema-aware og grønn. `SavedNoticeAiDocument`, Saksdokumenter, AI-svarutkastflyt og `knowledge_items.content` ble ikke endret.

**Fullført:** Commit `"Mark extraction field cleanup as completed"`. Fase 28.11 ikke startet.

### 28.11 Egen vurdering av `content`

- Kun når all tekstlesing er versjonsbasert og siste fallback ikke lenger trengs.

**28.11A — Kartlegging og auditgrunnlag (juni 2026)**

Kartlegging avdekket at `content_fallback_candidates`-telleren i `AuditKnowledgeLegacyFields` var blind etter at `knowledge_items.extracted_text` ble droppet i 28.10F. Telleren var gatekjørt av `$hasExtractedText = Schema::hasColumn('knowledge_items', 'extracted_text')` som alltid returnerte `false` etter droppet — dermed ble verdien alltid `0` uavhengig av faktiske data.

**28.11A-2 — Reparasjon av audit-teller (juni 2026)**

Audit-kommandoen er oppdatert med korrekt logikk for `content_fallback_candidates`:

- Rader der `currentVersion` mangler og `knowledge_items.content` ikke er blank telles nå korrekt (sjekk lagt inn før `continue`-hoppet).
- Rader der `currentVersion.extracted_text` er blank og `knowledge_items.content` ikke er blank telles uten avhengighet av droppede KI-kolonner.

Testen `test_command_reports_mirror_drift_and_does_not_write_any_rows` oppdatert: `content_fallback_candidates=0` → `content_fallback_candidates=2`. To nye tester lagt til:
- `test_content_fallback_candidates_counts_correctly` — dekker case A (KIV.extracted_text ikke-blank → ikke telt), case B (KIV.extracted_text blank, content ikke-blank → telt), case D (content blank → ikke telt).
- `test_content_fallback_candidates_counts_item_without_current_version` — dekker case C (ingen KIV, content ikke-blank → telt).

Lokal audit rapporterte `content_fallback_candidates=0` i testmiljøet (1 KI).

**28.11A-3 — Databasekontroll (juni 2026)**

Avklart at Procynia foreløpig ikke har noen produksjonsdatabase. Lokal Docker-database (`postgres:5433/procynia`) er eneste relevante datakilde. Audit kjørt mot Docker-containeren med korrigert teller: `content_fallback_candidates=0`. Ingen eksisterende rader er avhengige av `knowledge_items.content` som fallback. 28.11B kan startes.

**28.11B — Stopp speilskriving til `content` (juni 2026)**

`KnowledgeBaseController::store()` skrev tidligere `content` som speil av ekstrahert tekst (eller filnavn ved mislykket ekstraksjon). Fra og med 28.11B skrives kun `'content' => ''` (tom streng) ved oppretting — NOT NULL-restriksjonen i `knowledge_items` krever en verdi, og tom streng tilfredsstiller dette uten å beholde speilet. `KnowledgeItemVersion.extracted_text` er fortsatt autoritativ.

- `knowledge_items.content`-kolonnen er **ikke** droppet — det er ikke del av 28.11B.
- `content` er **ikke** fjernet fra `$fillable` — ikke del av 28.11B.
- Fallback-logikken i `textForKnowledgeProcessing()` er **ikke** endret — blank `content` faller nå naturlig gjennom til `null` ved mislykket ekstraksjon, som er korrekt atferd.
- `KnowledgeVocabularyExtractionService`, `KnowledgeVocabularyAnalysisBatchService` og `KnowledgeDocumentSummaryGenerationService` er **ikke** endret.

Ny test `test_knowledge_document_store_writes_extracted_text_to_current_version_without_mirroring_to_content` lagt til i `KnowledgeBaseControllerTest`. Bekrefter at `knowledge_items.content` er blank etter opplasting, at `currentVersion.extracted_text` inneholder ekstrahert tekst, og at `textForKnowledgeProcessing()` returnerer versjonsdataene.

Audit kjørt etter endringen: `content_fallback_candidates=0` bekreftet.

**28.11C — Etterkontroll av aktiv tekstlesing og fallback (juni 2026)**

Kartlegging av all gjenværende bruk av `knowledge_items.content` og `->content` i codebase:

- **Aktiv runtime-lesing fra `KnowledgeItem.content`:** Kun via `textForKnowledgeProcessing()` i `KnowledgeItem.php:276` — dette er den intenderte fallback-stien, ikke en primær datakilde.
- **Aktiv runtime-skriving:** `KnowledgeBaseController::store()` skriver `'content' => ''` (tom streng) etter 28.11B — ingen tekst skrives lenger.
- **AI-tjenester:** `KnowledgeVocabularyExtractionService` (linje 183), `KnowledgeVocabularyAnalysisBatchService` (linje 246) og `KnowledgeDocumentSummaryGenerationService` (linje 47) henter alle dokumenttekst via `$document->textForKnowledgeProcessing()`. Ingen leser direkte fra `KnowledgeItem.content`.
- **Øvrige `->content`-treff i `app/`:** Leser fra `KnowledgeItemChunk.content`, ikke `KnowledgeItem.content` — urelatert.
- **Diagnostikk:** `AuditKnowledgeLegacyFields` leser `$item->content` som del av tellerlogikk — forventet.
- **Testhjelpemetoder (C — stale men ufarlige):**
  - `KnowledgeBaseControllerTest:4533` (`createCurrentVersionFor`) — leser `$item->content` for å sette `KIV.extracted_text`; etter 28.11B gir dette `''` for nye KI-er, men brukes kun i tester som setter `content` eksplisitt.
  - `KnowledgeBaseControllerTest:5590` (`bindSuccessfulKnowledgeDocumentSummaryGenerationService`) — leser `$document->extracted_text ?: $document->content`; `extracted_text`-attributt er alltid `null` (kolonnen droppet i 28.10F), `$document->content` er `''` etter 28.11B. Primær sti (chunks) treffer alltid først — fallback er aldri aktiv i praksis.

Konklusjon: Ingen aktiv runtime-lesing leser direkte fra `knowledge_items.content` utenom `textForKnowledgeProcessing()`. Fallback er i behold. Fysisk kolonnedropp er ikke gjort — ikke del av 28.11C. `content_fallback_candidates=0` bekreftet.

Tester: 175/175 passerte (`KnowledgeBaseControllerTest`, `AuditKnowledgeLegacyFieldsCommandTest`, `KnowledgeVocabularyAnalysisBatchServiceTest`, `KnowledgeItemOwnershipTest`).

**Datakontroller før eventuell migrasjon:**

- alle `KnowledgeItem`-rader har gyldig `currentVersion` når dokumentet faktisk har fil
- current version har forventet filidentitet og filmetadata
- current version har forventet ekstraksjonsstatus og eventuelle feilverdier
- ingen aktiv payload, retrieval eller prosessering leser direkte fra legacyfeltet som skal fjernes
- tester dekker gamle dokumenter, current-version-fallback og null-/tomverdier
- rollback-plan er dokumentert før noen kolonne fjernes

**Akseptansekriterier før fysisk kolonnedropp:**

- feltet skrives ikke lenger
- feltet leses ikke lenger
- feltet brukes ikke som fallback
- feltet finnes ikke lenger i payloadkontrakt eller UI
- feltet brukes ikke av tester som legitim kompatibilitet
- produksjonsdata er validert
- migrasjonen er reversibel, eller risikoen er eksplisitt akseptert

**Fase 2.8 er fullført juni 2026.** Den fysiske databaseoppryddingen ble gjennomført kontrollert etter at fase 2.7 isolerte all aktiv legacy-bruk. Legacy-kolonnene `content_type` og `is_active` er fysisk fjernet fra `knowledge_items`. Etterkontroll bekreftet at resterende treff kun er historiske migrasjoner, dokumentasjon, schema-aware audit, andre modellers legitime `is_active`-felter eller tester som verifiserer fravær/ignorering. `knowledge:legacy-audit` er schema-aware og rapporterer `OK_FOR_NEXT_STEP` når kolonnene er fraværende. `SavedNoticeAiDocument`, Saksdokumenter, AI-svarutkastflyt og `answer_draft_coverage` ble ikke endret. Autoritative felt er fortsatt `document_type` og `document_status`.

---

## 29. Fase 3 og videre: Utsatt til senere

Følgende er identifisert som verdifullt på sikt, men skal ikke implementeres nå. De innebærer større endringer i datamodell, flyt eller forretningslogikk, og krever at fase 2 er avklart og stabil først.

### Avdeling/team-tilhørighet

`ownership_type` støtter i dag `company`, `personal` og `case`. Avdeling/team-tilhørighet krever `owning_department_id`, utvidet tilgangsstyring og oppdatering av retrieval.

### Hierarkiske temaer

Temaer er i dag flate. En hierarkisk modell med `parent_id` gir mer presis kategorisering, men øker kompleksiteten i admin-UI og retrieval.

### Godkjenningsflyt

Versjonsbasert godkjenning (pending_review → approved/rejected) er implementert i fase 2.5. Det som gjenstår for Fase 3 er dokument-nivå godkjenning: dedikert godkjenner-rolle, varslingsflyt og statusoverganger for selve `KnowledgeItem` (ikke bare `KnowledgeItemVersion`).

### Kvalitetsscore

Automatisk vurdering av dokumentkvalitet basert på manglende felt, utløpsdato, ekstraksjonsstatus og lignende.

### Faner i brukerflaten

Bibliotek, Mine dokumenter, Saksdokumenter, Til behandling, Utløper snart, Kvalitet og Arkiv som separate faner i Kunnskapsbase.

### Retrieval-filtrering på dokumentkategori og tema

AI-retrieval kan på sikt filtrere på `document_category_id` og `document_topic_id` for mer presis kontekststyring. Dette bør ikke gjøres før AI-policy og dokumentstatus er avklart og på plass.

### Deling og løfting av dokumenter

Funksjonalitet for å løfte personlige dokumenter eller saksdokumenter til avdelings- eller selskapsnivå, gjennom en kontrollert kandidat-/godkjenningsflyt.

### Varslingssystem for revisjon

Automatisk varsling til dokumenteier eller godkjenner når `review_due_at` nærmer seg eller er passert. Krever en varslingsmotor og brukerinnstillinger for varslingsterskel. Ikke implementert nå — revisjonsdatoene er på plass og synlige, men varsling er utsatt til en dedikert varslingsrunde.

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
- `review_due_at` endrer ikke automatisk `document_status`, `ai_usage_enabled` eller `is_active`. Dokumenter med passert revisjonsfrist forblir aktive inntil en bruker eksplisitt endrer status.

---

## 31. Konklusjon

Kunnskapsbase har gått fra et åpent dokumentlager til en strukturert kunnskapsbase med eierskap, kategorisering, temastruktur, sporbarhet og kontrollert AI-bruk.

Fase 1 — begrepsmodell, kundestyrte katalogverdier, frontend-integrasjon og validering — er fullført per juni 2026.

Fase 2.1 — AI-policy per dokument (`ai_usage_enabled`) — er fullført per juni 2026. Retrieval respekterer feltet, og det er synlig i listevisning, detaljside og skjema.

Fase 2.2 — Dokumentstatus (`document_status`) — er fullført per juni 2026. Feltet styrer dokumentets livsløp, er den eneste brukerrettede statusen, og respekteres av retrieval. `is_active` avledes automatisk og er ikke lenger brukerrettet.

Fase 2.3 — Revisjon og gyldighet (`last_reviewed_at`, `review_due_at`, `review_state`) — er fullført per juni 2026. Datoene gir kontroll og synlighet for dokumenters faglige gyldighet. Revisjonsstatus er en synlighetsmekanisme — den endrer ikke automatisk status eller AI-bruk.

Fase 2.4 — Versjonering av dokumentinnhold — er fullført per juni 2026. `knowledge_item_versions` gir fullstendig versjonssporing per `KnowledgeItem`. Retrieval henter bare chunks fra gjeldende versjon. Ny fil oppretter ny versjon uten at gamle versjoner eller chunks slettes. UI viser versjonshistorikk read-only og lar brukeren laste opp ny versjon fra detaljsiden. Rollback, sletting av enkeltversjoner og sammenligning er ikke bygget i denne fasen.

Fase 2.5 — Kontroll og godkjenning av kunnskapsdokumenter — er fullført per juni 2026. Nye dokumentversjoner aktiveres ikke lenger automatisk etter tekstuttrekk. De opprettes som `pending_review` og krever eksplisitt godkjenning før de settes som `is_current` og tas i bruk av AI. Avvisning er mulig med obligatorisk begrunnelse. Retrieval er ikke endret — det henter fortsatt kun chunks fra gjeldende versjon (`is_current = true`) etter eksisterende kriterier. `SavedNoticeAiDocument` og Saksdokumenter er ikke berørt.

Fase 2.6 — Innsyn i Kunnskapsbase-kilder sendt til AI — er fullført per juni 2026 (2.6B–F). Evidence-rader peker stabilt på dokumentversjonen som lå til grunn da svaret ble generert. Backend-payloaden eksponerer `knowledge_sources_sent_to_ai` per krav. UI viser kildene kompakt i krav-svar-panelet, med advarsel ved supersedert versjon og lenke til riktig KnowledgeItem-detaljside. I tillegg finnes den globale oversikten `Kunnskapsbase → Bruk i AI` (`/app/ai/knowledge-base/ai-usage`), som viser dokument- og chunkbruk på tvers av saker. Oversikten er videreutviklet som en videreføring av 2.6F med fullt master-detail-grensesnitt: dokumenttabellen er masterliste med automatisk radvalg, utdragstabellen filtreres på valgt dokument, begge tabeller er sorterbare, og hele dokumentraden er klikkbar. En Inertia-komponentfeil som hindret lasting av siden er rettet, og en draft-rendering-feil som skjulte lagrede svarutkast ved siden av evidence-visningen er rettet. Retrieval, prompt og AI-modellkall er ikke endret. `SavedNoticeAiDocument` og Saksdokumenter er ikke berørt. Dette innsynet viser hva som ble sendt som kontekst — ikke hva modellen faktisk brukte internt.

Kunnskapsbase har nå:
- Dokumentkategori og tema — kundestyrte katalogverdier med cascading validering
- AI-policy per dokument — `ai_usage_enabled` gir eksplisitt AI-tillatelse per dokument
- Dokumentstatus — `document_status` styrer livsløpet og respekteres av retrieval
- Revisjon og gyldighet — `review_due_at` og `last_reviewed_at` gir synlighet for faglig oppdatering
- Versjonering av dokumentinnhold — `knowledge_item_versions` med versjonsbevisst retrieval og UI for opplasting av ny versjon
- Kontroll og godkjenning — versjonsgodkjenning med pending/approved/rejected/superseded-flyt, revisjonslogg og synlig AI-statusboks på detaljsiden
- Innsyn i Kunnskapsbase-kilder sendt til AI — sporbarhet fra evidence-rad til dokumentversjon, payload per krav, UI-visning i krav-svar-panelet og lenke til riktig Kunnskapsbase-dokument; global oversikt med master-detail-funksjon, sorterbare tabeller og klikkbare dokumentrader

Fase 2.7 er nå dokumentert som fullført kontrollert legacy-isolering.

Fase 2.8A — fysisk fjerning av `content_type` og `is_active` fra `knowledge_items` — er fullført per juni 2026. Kolonnene eksisterer ikke lenger i skjemaet. `knowledge:legacy-audit` rapporterer `OK_FOR_NEXT_STEP` med `absent, skipped` for begge kolonner. `SavedNoticeAiDocument`, Saksdokumenter, AI-svarutkastflyt og `answer_draft_coverage` er ikke endret. Autoritative felt er `document_type` og `document_status`.

Fase 2.8 er fullført juni 2026. Den fysiske databaseoppryddingen ble gjennomført kontrollert etter at fase 2.7 isolerte all aktiv legacy-bruk. Legacy-kolonnene `content_type` og `is_active` er fysisk fjernet fra `knowledge_items`. Etterkontroll bekreftet at resterende treff kun er historiske migrasjoner, dokumentasjon, schema-aware audit, andre modellers legitime `is_active`-felter eller tester som verifiserer fravær/ignorering. `knowledge:legacy-audit` er schema-aware og rapporterer `OK_FOR_NEXT_STEP` når kolonnene er fraværende. `SavedNoticeAiDocument`, Saksdokumenter, AI-svarutkastflyt og `answer_draft_coverage` ble ikke endret. Autoritative felt er fortsatt `document_type` og `document_status`.

Fase 28.9 — fysisk fjerning av filidentitetsfeltene (`original_filename`, `storage_path`, `mime_type`, `file_size_bytes`) fra `knowledge_items` — er fullført juni 2026. Autoritativ kilde er nå utelukkende `knowledge_item_versions`. Aktiv skriving til legacy-speil ble stoppet i 28.9C, direkte PHP-lesing ble flyttet i 28.9D, SQL/query builder-lesing ble flyttet i 28.9E, resolver-fallback ble fjernet i 28.9F, og fysisk dropp med schema-aware audit ble gjennomført i 28.9G. `knowledge:legacy-audit` rapporterer `OK_FOR_NEXT_STEP` med `absent, skipped` for alle seks legacy-kolonner. `SavedNoticeAiDocument`, Saksdokumenter, AI-svarutkastflyt og `answer_draft_coverage` ble ikke endret.

Fase 28.10 — fysisk fjerning av ekstraksjonsfeltene (`extraction_status`, `extraction_error`, `extracted_text`) fra `knowledge_items` — er fullført juni 2026. `KnowledgeItemVersion` er autoritativ kilde for alle ekstraksjonsverdier. 28.10A kartla aktive runtime-avhengigheter; 28.10B stoppet aktiv skriving til KI-speilene; 28.10C flyttet det siste aktive SQL-filteret til `knowledge_item_versions`; 28.10D fjernet resolver-fallback; 28.10E bekreftet drop-readiness; 28.10F fjernet kolonnene fysisk og oppdaterte ni filer; 28.10G gjennomførte etterkontroll, rettet én oversett testhjelpemetode, og formelt lukket fasen. `knowledge:legacy-audit` rapporterer `OK_FOR_NEXT_STEP` med `absent, skipped` for alle tre ekstraksjonsfelt. `SavedNoticeAiDocument`, Saksdokumenter, AI-svarutkastflyt og `knowledge_items.content` ble ikke endret. Fase 28.11 (`content`) er ikke startet.

Saksdokumenter og Kunnskapsbase er to distinkte områder og skal fortsette å være det. `SavedNoticeAiDocument` og `KnowledgeItem` er separate modeller med separate flater og separate retrieval-stier.
