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

`document_type` er fortsatt den autoritative kilden for dokumentkategori/type, og `document_status` er fortsatt den autoritative kilden for aktiv/inaktiv-status. `KnowledgeItemVersion` er autoritativ kilde for gjeldende filidentitet, filmetadata og ekstraksjonsdata. `KnowledgeItem` beholder legacy-/fallbackfelter av kompatibilitetshensyn.

### 28.7L — Kartlegging av gjenværende legacy-speilfelter (2.7L)

Denne kartleggingen oppsummerer de feltene som fortsatt fungerer som legacy-speil eller fallback i Kunnskapsbase. De er fortsatt i bruk i payloads, UI, retrieval, AI-strømmer eller tester, og kan derfor ikke fjernes før alle konsumenter er flyttet helt over til versjons- og metadata-kildene.

**Autoritativ kilde for filidentitet:** `KnowledgeItemVersion` for gjeldende versjon. `KnowledgeItem` beholder midlertidige speilfelter for bakoverkompatibilitet.

**Autoritativ kilde for ekstraksjonsstatus:** gjeldende `KnowledgeItemVersion.extraction_status`. `KnowledgeItem.extraction_status` er fortsatt et legacy-speil som brukes som fallback i flere payloads og tester.

| Felt | Autoritativ kilde | Skrives fortsatt? | Leses fortsatt? | Brukt i payload/UI/retrieval/AI/tests? | Risiko ved fjerning | Anbefalt neste steg |
| --- | --- | --- | --- | --- | --- | --- |
| `storage_path` | `KnowledgeItemVersion.storage_path` for gjeldende versjon; `KnowledgeItem.storage_path` er legacy-speil | Ja, ved opplasting, erstatning og godkjenning | Ja | Ja: filhandlinger, payloads, revisjonssnapshots og tester | Høy — kan bryte filreferanser og historikk | Behold til alle filhandlinger leser rent fra versjonstabellen |
| `original_filename` | `KnowledgeItemVersion.original_filename` for gjeldende versjon; `KnowledgeItem.original_filename` som bakoverkompatibel fallback | Ja | Ja | Ja: liste, detalj, versjonshistorikk og tester | Høy — påvirker visning, deduplisering og historikk | Fase ut først når alle visninger er versjonsbevisste |
| `mime_type` | `KnowledgeItemVersion.mime_type` for gjeldende versjon; `KnowledgeItem.mime_type` som legacy-speil | Ja | Ja | Ja: liste, detalj, filmetadata og tester | Høy — filtypevisning og filhandlinger kan feile | Behold til hele UI-et leser fra versjonspayload alene |
| `file_size_bytes` | `KnowledgeItemVersion.file_size_bytes` for gjeldende versjon; `KnowledgeItem.file_size_bytes` som legacy-speil | Ja | Ja | Ja: liste, detalj, versjonshistorikk og tester | Middels/høy — størrelse kan forsvinne i UI og historikk | Fjern først når alle visninger bruker versjonsfeltet direkte |
| `extraction_status` | Gjeldende `KnowledgeItemVersion.extraction_status`; `KnowledgeItem.extraction_status` er fortsatt fallback | Ja | Ja | Ja: payload, banner-/statuslogikk, retrieval-sjekker og tester | Høy — kan bryte statusvisning og statusbasert filtrering | Behold til status er hentet konsekvent fra current version i alle konsumenter |
| `extraction_error` | Gjeldende `KnowledgeItemVersion.extraction_error`; `KnowledgeItem.extraction_error` som fallback | Ja | Ja | Ja: payload, UI-feilvisning og tester | Middels/høy — feilvisning kan bli tom eller feil | Fase ut når alle feilvisninger leser fra versjonspayload |
| `extracted_text` | `KnowledgeItemVersion.extracted_text` for gjeldende versjon; `KnowledgeItem.extracted_text` som legacy fallback | Ja | Ja | Ja: AI-grunnlag, sammendrag, payload-fallback og tester | Høy — kan påvirke AI-kontekst og fallback-tekster | Behold til alle AI-/oppsummeringskall er versjonsbaserte |
| `content` | Ingen enkelt kilde; brukes som siste fallback i `textForKnowledgeProcessing()` når versjonstekst og `KnowledgeItem.extracted_text` mangler | Ja, ved opprettelse og noen manuelle flyter | Ja | Ja: AI-/tekstgrunnlag og tester; ikke primær UI-kilde | Høy — kan fjerne siste tekstfallback for gamle dokumenter | Flytt all lesing til versjonstekst før denne kan ryddes bort |
| `content_type` | `document_type` | Ja, via modellens synkronisering | Ja | Ja: payload, UI og tester som legacy alias | Middels — begrepet er allerede avviklet brukerrettet, men aliaset brukes fortsatt | Behold til alle konsumenter bruker `document_type` konsekvent |
| `is_active` | `document_status` | Ja, via modellens synkronisering | Ja | Ja: payload, UI, retrieval og tester som legacy alias | Høy — påvirker status, filtrering og retrieval | Behold til `document_status` alene styrer alle konsumenter |

