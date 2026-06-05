# AI Usage Guard

## Formål
Procynia logger AI-operasjoner for intern innsikt og varsler om uvanlig høyt tempo før en AI-operasjon starter. Dette er ikke kommersiell stopp, full AI-credit-måling eller fakturering.

## Konfigurasjon
Følgende `.env`-variabler styrer grensene:
- `AI_RATE_LIMIT_USER_PER_MINUTE`
- `AI_RATE_LIMIT_USER_DECAY_SECONDS`
- `AI_RATE_LIMIT_CUSTOMER_DECAY_SECONDS`

`AI_RATE_LIMIT_CUSTOMER_PER_HOUR` er deprecated og brukes ikke lenger som en stoppmekanisme.

Standardverdiene ligger i `config/procynia.php`.

## Hva grensene betyr
- Brukergrensen varsler når en bruker kjører mer enn fem AI-operasjoner i løpet av ett minutt. Operasjonen fortsetter.
- Kunde-/timeverdien er ikke lenger en stoppmekanisme. Den gamle modellen med operasjonsstopp er avviklet.
- Grenser og tidsvinduer skal vurderes ut fra faktisk bruksmønster over tid, ikke som økonomisk styring.

## Usage events
Procynia lagrer ikke-sensitive `ai_usage_events` for tillatte AI-operasjoner og historiske blokkeringer fra eldre data.

Feltet lagrer kun:
- kunde
- bruker
- operation key
- status
- limit type
- operation count

Følgende lagres ikke:
- prompt
- dokumenttekst
- kravtekst
- svartekst
- chunkinnhold
- API-nøkler

## Intern AI-bruk og varsler
Filament-admin har nå en intern side under `Drift` med navnet `AI-bruksmønster og varsler`.
Den viser aggregert AI-bruk per kunde og per bruker, samt høy aktivitet, varsler og historiske blokkeringer fordelt på `ai_usage_events`.
Siden brukes til drift og observasjon, ikke økonomi.

Visningen bruker kundens inkluderte AI-referanseverdi når den er tydelig definert i kunde- eller plan-data, men den er en intern oversikt over bruksmønster og historiske varsler, ikke økonomi.
Hvis referanseverdi ikke er definert, vises dette eksplisitt som `Ikke definert` i stedet for at systemet gjetter.

Dette er intern styring og bruksmønsteroppfølging, ikke fakturagrunnlag og ikke Stripe usage billing.

## AVVIK-010
Full AI-credit- og bruksmønsteroppfølging hører til AVVIK-010. Denne delen av systemet viser faktisk AI-bruk, høy aktivitet og historiske varsler. Systemet lagrer fortsatt kun trygge bruksdata som grunnlag for senere vurdering.
