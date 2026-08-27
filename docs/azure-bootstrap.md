# Procynia — Azure tenant og subscription bootstrap

Hvordan vi kommer fra «ingen Azure» til «en subscription vi kan validere IaC mot».

Ingen reelle ID-er i denne filen. Alt som ser ut som `<...>` er en plassholder du fyller inn lokalt.

Etter denne siden: [`docs/azure-staging-runbook.md`](azure-staging-runbook.md).

---

## 1. Tenant

En **tenant** er Entra ID-katalogen — identitetene. En **subscription** er faktureringsgrensen —
ressursene. De er to forskjellige ting, og en tenant uten subscription kan ikke hoste noe.

Har Advania allerede en Entra ID-tenant (det har de, hvis de bruker Microsoft 365), **skal Procynia
ligge i den**. Ikke opprett en ny tenant for dette.

Sjekk om det finnes en:

```bash
az login
az account tenant list -o table       # eller: az account show --query tenantId -o tsv
```

### Virksomhetskonto, ikke personlig

Procynia skal ligge under virksomhetens tenant, ikke under en privat Microsoft-konto. Grunnene er
praktiske, ikke formelle:

- **Eierskap følger selskapet, ikke en person.** En personlig konto gjør én ansatt til
  single point of failure for hele produksjonsmiljøet.
- **Fakturering går gjennom selskapets avtale.** En personlig konto betyr privat kredittkort og
  manuell viderefakturering.
- **Tilgangsstyring finnes.** Grupper, PIM, betinget tilgang og revisjonslogg forutsetter en
  virksomhetstenant.
- **Kunder kommer til å spørre.** Procynia behandler anbudsdokumenter; «hvem eier miljøet» er et
  spørsmål vi må kunne svare konkret på.
- **Offboarding.** Når en ansatt slutter, skal ikke miljøet følge med ut.