**Oppsummert:**

- Filidentitet og filmetadata bør på sikt leses fra `KnowledgeItemVersion`.
- `document_type`/`document_status` er de autoritative styringsfeltene; `content_type` og `is_active` er kun kompatibilitetsspeil.
- `content` er den svakeste delen av kjeden og bør ryddes sist, etter at all tekstbruk er versjonsbasert.
- Ingen kolonner skal slettes før alle lesere, payloads og tester er flyttet over til de nye kildene.

### 28.7M — Sentralisert filidentitetslesing (fullført)

Fase 2.7M sentraliserte lesing av filidentitet og filmetadata via `KnowledgeItem`-resolvers uten å endre payload-formatet.

Følgende resolver-metoder finnes nå på `KnowledgeItem`:

- `resolvedStoragePath()`
- `resolvedOriginalFilename()`
- `resolvedMimeType()`
- `resolvedFileSizeBytes()`

Resolverne leser først fra gjeldende `currentVersion` og faller deretter tilbake til legacy-feltene på `KnowledgeItem` for bakoverkompatibilitet. Dette reduserte direkte bruk av legacy-speil i controlleren uten å endre kontrakten til frontend.

### 28.7N — Sentralisert ekstraksjonsstatuslesing (fullført)

Fase 2.7N sentraliserte lesing av ekstraksjonsstatus, ekstraksjonsfeil og extracted text via `KnowledgeItem`-resolvers uten å endre payload-format eller brukeropplevelse.

Følgende resolver-metoder finnes nå på `KnowledgeItem`:

- `resolvedExtractionStatus()`
- `resolvedExtractionError()`
- `resolvedExtractedText()`

`resolvedExtractionError()` behandler `null` på current version som en meningsbærende verdi og faller derfor ikke tilbake til gammel legacy-feil når gjeldende versjon finnes uten feil. `textForKnowledgeProcessing()` bruker nå `resolvedExtractedText()` og beholder `content` som siste fallback.

Videre fysisk opprydding kan eventuelt vurderes som en senere egen fase. Da må det lages egen migrasjonsplan, datakontroll og avvikling av resterende fallback-/speilskriving før legacy-kolonnene eventuelt kan fjernes.

### 28.8 Fysisk databaseopprydding av legacy-felter (planlagt)

Fase 2.8 er neste separate fase etter kontrollert legacy-isolering. Mens fase 2.7 dokumenterte og isolerte legacy-bruken, skal fase 2.8 planlegge og gjennomføre fysisk utfasing av legacy-speilfelter på `knowledge_items` på en kontrollert måte.

Denne fasen er **ikke implementert ennå**. Den skal bare beskrive hva som må være klart før kolonner eventuelt kan droppes.

**Felt som skal vurderes i 2.8:**

- `content_type`
- `is_active`
- `storage_path`
- `original_filename`
- `mime_type`
- `file_size_bytes`
- `extraction_status`
- `extraction_error`
- `extracted_text`
- `content`

**Anbefalt risikorekkefølge:**

1. Lavere risiko
   - `content_type`
   - `is_active`
   - Disse er allerede klare compatibility-speil for `document_type` og `document_status`.

