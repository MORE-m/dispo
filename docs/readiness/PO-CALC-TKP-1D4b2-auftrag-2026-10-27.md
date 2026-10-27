# PO-CALC-TKP-1D4b-2 – OA-TKP HTTP Create/Update Release Contract

Stand: 27. Oktober 2026 (Feature-Branch / Draft-PR)  
Base: `825542a6f0670894e866740d1e6d1bd9bcbb19fb` (D4b-1 auf `main`, PR #191)

## Ziel

Positive HTTP-Integrationsabsicherung: reguläre OA-TKP-Kalkulationen können über die
bestehenden autorisierten Controller (`calculations.store` / `calculations.update`)
erstellt und bearbeitet werden, **sobald** die Registry später freigegeben wird.

Der Release-Zustand wird **ausschließlich** in isolierten Tests über den D4b-1-Fake
`OaTkpWizardCapability::fakeHttpMutationAllowedForTests` simuliert.

## Vertrag

| Aspekt | Status nach D4b-2 |
|---|---|
| Positive HTTP Persistenz | **testseitig** abgesichert (Fake-Release) |
| Produktive HTTP Persistenz | **geschlossen** (Registry `planned`) |
| Registry-Katalog | unverändert `planned` / `current_released_version=null` / `v1=implemented` |
| Echte Preislisten / Plattformstammdaten | fehlen weiterhin (nur Test-Fixtures) |
| D4d | erst nach separater PO-Freigabe |
| Reporting | nachgelagert |

### Isolation

- Kein produktiver Bypass, kein Request-/Header-Schalter, kein `dispo_mat_core`-Schreibzugriff.
- Fake nur bei `runningUnitTests`; `tearDown` setzt zurück.
- HTTP ohne Fake → weiterhin 422 (`OaTkpHttpReleaseGuard::MESSAGE`).

### Abgedeckte HTTP-Pfade

- `POST calculations.store` / `PUT calculations.update`
- Create: Engine/Methode, Impressions, Plattform-Allokation, Basis-/Listen-/Mindest-/Final-TKP,
  Targeting-Aufschläge, Geldbeträge, Preislistenidentität, Snapshots, keine Spotproduktion,
  Client-Preisoverrides ignoriert / `engine_profile_key` prohibited.
- Update: Repricing, Lock (`lock_version` → 422), Expected-Token bei Rebind → 409,
  Allokations-Rollback, Pinning unverändert bei gleichem Medium/Inventar/Jahr.
- Method-Switch Spot↔OA auf bestehender Position → 422 (Vertrag unverändert).
- Mixed Spot+OA in einer Kalkulation.
- 403 Disposition; fehlende/mehrdeutige Preisliste; fehlende Plattform; `native_ad`;
  Budget-Propose und Standardangebote-TKP gesperrt.

## OUT

- Produktive Registry-Freigabe / Catalog-Flip (D4d)
- Preislistenimport / produktive Stammdaten
- LiveBookability-Änderung
- Budget-/SO-TKP-Freigabe
- Neue E2E-Infrastruktur
- Reporting

## Tests

`tests/Feature/OnlineAudio/PoCalcTkp1d4b2HttpCreateUpdateTest.php`

## Statuszeile

> D4b-2: Positive HTTP Create/Update testseitig abgesichert (Fake-Release).
> Produktive Registry planned; HTTP Store/Update geschlossen; D4d offen.