Trengs likevel en ny tenant (f.eks. bevisst separat fra konsernets): opprett den via
[entra.microsoft.com](https://entra.microsoft.com) → Manage tenants → Create, og bruk et domene du
faktisk kontrollerer.

**Noter:** `TENANT_ID = <tenant-guid>`

---

## 2. Subscription

### Alternativ A — bruk en eksisterende (foretrukket hvis den finnes)

```bash
az account list --query '[].{name:name, id:id, state:state, tenantId:tenantId}' -o table
```

Trenger du en egen subscription for Procynia? Ja, hvis den eksisterende deles med urelatert drift.
Kostnadssporing, tilgangsstyring og opprydding blir alle enklere med en egen.

### Alternativ B — opprett ny

Portal → Subscriptions → Add. Krever en aktiv billing account og rollen *Billing account
contributor* eller tilsvarende på avtalen.

Avtaletypen bestemmer hva som er mulig:

| Avtale | Kommentar |
|---|---|
| Microsoft Customer Agreement (MCA) | Vanligst for virksomheter i dag. Subscriptions opprettes under en *billing profile* → *invoice section*. |
| Enterprise Agreement (EA) | Subscriptions opprettes under en *enrollment account*. |
| Cloud Solution Provider (CSP) | Partneren oppretter den for dere. |
| Pay-As-You-Go | Fungerer, men få styringsmuligheter. Ikke anbefalt for produksjon. |

Har Advania en partneravtale, er CSP eller MCA gjennom partneren som regel raskeste vei.

### Billing

```bash
az billing account list -o table                       # krever billing-rettigheter
az account show --query '{name:name, id:id, state:state}' -o table
```

`state` skal være **`Enabled`**. Er den `Warned`, `PastDue` eller `Disabled`, vil deployment feile på
måter som ser ut som tekniske feil — sjekk dette først.

**Noter:** `SUBSCRIPTION_ID = <subscription-guid>`

---

## 3. Staging og production skal være atskilt

Anbefalingen, i prioritert rekkefølge:

1. **To subscriptions** — `Procynia-Staging` og `Procynia-Production`. Sterkeste isolasjon: separat
   fakturering, separate kvoter, og et uhell i staging kan ikke nå produksjon. Dette er anbefalingen.
2. **Én subscription, to resource groups** — `rg-procynia-staging-norwayeast` og
   `rg-procynia-production-norwayeast`. Fungerer, og er det IaC-en støtter i dag. Svakere isolasjon:
   felles kvote, og en for bred rolletildeling treffer begge.

IaC-en fungerer med begge: `deploy.sh` tar environment som argument, og `PROCYNIA_SUBSCRIPTION` velger
subscription. Med to subscriptions setter du bare den variabelen forskjellig.

Uansett modell: **ingen automatisk promotering fra staging til production.** Production deployes
eksplisitt, med sin egen `--apply`.

---

## 4. Region

Default er `norwayeast`, satt i begge `.bicepparam` via `PROCYNIA_AZURE_LOCATION`.

Hvorfor Norway East: datalokalitet i Norge for anbudsdokumenter, lav latens for norske brukere, og
tilgjengelighetssoner (som production-PostgreSQL bruker med `ZoneRedundant`).

**Ikke anta at alt finnes der.** Norway East er en mindre region enn West Europe, og enkelte tjenester
eller SKU-er ruller ut senere. Det er nettopp derfor
`scripts/azure-bootstrap/verify-azure-prerequisites.sh` finnes — den sjekker faktisk tilgjengelighet
mot din subscription i stedet for å stole på dokumentasjon.

Bytte region senere er en full ny deployment, ikke en parameterendring i praksis: globalt unike navn
og data må flyttes. Ta valget nå.

```bash
export PROCYNIA_AZURE_LOCATION=norwayeast     # eller westeurope
```

---

## 5. Rettigheter

Bicep-en oppretter rolletildelinger (AcrPull, Key Vault Secrets User, Storage-dataroller). Det krever
mer enn Contributor:

- **Owner** på subscription, eller
- **Contributor** + **User Access Administrator**

Kun Contributor gir en deployment som feiler halvveis — etter at ACR og Key Vault allerede er
opprettet.

---

## 6. Verktøy

```bash
brew install azure-cli          # macOS
az bicep install
az version
```

Sjekk konteksten før noe annet:

```bash
export PROCYNIA_SUBSCRIPTION="<subscription-id>"
export PROCYNIA_AZURE_LOCATION=norwayeast

./scripts/azure-bootstrap/check-azure-context.sh
```

Scriptet er read-only, og **velger aldri subscription for deg**. Ser den mer enn én og
`PROCYNIA_SUBSCRIPTION` ikke er satt, stopper den og lister dem. Å velge «den første» er hvordan
stagingarbeid havner i en produksjonssubscription.

Det sjekker: CLI og Bicep installert · innlogget · subscription valgt og `Enabled` · regionen
tilgjengelig · ni resource providers registrert · at du har rettighetene rolletildelingene krever.

---

## 7. Rekkefølge derfra

```bash
# 1. Kontekst — read-only
./scripts/azure-bootstrap/check-azure-context.sh

# 2. Tjenester og SKU-er faktisk tilgjengelige i regionen — read-only
./scripts/azure-bootstrap/verify-azure-prerequisites.sh

# 3. Bicep kompilerer, uten Azure
./infra/validate.sh

# 4. Azure-side validate + what-if — endrer ingenting
export PROCYNIA_PG_ADMIN_PASSWORD='<sterkt passord>'
./infra/deploy.sh staging

# 5. Les hele what-if-outputen. Deretter GO/NO-GO i runbooken.
```

Steg 4 krever at resource group finnes — Azure kan ikke kjøre what-if mot en gruppe som ikke
eksisterer. `deploy.sh` oppretter den **ikke** uten `--apply`, og sier tydelig fra. Å opprette en tom
resource group er i seg selv gratis og reversibelt (`az group delete`), men gjør det bevisst, ikke
som en bieffekt.

---

## 8. Verdier du må notere lokalt

Ikke i dette repoet.

| | Hvor den brukes |
|---|---|
| `TENANT_ID` | Feilsøking, `az login --tenant` |
| `SUBSCRIPTION_ID` | `PROCYNIA_SUBSCRIPTION` |
| Billing account / invoice section | Kostnadsoppfølging |
| Region | `PROCYNIA_AZURE_LOCATION` |
| PostgreSQL admin-passord | `PROCYNIA_PG_ADMIN_PASSWORD`, og lagres i Key Vault av deploymentet |

Secrets som må finnes før workloads deployes: se
[`docs/azure-runtime-contract.md`](azure-runtime-contract.md) §2 og GO/NO-GO-seksjonen i runbooken.
