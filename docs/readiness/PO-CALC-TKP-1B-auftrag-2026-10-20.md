# PO-CALC-TKP-1B – Normal-TKP-Engine

Status: **umgesetzt auf Feature-Branch** (Draft-PR; Nachzug HOCH-Findings; kein Merge/Deploy)  
Stand: 20. Oktober 2026  
Basis: `origin/main` @ `3785abc32ba8f86881c199954b4bb8bdd7b2c203`

## Ergebnis

Technische Normal-TKP-Engine für die fünf Kernmedien:

| Baustein | Zweck |
|---|---|
| `OaTkpNormalEngine` | `final_tkp = basis + Σ targeting`; `invest = impressions/1000 × final` |
| `OaTkpPriceResolver` | aktive Revision Jahr × Medium × Inventar; fail-closed 0/>1 active |
| `OaTkpCalculationPersister` | interner Preview/Save/Reload inkl. Snapshot |
| Persistenz | Impressions, TKP-Snapshots, Platform-Allocations, Targeting-Selections |

Nachzug (Abnahme): Plattformverteilung Pflicht (OA-006, Allocation >0, Unique Codes);
Targeting Unique; AE nur bei `ae_enabled ∧ is_ae_eligible`; Rabattzeilen über
`CalculationPositionDiscount` / `CalculationOrderDiscount`.

Registry:

`oa_tkp` × `tkp` = **planned**, `current_released_version = null`, Version `v1` = **implemented**

HTTP Preview/Wizard/Bookability bleiben fail-closed.

## Bewusst OUT

- OA-Festpreis / Adserver-Runtime / Approval / Dispo / Admin / Wizard
- Method Release / Native / operative Fake-Preise / Deploy

## Offene FDs

- `FD-OA-FEST`, `FD-OA-FEST-NN`, `FD-OA-ADSERVER`, `FD-OA-PLAT`, `FD-NATIVE`

## Nächster Schritt

Echtdaten / Abnahme; Release und Nutzerfreigabe erst in späteren Slices.
