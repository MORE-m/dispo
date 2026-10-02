# Readiness: Isolierter 8-Inventar-Kalkulierbarkeits-Smoke

Stand: 2. Oktober 2026  
Basis: `d7896aaf8e633f1e977a03e79af43290ac7c6ec3` (nach PR #114)  
Implementierung: Branch `test/eight-inventory-calculability-smoke` (nur Tests/Fixture)

## Urteil

**READY**

Vorbedingungen „isolierte Test-DB/Fixture“ sind erfüllt:
`CreatesEightSpotInventoryCalculabilityCatalog` + Pest unter SQLite `:memory:`;
`MysqlTestDatabaseGuard` blockiert `dispo` / jede Nicht-`dispo_test`-MySQL-DB
(inkl. `dispo_mat_core`). Port 8000 / Dev-Storage unberührt.

## Anforderungen (erfüllt im Smoke)

- Acht MORE-Spot-Inventare mit aktiver 2026-Liste und deterministischen Preisen
- Import-Raster 0–23 × mo_fr/sa/so; sparse Patterns wie PRI-OPS-1 (72/42/57)
- Fehlende Zellen fail-closed, keine Nullpreise
- MAT-003: kein `spot_single` auf Kombi+, ffn, Bollerwagen (Negativtest)
- Jahr ohne aktive Liste fail-closed ohne Fallback
- Preview → Store → Reload je Inventar; Erwartung aus Fixture-Preis abgeleitet

## Codepfade unter Test

Wizard-Create-Props (`catalog.inventories` / `media` / `rules`),
`calculations.preview` / `store` / `edit`, `CatalogResolver` fail-closed,
MAT-Regel „Kombination nicht zulässig“, `PriceListYearSelection` Missing-Year.

## Abdeckungsgrenzen

- Average + `spot_classic` (kein Calendar-/Festpreis-Method-Key, kein Playwright)
- Kein vollständiger MAT-Medienkatalog je Inventar (nur Classic + Single für Ausschluss)
- Keine Mutation von Dev-DBs; kein Preisimport-Artisan im Smoke

## DoD

- [x] Acht Inventare in isolierter Fixture
- [x] Fail-closed fehlende Stunde
- [x] MAT-003 Single-Ausschluss
- [x] Jahr ohne Liste
- [x] Lokale Pest-Suite grün ohne `dispo_mat_core`
- [ ] CI auf finalem Test-HEAD (PR)
- [ ] Docs-Merge separat
