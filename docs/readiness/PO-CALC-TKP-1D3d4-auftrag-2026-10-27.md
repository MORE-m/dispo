# PO-CALC-TKP-1D3d-4 – OA Playwright End-to-End Smoke

Stand: 27. Oktober 2026 (Feature-Branch / Draft-PR)  
Base: `23e9f0c775bb52f46e4ded343987b64e9c3b9a07` (D3d-3a auf `main`, PR #189)

## Ziel

Dauerhaft eingecheckter, reproduzierbarer Playwright-Smoke für den bestehenden
Online-Audio-TKP-Dispo-Workflow. Keine neuen Produktfunktionen, kein D4-Release.

## Testumgebung

| Aspekt | Wert |
|--------|------|
| Config | `playwright.po-calc-tkp-d3d4.config.ts` |
| Port | **8073** |
| DB | isolierte SQLite `database/e2e-po-calc-tkp-d3d4.sqlite` |
| Seeder | `E2EOaTkpDispoSeeder` |
| Spec | `tests/e2e/po-calc-tkp-d3d4-oa-smoke.spec.ts` |
| npm | `npm run test:e2e:po-calc-tkp-d3d4` |
| CI | Step im `ci`-Job (separat von `e2e-spt008`) |

Testdaten: persistierte OA-Kalkulationen nur über `CalculationWriter` +
`DispoOrderWriter` im Seeder. Kein produktiver HTTP-Bypass, keine Registry-
Manipulation, keine Nutzung von `dispo_mat_core` / Dev-DB.

## Abdeckung

1. Fixture-Persistenz + Registry `oa_tkp`/`tkp` = planned
2. HTTP `POST /kalkulationen` mit OA-TKP → 422 („noch nicht freigegeben“)
3. UI-Handoff aus gespeicherter OA-Position; Snapshot; keine Spotproduktion
4. Ops-Flight fehlt → `disposed` blockiert
5. „Aus Kalkulation übernehmen“ ohne Auto-Save; Save; Reload; Snapshot unverändert
6. `disposed` nach gültigem Flight; Completion-Check `oa_operational_flight` ✓
7. Material-Upload (Audio-Motiv) ohne Status-/Snapshot-Änderung
8. Mixed Spot+OA: Flight nur an OA; Gate; danach disposed
9. Vertrieb: Flight read-only; Disposition: 409 bei stale `lock_version`

## Release Protection

- Registry `oa_tkp` × `tkp` bleibt `planned`, `current_released_version = null`
- HTTP Calculation Store/Update geschlossen
- Keine Budget-/Standard-Offers-TKP-Freigabe
- Kein D4

## OUT

- Produktcode-Änderungen / Bypass-Pfade
- D4 Registry/HTTP Release
- Vollständiger `completed`-Zwang, wenn andere Completion-Checks offen bleiben
- Separater CI-Job (bewusst im `ci`-Job wie andere isolierte Smokes)

## Statuszeile

> D3d-4 implementiert: eingecheckter OA-Playwright-Smoke läuft reproduzierbar
> auf isolierter SQLite/Port 8073. Registry planned; HTTP Store geschlossen; D4 offen.
