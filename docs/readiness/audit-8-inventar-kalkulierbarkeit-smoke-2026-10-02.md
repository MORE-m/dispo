# Readiness: Isolierter 8-Inventar-Kalkulierbarkeits-Smoke

Stand: 3. Oktober 2026
Basis: `d7896aaf8e633f1e977a03e79af43290ac7c6ec3` (nach PR #114)
Test-PR: [#115](https://github.com/MORE-m/dispo/pull/115) (Draft, **nicht** auf `main`)
Test-HEAD: [`e6d4a8123d89751144c962ce3dc074c292458bd6`](https://github.com/MORE-m/dispo/commit/e6d4a8123d89751144c962ce3dc074c292458bd6)
Branch: `test/eight-inventory-calculability-smoke` (nur Tests/Fixture)

## Urteil

**READY** = Vorbedingungen für den isolierten Smoke sind erfüllt
(Fixture + Guard + SQLite `:memory:` möglich).

Das ist **nicht** dasselbe wie „Smoke bereits auf `main` integriert“ oder
„Produktdaten/Workbook-Preise abgenommen“. Umsetzung und lokaler Testlauf
liegen auf dem offenen Test-Branch / Draft-PR #115.

## Vorbedingungen (Readiness)

- Isolierte Fixture `CreatesEightSpotInventoryCalculabilityCatalog`
- Pest unter SQLite `:memory:`
- `MysqlTestDatabaseGuard` blockiert `dispo` / jede Nicht-`dispo_test`-MySQL-DB
  (inkl. `dispo_mat_core`)
- Port 8000 / Dev-Storage unberührt

## Was der Smoke prüft (Fixture / Backend)

- Acht MORE-Spot-Inventare mit aktiver 2026-Liste und **deterministischen Fixture-Preisen**
- Nachgebildetes Abdeckungsmuster 0–23 × mo_fr/sa/so (72/42/57; Summe 501/75 = Muster, keine Import-Validierung)
- Fehlende Zellen fail-closed, keine Nullpreise
- MAT-003: kein `spot_single` auf Kombi+, ffn, Bollerwagen (Negativtest)
- Jahr ohne aktive Liste fail-closed ohne Fallback (Testzeit auf 2026 eingefroren)
- Preview → Store → Reload; kanonisch `savedSummary.media_gross` verbindlich;
  Preislisten-ID, Stundenbereich, Day Group, Spotanzahl gegen Payload
- Wizard-Create-Props: Backend stellt `catalog.inventories` / `media` / `rules` bereit

## Abdeckungsgrenzen

- Average + `spot_classic` (kein Calendar-/Festpreis-Method-Key)
- **Kein** neuer Browser-Klickpfad / Playwright
- **Keine** Validierung realer Workbook-Preiswerte durch diese Fixture
  (Importer-Belege separat: `MoreSpotkalkulationMatCoreImportTest`,
  `MoreSpotkalkulationWorkbookParserTest`)
- Ergebnisbeträge (z. B. 300/330 €) = ausschließlich Fixture-Erwartungen
- Kein vollständiger MAT-Medienkatalog je Inventar (nur Classic + Single für Ausschluss)
- Keine Mutation von Dev-DBs; kein Preisimport-Artisan im Smoke
- Rechte-Soll vs. Ist bleibt **offene fachliche Entscheidung** (keine Policy-Änderung hier)

## DoD

- [x] Acht Inventare in isolierter Fixture
- [x] Fail-closed fehlende Stunde
- [x] MAT-003 Single-Ausschluss
- [x] Jahr ohne Liste
- [x] Lokale Pest-Suite grün ohne `dispo_mat_core`
- [ ] CI `ci` / `mysql` / `e2e-spt008` auf finalem Test-HEAD (PR #115)
- [ ] Test-PR auf `main` integriert (noch Draft / offen)
- [ ] Docs-Merge separat (PR #116)
