# Forventede resultater – test av kunnskapskobling i Enterprise Wiki

**Ikke last opp denne filen til Wiki-en.** Den er fasiten for å vurdere testkjøringen.

- Kildedokument som lastes opp: `docs/testing/wiki-knowledge-integration-test-source.docx`
- Redigerbar kilde: `docs/testing/wiki-knowledge-integration-test-source.md`
- Kunde: Advania Norge AS (`customer_id = 4`)
- Grunnlag: Wiki-tilstanden i utviklingsdatabasen 2026-10-18, lest uten endringer

## 1. Hvorfor dette temaet

Kunde 4 har to grupper med Wiki-innhold:

1. **SOC- og hendelsesklyngen (side 65–99).** Den er faglig rik og bygget fra `Masterdata_SOC_Advania.docx`. Den har 133 deterministiske wikilenker og tydelige sammenhenger mellom roller (SOC, Duty Officer, Incident Commander, IRT), prosesser (trusseletterretning, threat hunting, SOAR), måling (MTTA, ITSM-tidsstempler) og styring (ISO/IEC 27001/27002/27035, NSM, TLP, GDPR).
2. **Leverandørklyngen (side 102–111).** Den er tynn og har plassholdertekst («Må fylles inn», «bbbb»). Den har tre nesten like sider om registrering i leverandørregisteret (104, 105, 106) og nesten ingen lenker. Den egner seg dårlig som koblingsmål, fordi testen da ville måle duplikathåndtering i stedet for kunnskapskobling. Den egner seg derimot godt som **negativ kontroll**.

Etterhendelsesgjennomgang (Post Incident Review) nevnes i dag bare i én setning og én frist på IRT-siden (68): «Post Incident Review/Hot Washup med læringspunkter og forbedringstiltak», og gjennomføring innen 10 arbeidsdager. Ingen side forklarer hvordan gjennomgangen gjøres, hvordan tiltak eies og verifiseres, eller hvordan læring føres tilbake. Temaet hører til kvalitet og etterlevelse: avvik, korrigerende tiltak, kontinuerlig forbedring og effektverifisering. Det kobler naturlig mot mange eksisterende sider uten å duplisere dem.

## 2. Bevisste valg i testdokumentet

| Valg | Hva det tester |
| --- | --- |
| Dokumentet er generelt og nevner ikke Advania eller noen annen virksomhet | At AI ikke tilskriver generell fagpraksis til kunden som faktisk rutine |
| Ingen tallfestede frister; fristene «følger av den enkelte avtalen» | At AI ikke finner opp frister, og at fristene 2/5/10 arbeidsdager på side 68 forblir uendret og beholder sin opprinnelige provenance |
| MITRE ATT&CK, ISO/IEC 27035, ISO/IEC 27001, TLP og MTTA er beskrevet, men **ikke navngitt** | Semantisk navigasjon. Koblingen må oppdages ut fra innhold, ikke ut fra like ord (jf. arkitekturens punkt C) |
| Incident Commander, Duty Officer, Scribe, trusseletterretning er navngitt | At åpenbare koblinger faktisk oppstår |
| Ordene «leverandøren», «risikoaksept», «kontroll», «sletting» og «skanning» forekommer | Leksikale feller mot leverandørsidene, Exit-prosess og Tenable |
| Et tydelig merket fiktivt eksempel | At kunde- eller eksempelspesifikk evidens ikke generaliseres til tjenestenivå |
| Modaliteten er «anbefales» og «bør» flere steder | At anbefalinger ikke gjøres om til bindende «skal»-krav i `source_based`-blokker |

## 3. Forventede koblinger

Retning er ikke avgjørende. En lenke fra ny side til eksisterende side, eller en patch på eksisterende side som lenker til ny side, teller begge.

### 3.1 Nødvendige

Hvis disse mangler, er det et reelt funn.

