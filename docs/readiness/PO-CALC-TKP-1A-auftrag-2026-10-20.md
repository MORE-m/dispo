# PO-CALC-TKP-1A – OA/TKP-Stammdatenfundament

Status: **umgesetzt auf Feature-Branch** (Draft-PR; kein Merge/Deploy)  
Stand: 20. Oktober 2026  
Basis: `origin/main` @ `70494cfd770b2c77bf6f4c961da3e849de8e7294`

## Ergebnis

Technisches Stammdatenfundament für den späteren TKP-Kern:

| Objekt | Zweck |
|---|---|
| `oa_platforms` | Plattformcodes (Mengenverteilung) |
| `oa_tkp_price_lists` + `oa_tkp_price_list_items` | Listen-/Mindest-TKP je Jahr/Revision × Medium × Inventar |
| `oa_targeting_definitions` | Targeting-Katalog mit absolutem `tkp_surcharge` |
| `oa_adserver_surcharges` | konfigurierbarer Adserver-TKP (Scope JSON; ohne operativen Seed) |

Kategorie-Assignment:

`online_audio` → Method `tkp` / Profile `oa_tkp`

Registry:

`oa_tkp` × `tkp` = **planned** (keine Version, kein Release)

Import-Grundlage: `App\Services\OnlineAudio\Import\*` (Fixtures erlaubt, keine produktiven Fake-Seeds).

## Bewusst OUT

- TKP Calculation Engine / Preview / Save / Wizard / Bookability
- OA-Festpreis / Approval / Dispo / Admin-UI
- Native / Online-SWF / Social
- Method Release
- Plattformdimension im Preis-Key (`FD-OA-PLAT` offen)
- operative Listen-/Mindest-TKP-, Plattform-, Targeting-Werte

## Offene FDs (unverändert)

- `FD-OA-FEST`
- `FD-OA-FEST-NN`
- `FD-OA-ADSERVER`
- `FD-OA-PLAT`
- `FD-NATIVE`

## Nächster Schritt

Externe Datenlieferung + ggf. FD-Klärung; danach Phase B Engine (erst dann Release).