2. Høyere risiko
   - `storage_path`
   - `original_filename`
   - `mime_type`
   - `file_size_bytes`
   - Disse påvirker filidentitet, filhandlinger og visning av gjeldende dokumentversjon.

3. Høy risiko
   - `extraction_status`
   - `extraction_error`
   - `extracted_text`
   - Disse påvirker statusvisning, oppsummering, metadataflyt og AI-grunnlag.

4. Svært høy risiko
   - `content`
   - Dette er siste fallback i `textForKnowledgeProcessing()` og må ikke fjernes før hele tekstkjeden er verifisert versjonsbasert.

**Foreslåtte delsteg for 2.8:**

- 2.8A — Plan og datakontroll
  - Definere konkret omfang, risikonivå og rollback-strategi.
- 2.8B — Kartlegg produksjonsdata
  - Artisan-kommandoen `knowledge:legacy-audit` skal rapportere hvor ofte legacy-feltene fortsatt brukes i praksis, hvilke dokumenter som fortsatt er avhengige av fallback, og gi en read-only anbefaling før fysisk opprydding.
- 2.8C — Stopp eventuell gjenværende speilskriving for lavrisikofelter
  - `knowledge:legacy-audit` er kjørt og returnerte `OK_FOR_NEXT_STEP`.
  - Blocking findings: ingen.
  - Review findings: ingen.
  - Expected legacy findings: `current_version_missing_extraction_error_on_success=2` for vellykkede current versions uten lagret feiltekst.
  - Dette betyr at det foreløpig ikke er dataavvik som stopper fysisk opprydding, men at audit-kommandoen nå tydelig skiller mellom reelle blockers, vurderingspunkter og forventede legacy-avvik.
  - Tryggeste feltområde å starte med videre er fortsatt de lavrisiko speilfeltene `content_type` og `is_active`, fordi de allerede er hardt kontrollert av nåværende datamodell og payloader.
- 2.8D — Fjern lesere/fallback for lavrisikofelter
  - Modellens speilskriving for `content_type` og `is_active` er nå stoppet.
  - `document_type` og `document_status` er fortsatt autoritative.
  - Legacy-kolonnene finnes fortsatt i databasen, men de vedlikeholdes ikke lenger som aktive speil.
  - Avvik i `content_type` og `is_active` er nå forventede legacy-funn i audit.
  - Ingen kolonner er droppet i dette steget.
  - Neste konsumentsteg er å sikre at all lesing og all fremtidig opprydding tydelig behandler disse feltene som legacy-kompatibilitet, ikke som styrende felter.
- 2.8E — Vurder fysisk dropp av lavrisikofelter
  - Bare etter at ingen lesere, payloads eller tester er avhengige av dem.
- 2.8F — Tilsvarende plan for filidentitetsfeltene
  - `storage_path`, `original_filename`, `mime_type`, `file_size_bytes`.
- 2.8G — Tilsvarende plan for ekstraksjonsfeltene
  - `extraction_status`, `extraction_error`, `extracted_text`.
- 2.8H — Egen vurdering av `content`
  - Kun når all tekstlesing er versjonsbasert og siste fallback ikke lenger trengs.

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

**Ikke gjort i 2.8-planen:**

- ingen konkrete migrasjoner
- ingen `Schema::table(... dropColumn ...)`
- ingen modellendringer
- ingen controller-endringer
- ingen frontend-endringer
- ingen payload- eller retrieval-endringer

**Kort mål for fase 2.8:**

- planlegge og gjennomføre fysisk opprydding først når legacy-bruken er dokumentert borte og alle konsumenter er flyttet over til autoritative kilder.

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

2.7J presisering:
- `KnowledgeBaseController` skriver ikke lenger `content_type` eller `is_active` direkte ved store/update.
- `KnowledgeItem` holder fortsatt legacy-feltene synkronisert internt ved lagring som midlertidig kompatibilitet.
- Autoritative felter er fortsatt `document_type` og `document_status`.
- Legacy-kolonnene er fortsatt til stede; det er ikke gjort databaseopprydding ennå.

Saksdokumenter og Kunnskapsbase er to distinkte områder og skal fortsette å være det. `SavedNoticeAiDocument` og `KnowledgeItem` er separate modeller med separate flater og separate retrieval-stier.