| Side-ID | Tittel | Begrunnelse |
| --- | --- | --- |
| 68 | Incident Response Team (IRT) | Post Incident Review er IRT-prosessens siste steg. Dokumentet bruker IRT-rollene Scribe/Information Manager og Forensics og viser til «hendelsesteamet for alvorlige hendelser». Den nye kunnskapen utdyper nettopp det side 68 bare nevner. |
| 69 | Incident Commander | Dokumentet gjør Incident Commander til eier av gjennomgangen. Det er en ny ansvarsopplysning om rollen. |
| 72 | Trusseletterretning (Threat Intelligence) | Læringspunkter føres tilbake til trusseletterretningen og den faste gjennomgangen mellom etterretning, overvåking og hendelseshåndtering. Side 72 beskriver den samme tilbakeføringssløyfen og den ukentlige gjennomgangen. |

### 3.2 Sannsynlige

Forventet hos en god kjøring, men ikke krevd.

| Side-ID | Tittel | Begrunnelse |
| --- | --- | --- |
| 73 | MITRE ATT&CK | «Det felles rammeverket for taktikker og teknikker» og «dekningsmatrisen». Side 73 har kravet om en dekningsmatrise mot MITRE ATT&CK. Dette er den sterkeste semantiske testen uten navn. |
| 71 | Threat Hunting | «Retrospektiv trusseljakt etter samme teknikker». Side 71 beskriver threat hunting som både proaktiv og retrospektiv. |
| 78 | ITSM-integrasjon | Tidslinjen bygger på systemgenererte tidsstempler for «registrering, start av analyse, reklassifisering, varsling og tiltak». Det er nøyaktig samme liste som på side 78. Tiltak skal også ha egen saksreferanse. |
| 89 | ISO/IEC 27035 | «Læringsfasen i standarden for hendelseshåndtering». Side 89 er prosessreferansen for hendelseshåndtering, inkludert rapportering. |
| 87 | ISO/IEC 27001 | «Avvik og korrigerende tiltak håndteres systematisk i styringssystemet for informasjonssikkerhet». |
| 70 | Duty Officer (SOC) | Navngitt rolle i gjennomgangen, men med et lite bidrag. |
| 80 | Overvåking av loggkilder | Bortfall av loggkilde markeres i tidslinjen. Det fiktive eksempelet handler om et bortfall som ikke ble fulgt opp. |
| 83 | Sårbarhetsstyring | Sårbarhet som inngangsvektor: oppdaget, prioritert, fulgt opp hos kunden. Det samsvarer med rollefordelingen på side 83 (kunden beslutter og implementerer). |
| 74 eller 81 | SOAR / Automatisert respons (SOAR) | Vurdering av automatiserte tiltak og forhåndsgodkjente handlinger mot godkjenningsmodellen. Én av de to holder. De to sidene overlapper fra før, så lenke til begge er akseptabelt, men ikke et pluss. |

### 3.3 Mulige

Faglig forsvarlige, men ikke forventet. De teller verken for eller mot.

| Side-ID | Tittel | Begrunnelse |
| --- | --- | --- |
| 84 | MTTA (Mean Time to Acknowledge) | «Kvitteringstid» og «første bekreftede oppfølging» tilsvarer MTTA. Responsgapet er nært beslektet, men ikke identisk. |
| 86 | TLP (Traffic Light Protocol) | «Den etablerte protokollen for informasjonsdeling». |
| 85 | GDPR og personopplysningsloven | Dataminimering og pseudonymisering i rapporter. |
| 90 | Dataretensjon for sikkerhetslogger | «Ordinær lagringstid» og sikring av logger før de slettes. |
| 99 | Nasjonal sikkerhetsmyndighet (NSM) | «Nasjonale fagmiljøer for hendelseshåndtering». Svakt signal. |
| 67 | Security Operations Center (SOC) | Overordnet kontekst. Ordet «SOC» brukes ikke i dokumentet. |
| 65 / 66 | Masterdata for SOC (artikkel / sammendrag) | MD-SOC-022 «SLA-prinsipper, KPI-er og kontinuerlig forbedring» og MD-SOC-006 er beslektet. |

## 4. Koblinger som ikke bør opprettes

### 4.1 Harde feil

Én forekomst gjør at testen ikke er bestått.

