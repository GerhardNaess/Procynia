# AI Usage Guard

## Formål
Procynia bruker tekniske sikkerhetsgrenser for å hindre ukontrollert AI-bruk før en AI-operasjon starter. Dette er en sikkerhetsbrems, ikke full AI-credit-måling og ikke fakturering.

## Konfigurasjon
Følgende `.env`-variabler styrer grensene:
- `AI_RATE_LIMIT_USER_PER_MINUTE`
- `AI_RATE_LIMIT_CUSTOMER_PER_HOUR`
- `AI_RATE_LIMIT_USER_DECAY_SECONDS`
- `AI_RATE_LIMIT_CUSTOMER_DECAY_SECONDS`

Standardverdiene ligger i `config/procynia.php`.

## Hva grensene betyr
- Brukergrensen beskytter mot for mange AI-kjøringer fra én bruker i løpet av ett minutt.
- Kundegrensen beskytter mot for mange AI-kjøringer samlet for én kunde i løpet av en time.
- Grenser og tidsvinduer skal justeres etter faktisk bruk over tid.

## Usage events
Procynia lagrer ikke-sensitive `ai_usage_events` for tillatte og blokkerte AI-operasjoner.

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

## Intern AI-bruk og kapasitet
Filament-admin har nå en intern side under `Fakturering` med navnet `AI-bruk og kapasitet`.
Den viser aggregert AI-bruk per kunde og per bruker, samt tillatte og blokkerte operasjoner fordelt på `ai_usage_events`.

Visningen bruker kundens inkluderte AI-kapasitet når den er tydelig definert i kunde- eller plan-data.
Hvis kapasitet ikke er definert, vises dette eksplisitt som `Ikke definert` i stedet for at systemet gjetter.

Dette er intern styring og kapasitetsoppfølging, ikke fakturagrunnlag og ikke Stripe usage billing.

## AVVIK-010
Full AI-credit- og kapasitetsmåling hører til AVVIK-010. Denne delen av systemet viser faktisk AI-bruk mot tilgjengelig definert kapasitet der dette finnes, og viser ellers tydelig når kapasitet mangler definisjon. Systemet lagrer fortsatt kun trygge bruksdata som grunnlag for senere vurdering.
