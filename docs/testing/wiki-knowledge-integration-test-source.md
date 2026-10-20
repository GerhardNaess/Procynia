# Etterhendelsesgjennomgang og forbedringssløyfe etter sikkerhetshendelser

Fagnotat med generell metodebeskrivelse for Post Incident Review i en leveranse av sikkerhetsovervåking og hendelseshåndtering. Notatet beskriver anbefalt fagpraksis og er ikke en beskrivelse av rutiner, krav eller beslutninger i en bestemt virksomhet.

## Formål og avgrensning

En etterhendelsesgjennomgang (Post Incident Review, ofte kalt «hot washup» når den gjennomføres kort tid etter stabilisering) er den strukturerte aktiviteten som gjør en håndtert sikkerhetshendelse om til varig forbedring. Hendelsesrapporten beskriver hva som skjedde. Gjennomgangen skal i tillegg forklare hvorfor det skjedde, hvorfor det ble oppdaget når det ble oppdaget, og hva som må endres for at det ikke skal skje igjen.

Notatet dekker perioden fra hendelsen er stabilisert til forbedringstiltakene er verifisert. Selve håndteringen av hendelsen – skadebegrensning, fjerning av trusselaktør og gjenoppretting – omtales bare der den gir grunnlag for læring. Frister for hendelsesrapport og gjennomgang følger av den enkelte avtalen og fastsettes ikke her.

## Når en gjennomgang skal gjennomføres

Ikke alle hendelser krever en full gjennomgang. Følgende kriterier anbefales som utløsende:

- Hendelsen førte til aktivering av hendelsesteamet for alvorlige hendelser.
- Hendelsen ble klassifisert til et av de to høyeste alvorlighetsnivåene, selv om den ble løst uten eskalering.
- Avtalte tidsmål for kvittering, varsling eller eskalering ble brutt.
- Hendelsen ble oppdaget av kunden, en tredjepart eller en myndighet før den ble oppdaget av overvåkingen.
- Samme rotårsak har gitt hendelser tidligere.

For hendelser som ikke oppfyller kriteriene, er en forenklet gjennomgang tilstrekkelig: en kort vurdering i saken av om hendelsen avdekket et forbedringsbehov. Den forenklede vurderingen skal likevel dokumenteres, slik at mønstre på tvers av mange mindre hendelser kan fanges opp over tid.

## Roller i gjennomgangen

Den som ledet hendelsen, normalt Incident Commander, eier gjennomgangen og er ansvarlig for at den blir gjennomført. Selve møtet bør likevel fasiliteres av en person som ikke hadde en ledende rolle under hendelsen. Det reduserer risikoen for at gjennomgangen blir et forsvar av egne beslutninger.

Scribe eller Information Manager har allerede ført tidslinje, beslutninger og tiltak under hendelsen, og leverer dette som utgangspunkt. Duty Officer bidrar med vurderingen av kapasitet og prioritering i den operative fasen. Forensics- og deteksjonsressursene bidrar med teknisk forløp og vurdering av hvilke spor som fantes i datagrunnlaget. Funksjonen for trusseletterretning bør delta når hendelsen kan knyttes til en kjent aktør, kampanje eller sårbarhet. Kundens bemyndigede kontakt deltar alltid, fordi mange tiltak ligger i kundens eget miljø og må besluttes der.

## Forberedelse: rekonstruksjon av tidslinjen

Gjennomgangen bygger på en etterprøvbar tidslinje, ikke på hukommelse. Tidslinjen settes sammen av tre kilder:

1. Systemgenererte tidsstempler i sakssystemet for registrering, start av analyse, reklassifisering, varsling og tiltak.
2. Hendelsesloggen ført av Scribe, med beslutninger og begrunnelser.
3. Tekniske spor fra loggkilder og endepunkter som viser når trusselaktøren faktisk var aktiv.

Det viktigste analytiske grepet er å sammenligne tidspunktet for første tekniske spor med tidspunktet for første alarm og tidspunktet for første bekreftede oppfølging. Avstanden mellom de to første viser et deteksjonsgap. Avstanden mellom de to siste viser et responsgap. De to gapene har ulike årsaker og krever ulike tiltak, og bør derfor aldri slås sammen til ett tall.

