# PO-CALC-TKP-OA003 – Closure (Online-Audio-Festpreis)

Stand: 12. November 2026  
Basis: `main` @ `573c1c330abc0ebeba4a38747cc6d90ee67d685f` (Merge #233)  
Status: **PO-CALC-TKP-OA003 – ABGESCHLOSSEN.** OA-003 Festpreis released im
bestehenden OA-TKP-v1-Vertrag.  
Vertrag: [`docs/entscheidungen/PO-CALC-TKP-OA003-fixed-price.md`](../entscheidungen/PO-CALC-TKP-OA003-fixed-price.md)  
Docs-only: keine Runtime-, Registry-, Migrations- oder Deploy-Änderung.

## 1. Gemergte Kette

| Slice | PR | Inhalt |
|---|---|---|
| OA003-PO | #229 | Vertrag (Docs-only) |
| OA003-1 | #230 | Settlement + Approval |
| OA003-2 | #231 | Backend (Resolver/Preview/Writer/Persistenz/Reload/Dispo) |
| OA003-3 | #232 | UI (`OaTkpPositionEditor`) |
| OA003-4 | #233 | E2E; Feature-HEAD `9600cef`, Merge-Commit `573c1c330abc0ebeba4a38747cc6d90ee67d685f` |

## 2. CI-Evidenz

Post-Merge-CI `43220059401` – **success**:

- `ci`
- `mysql`
- `e2e-spt008`
- D4d-Browser-Step: success
- OA003-4-Browser-Step: success

## 3. Registry (Code auf `main`)

```text
oa_tkp × tkp
pair_status = released
current_released_version = v1
versions.v1 = released
```

- Kein `oa_tkp × fixed_price`, kein v2.
- Spot-Methode `fixed_price` bleibt `planned`.
- `supportsFixedPriceSettlement(oa_tkp)` bleibt `false` (OA-eigener Resolver).

## 4. Vertragszusammenfassung (geschlossen)

| Thema | Festlegung |
|---|---|
| Settlement | `pricing_settlement_mode=fixed_price`, `fixed_price_nn` = N/N-Endbetrag |
| Formel | `effective_tkp = fixed_price_nn / impressions × 1000`; `basis_tkp = final_tkp = effective_tkp` |
| Freeze | `oa_tkp` / `tkp` / `v1` |
| Minimum | effective minimum = Preislisten-Minimum + Σ Targeting-Mindestaufschläge; Under-Min, wenn `final < effective minimum` |
| Targeting | nicht additiv zum N/N; `targeting_surcharge_total=0` bei Festpreis; Listenaufschläge → Referenz-`media_gross` |
| Beispiel Midroll+DMP | ref 105 / `media_gross` 10.500 / Festpreis 5.000 → final 50 / N/N 5.000 |
| +15 | kein Verkaufseffekt |
| Rabatte / AE | keine Forward-Rabatte; AE rückwärts; `nn_invest = fixed_price_nn` |
| Approval | immer `oa_tkp_fixed_price`; bei Under-Min zusätzlich `oa_tkp_basis_below_minimum` |
| Medien | fünf Core-Medien |
| Migration / Deploy | keine / kein |

## 5. E2E-Isolation

- `APP_ENV=testing`, SQLite, `DB_URL=''`
- `database/e2e-po-calc-tkp-oa003-release-smoke.sqlite`
- Port 8078, Guard gegen `dispo_mat_core`
- `dispo_mat_core` unberührt

Details: `docs/readiness/PO-CALC-TKP-OA003-4-E2E-2026-11-12.md`.

## 6. Dev-DB Read-only-Sanity (Hinweis)

- `2026.1`: active, 41 Items (genau 1 active 2026)
- `2027.1`: active, 41 Items (genau 1 active 2027)
- Midroll 80/20
- keine unerwartete OA-Preismutation

## 7. Weiter OUT / offen (nicht erledigt)

- `native_ad`
- Budget
- Standardangebote
- Plattform-UI / Endnutzer-Plattformwahl
- OA-005 / OA-006 Allocation
- interne 15-EUR-Kosten-/Margenabbildung

## 8. Historie

Frühere Dokumente und Log-Zeilen, die OA-003 als OUT oder „erst nach Merge final“
führten, bleiben unverändert als Historie; maßgeblich ist der Abschluss vom
12.11.2026.
