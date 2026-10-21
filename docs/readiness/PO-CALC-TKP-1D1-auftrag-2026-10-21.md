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
    Medium/Inventar/Jahr, sonst Rebind; `expected_oa_tkp_price_list_id` analog Spot
    (Pflicht bei explizitem `price_year` und bei Rebind; fehlt → 422; Mismatch → 409)
  - Targeting: code-only bei bestehendem Code hält historischen Snapshot; ID-Pin;
    ID/Code-Mismatch → reject
  - Plattformen aus Stammdaten; Client-Preise/-Namen ignoriert
  - `yearsToLockForPayload` inkl. gepinnter Nicht-Current-Jahre; Lock nach id
- Writer: Spot + OA gemischt, Child-Sync, Orphan-Cleanup, Reload, Assessor nach Persist
- Fail-closed: HTTP (`OaTkpHttpReleaseGuard`), Budget, Standardangebote
- **Dispo vor D3:** `DispoOrderSnapshotMapper` rejectet Freeze `oa_tkp`×`tkp`
  (kein unvollständiger OA-Dispo-Snapshot; D3 ersetzt diesen Gate)
- Tests: Writer-Integration + `PoCalcTkp1d1DispoGateTest`

## OUT (bewusst nicht)

- Registry-Release, Wizard/UI, **vollständiger** OA-Dispo-Snapshot (D3), HTTP-Freigabe
- Adserver, Festpreis, Native, Plattformpreise (FD-OA-PLAT)
- Migration (Schema aus 1B ausreichend)
- Nutzerbegründung bei Special-Submit

## Offene Punkte / Hinweise

- Header-only-Update (z. B. nur Auftragsrabatt) aktualisiert Kalkulationssummen, nicht
  Positionsbeträge — bestehendes Spot-Verhalten, unverändert.
- Fehlende `price_year` bei bestehender OA-Position behält den Pin (nicht aktuelles Jahr).
- MySQL-Concurrency-Test für OA-Lock nicht ergänzt.
- Dispo-Gate wird in D3 entfernt/ersetzt, sobald der OA-Vollsnapshot existiert.

## Statuszeile

> Normal-TKP in CalculationWriter integriert; Registry planned; Dispo-Handoff OA fail-closed bis D3; keine Nutzerfreigabe.