Rekonstruksjonen forutsetter at rådata fortsatt finnes. Dersom gjennomgangen ligger nær utløpet av ordinær lagringstid, må relevante logger og bevismateriale sikres særskilt etter kundens instruks og med dokumentert forvaringskjede. Perioder der en loggkilde har vært ute av drift, markeres eksplisitt i tidslinjen, slik at manglende spor ikke tolkes som fravær av aktivitet.

## Gjennomføring: fra forløp til årsaker

Gjennomgangen gjennomføres etter et skyldfritt prinsipp. Spørsmålet er hvilke forhold som gjorde en feil mulig eller sannsynlig, ikke hvem som gjorde feilen. Erfaring viser at deltakere ellers holder tilbake nettopp den informasjonen som forklarer forløpet.

Analysen skiller mellom tre forhold:

- **Utløsende årsak:** det konkrete forholdet som gjorde hendelsen mulig, for eksempel en utnyttet sårbarhet eller en kompromittert konto.
- **Bidragende faktorer:** forhold som gjorde hendelsen større eller oppdagelsen senere, for eksempel manglende deteksjonsdekning, uklar fullmakt eller en forsinket varsling.
- **Barrierer:** tiltak som virket, og tiltak som skulle ha virket, men sviktet.

Observerte angrepsteknikker kartlegges mot det felles rammeverket for taktikker og teknikker som brukes i deteksjonsarbeidet. For hver teknikk avgjøres det om den ble oppdaget, om den kunne ha blitt oppdaget med eksisterende data, eller om datagrunnlaget manglet. Resultatet føres direkte inn i dekningsmatrisen, slik at hendelsen påvirker prioriteringen av ny deteksjonsutvikling.

Der inngangsvektoren var en kjent sårbarhet, vurderes det hvorfor sårbarheten ikke var lukket. Ble den oppdaget? Ble den prioritert riktig ut fra eksponering og trusselbilde? Ble anbefalingen fulgt opp hos kunden? Svaret avgjør om tiltaket hører hjemme i skanningen, prioriteringen eller i kundens endringsprosess.

Der automatiserte responstiltak ble utløst, vurderes det om tiltakene traff riktig, om de ble forsinket av godkjenningskrav, og om noen tiltak burde vært automatisert. Endringer i hvilke handlinger som er forhåndsgodkjent, gjøres aldri som et direkte resultat av gjennomgangen. De fremmes som forslag og avtales med kunden etter den ordinære godkjenningsmodellen.

## Klassifisering og eierskap av forbedringstiltak

Hvert tiltak klassifiseres som én av tre typer:

- **Korrigerende tiltak** fjerner den konkrete årsaken i det berørte miljøet.
- **Forebyggende tiltak** hindrer at samme type årsak gir hendelser andre steder, også hos andre kunder der det er relevant og avtalt.
- **Kapabilitetsforbedringer** styrker evnen til å oppdage og håndtere neste hendelse: nye deteksjoner, justerte eskaleringskriterier, oppdaterte prosedyrer eller opplæring.

Hvert tiltak skal ha én navngitt eier, en frist, et akseptkriterium som beskriver hvordan effekten skal påvises, og en egen saksreferanse slik at det kan følges opp på samme måte som andre saker. Tiltak som ligger i kundens miljø, eies av kunden. Leverandøren eier tiltak i egen tjeneste og egne prosesser. Dersom kunden velger ikke å gjennomføre et anbefalt tiltak, dokumenteres dette som en bevisst risikoaksept med begrunnelse, ikke som et åpent tiltak.

## Verifisering av effekt

Et tiltak lukkes ikke når det er gjennomført, men når effekten er påvist i henhold til akseptkriteriet. For en ny deteksjon betyr det at regelen er testet mot en kontrollert simulering av teknikken og har gitt alarm. For en sårbarhet betyr det at ny skanning bekrefter at den er lukket. For en prosessendring betyr det at endringen er tatt i bruk og har fungert i minst én reell eller øvd situasjon.

