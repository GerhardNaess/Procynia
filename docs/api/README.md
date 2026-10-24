# Procynia API – OpenAPI-spesifikasjonen

[`openapi.yaml`](openapi.yaml) er kontrakten for det eksterne API-et, i OpenAPI 3.1. Den beskriver API-et slik det er implementert, ikke slik det er planlagt. Status og plan står i [`../external-api-coverage-status.md`](../external-api-coverage-status.md), arkitekturen i [`../external-api-architecture.md`](../external-api-architecture.md).

Spesifikasjonen er skrevet for hånd og versjoneres sammen med koden. Den genereres ikke fra koden.

## Hva som er kilden

| I spesifikasjonen | Kilde i koden |
|---|---|
| Stier og metoder | `routes/api.php` |
| Middleware-rekkefølge, tilgang og 403/404 | `bootstrap/app.php`, `app/Http/Middleware/Api/`, tilgangstjenestene (`RiskAccessService` m.fl.) |
| Spørreparametere, grenser og validering | `$request->validate()` i `app/Http/Controllers/Api/V1/` |
| Svarfelter | Presentasjonsklassene i `app/Services/Api/Presenters/` og `MeController` / `RiskTreatmentActionController` |
| Konvolutt (`data`, `meta`) | `ApiResponse` |
| Feilformat og feilkoder | `ApiErrorResponder` |
| Paginering | `ApiCursor` |
| Token og app-roller | `EntraAccessTokenValidator`, `RequireApiWriteRole`, `config/procynia.php` → `api` |
| Skriveoperasjoner | `ApiOperationCatalog` |

## Automatisk kontroll

`tests/Feature/Api/ApiOpenApiContractTest.php` kjører med resten av API-testene og feiler når:

- en API-rute mangler i spesifikasjonen, eller spesifikasjonen har en sti eller metode som ikke finnes;
- en operasjon mangler `Procynia-Customer`, en skriverute mangler `Procynia.Write.All`, eller en leserute krever den;
- en operasjon i `ApiOperationCatalog` ikke er nevnt;
- en enum (status, enhet, oppmerksomhetskode, feilkode m.fl.) avviker fra konstantene i koden.

Testen leser filen linje for linje, fordi prosjektet ikke har en YAML-parser. Den forutsetter derfor denne layouten:

- stier på to mellomrom (`  /v1/risks:`), metoder på fire (`    get:`);
- skjemaer på fire mellomrom under `components.schemas`;
- hver enum på én linje: `enum: [a, b, c]`.

Testen dekker ikke feltlistene i svarene. Feltene er låst av `assertExactJsonStructure` i lesetestene (`ApiRiskReadTest`, `ApiDeviationReadTest`, `ApiObjectiveKpiReadTest`) og av `assertExactJson` i `ApiCustomerGrantTest` og `ApiRiskTreatmentActionCreateTest`. Når de endres, må spesifikasjonen endres for hånd.

```bash
docker exec procynia-app php artisan test --filter='ApiOpenApiContractTest|ApiRouteContractTest'
```

## Validering mot OpenAPI 3.1

Spesifikasjonen valideres med Redocly CLI. Kommandoen kjøres i et Docker-bilde, så verktøyet blir ikke en avhengighet i prosjektet:

```bash
docker run --rm -v "$PWD/docs/api:/spec:ro" -w /tmp node:22 \
  npx -y @redocly/cli@1.34.5 lint /spec/openapi.yaml
```

Forventet resultat: gyldig, med én advarsel (`info-license`). Lisensvilkårene for API-et er ikke bestemt, og spesifikasjonen sier derfor ikke noe om dem.

## Når et nytt endepunkt utvikles

1. Legg stien og metoden under `paths`, med `operationId`, `tags`, `Procynia-Customer` og alle svarkodene operasjonen faktisk kan gi.
2. Beskriv svarfeltene som et gjenbrukbart skjema under `components.schemas`. Bare feltene presentasjonsklassen sender, med samme navn og samme nullbarhet. Fritekst, filer, interne felt og webruter skal heller ikke stå i spesifikasjonen.
3. Gjenbruk de felles delene: `Limit`, `Cursor`, `BusinessAreaId`, `PageMeta`, `Meta`, `Reference`, `Person`, `Timestamp`, `Decimal` og feilsvarene under `components.responses`.
4. Skriv nye enums på én linje og legg dem til i `test_the_enums_are_the_codes_own_values`, med konstanten de kommer fra.
5. For en skriveoperasjon: krev `[Procynia.Read.All, Procynia.Write.All]` under `security`, nevn operasjonsnavnet fra `ApiOperationCatalog` i beskrivelsen, og beskriv rekkefølgen kontrollene skjer i. Si eksplisitt om operasjonen er idempotent.
6. Kjør kontrakttesten og Redocly-valideringen over.
7. Oppdater `info.version` når kontrakten endres. Ny valgfri funksjonalitet gir en ny mindre versjon (1.1.0). Endringer som bryter med eksisterende klienter hører til en ny API-versjon (`/v2`).
8. Oppdater [`../external-api-coverage-status.md`](../external-api-coverage-status.md) etter reglene der.