| Side-ID | Tittel | Hvorfor feil |
| --- | --- | --- |
| 100, 101 | E2E Anskaffelsesrutine, E2E Terskelverdier | Tilhører kunde 2. Enhver kobling er et brudd på kundeisolasjonen. |
| 102–106, 109–111 | Leverandørartiklene (sikkerhetskrav, opprettelse, registrering, utvidet kontroll, betalingsbetingelser) | Leksikal felle. «Leverandøren» i testdokumentet er tjenesteleverandøren i en hendelsesleveranse, ikke en leverandør som registreres i et innkjøpsregister. «Risikoaksept» og «kontroll» er heller ikke det samme som leverandørkontroll. |
| 91 | Exit-prosess (tjenestenedtrapping) | Leksikal felle («sletting», «logger»). Exit handler om avslutning av leveransen, ikke om læring etter en hendelse. |

### 4.2 Feil, men ikke kritiske

| Side-ID | Tittel | Hvorfor feil |
| --- | --- | --- |
| 93–98 | LimaCharlie, Microsoft, Google Cloud, AWS, Fortinet, Tenable | Dokumentet nevner ingen produkter. «Skanning» skal ikke føre til Tenable. |
| 77, 79 | Kundeportal (SOC), Onboarding av datakilder | Ingen faglig sammenheng ut over generelle ord. |
| 92 | Advania Norge | Kobling i seg selv er ikke feil, men innhold som sier at Advania gjennomfører denne metoden, er feil. Dokumentet er generelt og tilskriver ingenting til Advania. |

### 4.3 Grensetilfeller

Vurderes skjønnsmessig.

- **75 SIEM, 76 EDR, 82 Hybrid SOC-arkitektur:** Dokumentet nevner «loggkilder og endepunkter» som kilder til tekniske spor. En kobling til EDR er forsvarlig, men svak. En kobling til Hybrid SOC-arkitektur er ikke begrunnet.

## 5. Ny kunnskap dokumentet tilfører

Ingen av punktene finnes i Wiki-en i dag:

1. Utløsende kriterier for full gjennomgang og forenklet vurdering. Også forenklede vurderinger dokumenteres for å fange mønstre.
2. Rollefordeling i gjennomgangen. Incident Commander eier, men en person uten ledende rolle i hendelsen fasiliterer.
3. Tidslinje fra tre kilder: sakssystemets tidsstempler, Scribes logg og tekniske spor.
4. **Deteksjonsgap og responsgap** som to separate mål. De skal ikke slås sammen.
5. Sikring av rådata før ordinær lagringstid løper ut, og eksplisitt markering av perioder med loggbortfall.
6. Skyldfritt prinsipp. Skillet mellom utløsende årsak, bidragende faktorer og barrierer.
7. Tilbakeføring av teknikker til dekningsmatrisen. Analyse av sårbarhet som inngangsvektor. SOAR-endringer fremmes som forslag og vedtas aldri direkte i gjennomgangen.
8. Tre tiltakstyper: korrigerende, forebyggende og kapabilitetsforbedring. Hvert tiltak har eier, frist, akseptkriterium og saksreferanse.
9. Kundens avslag på et tiltak dokumenteres som risikoaksept, ikke som åpent tiltak.
10. Et tiltak lukkes ved **verifisert effekt**, ikke ved gjennomføring, med konkrete verifiseringsformer per tiltakstype.
11. Retrospektiv trusseljakt når en ny angrepsmetode avdekkes.
12. Deling av læringspunkter krever samtykke, merking og dataminimering.
13. Fem indikatorer for selve forbedringssløyfen, og forbeholdet om at kortere responsgap kan skyldes nedklassifisering.

## 6. Forventet sidestruktur

Akseptable utfall:

- Én ny `concept`-side, for eksempel «Etterhendelsesgjennomgang (Post Incident Review)». Eventuelt med en `summary`-side for dokumentet.
- Eventuelt én ekstra `concept`-side for «Deteksjonsgap og responsgap» eller «Forbedringstiltak», dersom maintainer vurderer begrepet som selvstendig.
- Eventuelt en patch på side 68 som lenker til den nye siden fra avsnittet om Post Incident Review.

