# Avviksregister

## Formål

Avviksregisteret i Procynia er den operative kilden til sannhet for sikkerhetsfunn, tekniske forbedringer, driftstiltak og V1-oppfølging. Registeret skal brukes i adminpanelet, ikke i Word-rapporter eller chat.

## Statuser

- `Ny` - avviket er registrert, men ikke prioritert enda.
- `Planlagt` - avviket er satt på plan og venter gjennomføring.
- `Pågår` - avviket er under arbeid.
- `Klar for verifisering` - tiltaket er implementert og venter kontroll.
- `Verifisert` - tiltaket er kontrollert, men ikke lukket ennå.
- `Lukket` - avviket er ferdigstilt og avsluttet.
- `Utsatt` - avviket er midlertidig satt på pause.

## Når et avvik kan lukkes

Et avvik skal først lukkes når:

- tiltaket er implementert
- commit-hash er fylt inn
- verifiseringsnotat er fylt inn
- verifiseringen er gjennomført og dokumentert

Sikkerhetsavvik skal ikke lukkes før tiltaket er verifisert.

## Sporbarhet

Feltet `commit_hash` skal brukes til å vise hvilken endring som løste avviket. Feltet `verification_notes` skal beskrive hvordan løsningen ble kontrollert.

## Praktisk bruk

- Opprett avvik når noe bør følges opp operativt.
- Sett ansvarlig bruker hvis noen skal eie oppfølgingen.
- Flytt status etter hvert som arbeidet går fremover.
- Lukk avviket når tiltaket er verifisert og registrert.

## Kildegrunnlag

Word-rapporter og chat kan brukes som bakgrunn, men de er ikke styrende register. Filament-modulen under `Drift -> Avvik og forbedringer` er den styrende registreringen.
