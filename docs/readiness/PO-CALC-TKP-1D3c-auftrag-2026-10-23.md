# PO-CALC-TKP-1D3c – OA Dispo-Show und Snapshot-Anzeige

Stand: 24. Oktober 2026 – **auf `main`** (PR #176, Merge
`3081741bf70186ca8fa47189e0a0cd9acb0036df`)  
Base zur Umsetzung: `6e4f9bbfbc0e800429bee6c87a1c876c22d64158` (D3b auf `main`)

## Ziel

Übernommene OA-TKP-Positionen in der bestehenden Dispo-Auftragsansicht aus dem
eingefrorenen Dispo-Snapshot darstellen. Spot-only unverändert; Mixed in einem
Auftrag.

## IN

- Show-Props additiv: OA-Snapshot-Felder aus `dispo_order_positions`
- Methodenspezifische Meta-Zeile (`calculation_method_key` × `engine_profile_key`)
- OA-Panel: Impressions, TKP-Preise (4 NK), Platforms, Targetings, Preisidentität
- Keine Live-Neuberechnung / keine Stammdaten-Nachbindung
- Spot-Komponenten/Zeitbereiche/Planner nur bei Nicht-OA

## OUT

- D3d Material-/Completion-/Status-Regeländerungen
- D4 Registry/HTTP Persistenz/Reporting
- Umfangreiche Spot-Distribution-Export-Neuentwicklung (OA ohne Calendar ohnehin
  außerhalb des Export-Capability-Pfads; größere Exportlücken bleiben Folgeslice)

## Statuszeile

> D3c ist reine Snapshot-Darstellung. Operative Härte folgt in D3d.