Ikke akseptabelt:

- En egen `entity`-side for «den fiktive virksomheten» eller hendelsen i eksempelet.
- En ny side som dupliserer side 68 eller 69.
- Mer enn 4–5 nye sider fra dette dokumentet. Det tyder på overfragmentering.

## 7. Innholdskontroller (utover lenker)

| # | Kontroll | Bestått når |
| --- | --- | --- |
| K1 | Fristene på side 68 | 2 / 5 / 10 arbeidsdager står uendret, med provenance fra `Masterdata_SOC_Advania.docx` (dokument 26). Ingen blokk med testdokumentets provenance inneholder disse tallene. |
| K2 | Ingen oppfunne tall | Ingen `source_based`-blokk fra testdokumentet inneholder frister, terskler eller prosentsatser. Dokumentet har ingen. |
| K3 | Det fiktive eksempelet | Eksempelet gjengis eventuelt som eksempel. Det blir ikke en påstand om tjenesten, kunden eller side 80. |
| K4 | Ingen tilskrivning | Ingen tekst sier at Advania (eller «Leverandøren» som Advania) praktiserer metoden. |
| K5 | Modalitet | «Anbefales» og «bør» forblir anbefalinger i `source_based`-blokker. «Skal»-formuleringer er bare akseptable der dokumentet selv bruker «skal» (for eksempel «hvert tiltak skal ha én navngitt eier»), eller i blokker merket `best_practice`. |
| K6 | Provenance | Hver `source_based`-blokk har nøyaktig ett kildedokument. Ingen blokk blander testdokumentet med masterdata. |
| K7 | Påstandsverifisering | Claims fra den nye siden verifiseres mot testdokumentet uten falske negativer på de navngitte rollene (Incident Commander, Scribe, Duty Officer). |
| K8 | Uberørte sider | Sider uten faglig relevans (91, 93–98, 102–111) får ingen ny versjon i kjøringen. |

## 8. Slik evaluerer vi resultatet

### 8.1 Datainnsamling (skrivebeskyttet)

Etter kjøringen, med `:run_id` som ingest-kjøringens ID:

```sql
-- Nye eller endrede sider i kjøringen
select rp.*, p.title, p.page_type
from enterprise_wiki_ingest_run_pages rp
join enterprise_wiki_pages p on p.id = rp.enterprise_wiki_page_id
where rp.enterprise_wiki_ingest_run_id = :run_id;

-- Relasjoner kjøringen faktisk opprettet (plan §8.4 og §18, P1-1).
-- Ikke filtrer på l.enterprise_wiki_ingest_run_id alene: før §18 skrev hver ny
-- materialisering av en side sin egen kjøring inn på ALLE sidens lenkerader, også de
-- gamle (kjøring 962 «fikk» 16 lenker fra september). Heller ikke created_at alene beviser
-- opphav, fordi en lenke som fjernes og legges til igjen, får ny rad.
-- Regelen: en wikilink-rad som finnes nå, fra en side kjøringen skrev (pivot eller anvendt
-- inngående relink), og som IKKE fantes i sidens siste versjon før kjøringen startet.
with run as (
  select id, started_at from enterprise_wiki_ingest_runs where id = :run_id
),
run_pages as (
  select rp.enterprise_wiki_page_id as page_id
  from enterprise_wiki_ingest_run_pages rp where rp.enterprise_wiki_ingest_run_id = :run_id
  union
  select ra.candidate_page_id from enterprise_wiki_page_relink_attempts ra
  where ra.enterprise_wiki_ingest_run_id = :run_id and ra.status = 'applied'
),
before_version as (
  select distinct on (v.enterprise_wiki_page_id) v.enterprise_wiki_page_id as page_id, v.content_markdown
  from enterprise_wiki_page_versions v
  join run_pages p on p.page_id = v.enterprise_wiki_page_id
  cross join run
  where v.created_at < run.started_at
  order by v.enterprise_wiki_page_id, v.version_number desc
),
before_targets as (
  select b.page_id, lower(trim(m[1])) as slug
  from before_version b, regexp_matches(b.content_markdown, '\[\[([^\]|#]+)', 'g') as m
)
select l.from_page_id, pf.title as fra, l.to_page_id, pt.title as til,
       l.link_type, l.source, l.confidence
from enterprise_wiki_page_links l
join run_pages p on p.page_id = l.from_page_id
join enterprise_wiki_pages pf on pf.id = l.from_page_id
join enterprise_wiki_pages pt on pt.id = l.to_page_id
where l.link_type = 'wikilink'
  and not exists (
    select 1 from before_targets bt where bt.page_id = l.from_page_id and bt.slug = lower(pt.slug)
  )
order by l.from_page_id, l.to_page_id;
-- Kontroll mot kjøring 962 (lest 2026-10-18): 23 rader — 20 fra de fire nye sidene og 3
-- inngående (68, 73, 67). De 16 eldre lenkene fra side 67, 68 og 73 er ikke med.

-- Kryss-kunde-kontroll (skal gi 0 rader)
select l.* from enterprise_wiki_page_links l
join enterprise_wiki_pages pf on pf.id = l.from_page_id
join enterprise_wiki_pages pt on pt.id = l.to_page_id
where pf.customer_id <> pt.customer_id;

-- Versjoner av eksisterende sider opprettet etter opplasting
select v.enterprise_wiki_page_id, v.version_number, v.is_current, v.created_at
from enterprise_wiki_page_versions v
where v.enterprise_wiki_page_id between 65 and 111
  and v.created_at >= :upload_time
order by 1, 2;
```

