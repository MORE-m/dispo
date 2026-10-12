# Review: Calc↔Dispo-Isolationsbanner (PO-CALC-DISPO-HINT-1 Folgeslice)

Stand: 12. Oktober 2026  
Basis: `origin/main` @ `a76e24e87f40be059d7c57f1cac8757535016b7f`  
Branch: `feat/calc-dispo-isolation-banner`  
IDs: `DSP-003`, `PO-CALC-DISPO-LIFECYCLE-1` Option A, `PO-CALC-DISPO-HINT-1`

## Scope (erledigt)

- Verständliches Banner im Kalkulations-Wizard, sobald ≥1 Dispoauftrag aus der Calc existiert
- Singular-/Plural-Wortlaut laut Freigabe
- Dispo-Nummern mit Link zur Detailseite nur bei Dispo-Ansichtsrecht
- completed/cancelled nicht ausgeblendet
- Kein Banner vor dem ersten Dispo; kein Banner im Standardangebotsmodus
- Keine Lifecycle-/Sync-/Sperr-Änderung

## Nicht in diesem Slice

CRM-004, Stammdaten-Textsuche, Deploy, operative DB-Mutation, neue Methoden/SWF.

## Tests

- Pest: `CalcDispoIsolationBannerTest` (+ bestehende Isolation in `CreateDispoOrderFromCalculationTest`)
- Vitest: `calc-dispo-isolation-banner.test.tsx`
- E2E: Port **8065**, `playwright.calc-dispo-isolation-banner.config.ts` (`--retries=0`)
