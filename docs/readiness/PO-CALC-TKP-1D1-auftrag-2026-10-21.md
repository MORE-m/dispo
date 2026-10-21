# PO-CALC-TKP-1D1 – Writer/Persistence Integration (Auftrag)

Stand: 21. Oktober 2026  
Base: `7a223d76776e595ad861a0ce6cf4cc2969d6603a`  
Branch: `feat/po-calc-tkp-1d1-writer-integration`

## Ziel

Normal-TKP (`oa_tkp` × `tkp`) in den gemeinsamen `CalculationWriter` integrieren
(Variante A). Registry bleibt **planned**, HTTP bleibt fail-closed.

## IN (umgesetzt)

- Dispatch im Writer über Methode/Freeze (`calculation_method_key`/`spot_method = tkp`
  bzw. unveränderter OA-Freeze) — kein Medium-Code-Branching
- `OaTkpWriterPositionResolver`:
  - Berechtigung über aktive Medium-/Kategorie-Zuordnung `oa_tkp` × `tkp`
    (native_ad/unzugeordnet → `ValidationException`)
  - manueller Freeze (`oa_tkp`, `tkp`, v1) ohne LiveBookability
  - Preisjahr (aktuell/Folgejahr; Folgejahr nur mit aktiver OA-Liste), Pin bei gleichem
    Medium/Inventar/Jahr, sonst Rebind auf aktive Revision; `expected_oa_tkp_price_list_id`
    mit Mismatch → `PriceListSelectionConflictException` (409)
  - Plattformen/Targetings aus Stammdaten (id oder Code), Snapshots; Client-Preise/-Namen
    werden ignoriert; unveränderte Targetings/Plattformen behalten ihren Snapshot
  - `lockOaPriceListsForYears()` (Lock-Reihenfolge nach id)
- Writer: Spot + OA gemischt (`totalsFromResolved`), Summen/Zielbudget über alle Positionen,
  OA-Child-Sync (delete+recreate), Orphan-Cleanup, Reload (`payloadFromCalculation`),
  Header-only-Vergleich enthält OA-Felder, Sonderfreigabe nach Persist über Assessor
- Fail-closed: HTTP Preview/Store/Update (`OaTkpHttpReleaseGuard`), Budget-Vorschlag/-Apply,
  Standardangebot-Materializer
- Tests: `PoCalcTkp1d1WriterIntegrationTest` (13 Tests)

## OUT (bewusst nicht)

- Registry-Release, Wizard/UI, Dispo-Vollsnapshot, HTTP-Freigabe
- Adserver, Festpreis, Native, Plattformpreise (FD-OA-PLAT)
- Migration (Schema aus 1B ausreichend)
- Nutzerbegründung bei Special-Submit

## Offene Punkte / Hinweise

- Header-only-Update (z. B. nur Auftragsrabatt) aktualisiert Kalkulationssummen, nicht
  Positionsbeträge — bestehendes Spot-Verhalten, unverändert.
- Fehlende `price_year` bei bestehender OA-Position behält den Pin (nicht aktuelles Jahr).
- MySQL-Concurrency-Test für OA-Lock nicht ergänzt.

## Statuszeile

> Normal-TKP in CalculationWriter integriert; Registry planned; keine Nutzerfreigabe.