Lenker som er skrevet som wikilenker i ny tekst, men som ikke er registrert i `enterprise_wiki_page_links`, kontrolleres i tillegg ved å lese `content_markdown` på de nye sidene.

### 8.2 Klassifisering

Hver opprettet kobling får én vurdering:

- **Riktig:** står i 3.1 eller 3.2, eller er en ikke-forutsett kobling som har en konkret faglig begrunnelse i dokumentets innhold.
- **Akseptabel:** står i 3.3 eller 4.3.
- **Feil:** står i 4.1 eller 4.2, eller mangler faglig begrunnelse.

Ikke-forutsette koblinger vurderes på eget grunnlag. Fasiten er ikke uttømmende.

### 8.3 Bestått / ikke bestått

| Kriterium | Krav |
| --- | --- |
| Harde feil (4.1) | 0 |
| Presisjon: (riktig + akseptabel) / alle koblinger | ≥ 85 % |
| Nødvendige koblinger (3.1) | Minst 2 av 3 |
| Sannsynlige koblinger (3.2) | Ingen minimumskrav. Antall rapporteres. Minst én av de **unavngitte** (73, 89, 87) er et tydelig tegn på at semantisk navigasjon fungerer. |
| Innholdskontroller K1–K6 | Alle bestått |
| K7–K8 | Avvik rapporteres som funn, men stopper ikke testen alene |

Antall lenker er ikke et mål i seg selv. En kjøring med 5 riktige koblinger er bedre enn en kjøring med 15 koblinger der 3 er feil.

### 8.4 Valgfri oppfølging i Spør Wiki

Kjøres bare etter egen beslutning, fordi den kaller ekte AI:

| Spørsmål | Forventet |
| --- | --- |
| «Hva er forskjellen på deteksjonsgap og responsgap?» | Grunnet svar fra den nye siden |
| «Når kan et forbedringstiltak etter en sikkerhetshendelse lukkes?» | Ved verifisert effekt, ikke ved gjennomføring |
| «Hvem eier etterhendelsesgjennomgangen?» | Incident Commander, med en uavhengig fasilitator |
| «Hvor lang frist er det for Post Incident Review?» | 10 arbeidsdager, med kilde i side 68 / masterdata. **Ikke** fra testdokumentet. |
| «Hvor mange etterhendelsesgjennomganger gjennomførte Advania i fjor?» | `insufficient evidence` |