I tillegg anbefales en retrospektiv trusseljakt etter samme teknikker i hele det overvåkede miljøet når gjennomgangen avdekker en ny angrepsmetode. Formålet er å avklare om samme aktør eller metode har vært aktiv andre steder uten å bli oppdaget.

## Tilbakeføring til kunnskap og trusselbilde

Læringspunktene skal ut av rapporten og inn i det løpende arbeidet. Indikatorer og teknikker fra hendelsen gis til trusseletterretningen og vurderes i den faste gjennomgangen mellom etterretning, overvåking og hendelseshåndtering. Prosedyrer og responsscenarier oppdateres der gjennomgangen har vist svakheter.

Deling av læringspunkter utenfor kundeforholdet krever kundens samtykke og skal merkes etter den etablerte protokollen for informasjonsdeling. Det gjelder også anonymiserte erfaringer som deles med andre kunder eller med nasjonale fagmiljøer for hendelseshåndtering. Rapporter og læringsnotater skal ikke inneholde flere personopplysninger enn formålet krever. Brukernavn og identiteter erstattes med roller eller pseudonymer der identiteten ikke er nødvendig for å forstå læringen.

## Måling av forbedringssløyfen

Forbedringssløyfen bør måles som en egen prosess, ikke bare gjennom de operative responstidene. Anbefalte indikatorer er:

- andel gjennomganger gjennomført innen avtalt frist
- andel tiltak lukket med verifisert effekt, ikke bare gjennomført
- gjennomsnittlig alder på åpne forbedringstiltak
- antall hendelser med samme rotårsak som en tidligere gjennomgått hendelse
- utvikling i deteksjonsgap og responsgap over tid

Den siste indikatoren må ses sammen med kvitteringstid og andelen falske positiver. Et kortere responsgap er bare en forbedring dersom det ikke skyldes at flere reelle hendelser er klassifisert lavere.

Indikatorene rapporteres i den ordinære tjenesterapporteringen. De gir dokumentasjon for at avvik og korrigerende tiltak håndteres systematisk i styringssystemet for informasjonssikkerhet. De viser også at læringsfasen i standarden for hendelseshåndtering faktisk gjennomføres, ikke bare beskrives.

## Illustrerende eksempel

Eksempelet er konstruert for å vise metoden og beskriver ingen faktisk hendelse, kunde eller virksomhet.

En fiktiv virksomhet fikk en brukerkonto kompromittert gjennom phishing. Første tekniske spor av uvanlig innlogging fantes i identitetsloggene om morgenen, men første alarm kom først på ettermiddagen. Rekonstruksjonen viste at identitetskilden hadde hatt bortfall i flere timer. Varselet om bortfallet var sendt til en funksjonsadresse som ikke lenger ble fulgt opp. Deteksjonsgapet skyldtes dermed ikke manglende regler, men manglende data og en svikt i varslingskjeden.

Gjennomgangen ga tre tiltak. Det korrigerende tiltaket var å rette mottaker for varsler om bortfall, med kunden som eier. Det forebyggende tiltaket var å kontrollere varslingsmottakere for alle kilder hos samme kunde. Kapabilitetsforbedringen var en ny regel som slo alarm når en kritisk identitetskilde hadde vært taus utover en fastsatt terskel. Regelen ble verifisert ved en planlagt, kontrollert stans av kilden.

## Vanlige fallgruver

- Gjennomgangen gjennomføres så sent at deltakerne ikke husker begrunnelsene, eller at loggene er slettet.
- Tiltak formuleres som «øke bevisstheten» eller «følge opp», uten eier, frist og akseptkriterium.
- Tiltak lukkes når de er gjennomført, uten at effekten er påvist.
- Deteksjonsgap og responsgap slås sammen, slik at feil årsak blir behandlet.
- Gjennomgangen blir en vurdering av enkeltpersoners innsats i stedet for en vurdering av forholdene som styrte innsatsen.
