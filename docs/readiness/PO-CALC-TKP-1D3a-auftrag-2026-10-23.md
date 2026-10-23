# PO-CALC-TKP-1D3a – OA Dispo Snapshot Contract + Mapper

Stand: 23. Oktober 2026 (Feature-Branch)  
Base: `87924b770e2b4d583e5a0061e85b4330f432369b`

## Ziel

Gespeicherte Online-Audio-TKP-Positionen über den bestehenden `DispoOrderWriter`
in revisionssichere Dispo-Snapshots übernehmen. Spot-Handoff unverändert.

## IN

- Additive Spalten + JSON auf `dispo_order_positions` (Impressions, TKP-Felder,
  OA-Preislistenidentität, Platform-/Targeting-Snapshots)
- `DispoOrderSnapshotMapper` OA-Zweig (Freeze `oa_tkp`×`tkp`); keine Neuberechnung
- D1-Gate `assertOaTkpNotHandOffUntilD3` ersetzt durch Vollständigkeitsprüfung
- OA-only / Mixed Spot+OA / Teil- und Mehrfachübernahme (DSP-001/003/004)
- Snapshot-Isolation gegenüber Calc-/Stammdaten-Änderungen
- Keine Spotproduktion für OA
- Tests via `CalculationWriter`-Fixtures; Registry/HTTP Store-Update bleiben closed

## OUT

- D3b Dispo-Dialog / Adoption-UI
- D3c Dispo-Show („0 Spots“-Darstellung)
- D3d operative Härte / Playwright
- D4 Registry Release + HTTP Persistenz
- Material-/Completion-OA-Sonderregeln

## Statuszeile

> D3a implementiert serverseitige OA-Dispo-Snapshots. UI folgt in D3b/D3c;
> Härte in D3d; Persistenz-Nutzerpfad in D4.
