# PO-CALC-TKP-1D3d-2a – OA Flight Contract Regression

Stand: 24. Oktober 2026 (Feature-Branch / Draft-PR)  
Base: `4d64ac21664055b8a810e1ecedb2222eacfbc448` (D3d-1 auf `main`, PR #185)

## Ziel

Bestehenden Online-Audio-TKP-Flight-Vertrag (DSP-DCP-001 Non-Calendar-Pfad)
durch gezielte Tests und Dokumentation absichern.

## Verbindlicher Vertrag (unverändert)

- OA-TKP nutzt den Non-Calendar-Flight-Pfad (`spot_method=tkp` → Flight, nicht Planner).
- Dynamic Fields: `period_open`, `position_flight_period`.
- Bei `period_open=false` ist ein gültiger Flight erforderlich.
- Bei `period_open=true` ist ein offener Flight derzeit erlaubt (Derived `open`).
- Header `campaign_period` ist nicht Source of Truth für Derived.
- Derived wird beim Dispo-Create eingefroren; Calc-Änderungen danach ändern Derived nicht.
- Unterschiedliche Positionszeiträume → Min-/Max-Envelope.
- Historische Legacy-Snapshots bleiben unverändert.
- Completion: `open` überspringt Invoice-Ende; `invalid` blockiert (methodenagnostisch).

## IN

- Unit: OA-TKP-Fälle in `DispoOrderCampaignPeriodDeriverTest`
- Unit: `DispoOrderCompletionReadinessPeriodStateTest` (open/invalid/concrete inkl. `tkp`)
- Feature: `PoCalcTkp1d3d2aFlightContractTest` (closed/open, Envelope, Mixed, Freeze, Legacy, Header-Konflikt, Release Protection)
- Doku: `fortschritt.md`, DSP-DCP-001-Klarstellung, dieser Readiness-Eintrag

## OUT

- Neue Flight-Pflichten / Pflichtfelder
- Neue Status- oder Completion-Gates (D3d-2b)
- D3d-3 Material/Completion-Härte
- D4 Registry Release / HTTP Store/Update / Reporting
- Neue Flight-UI
- MySQL-OA-Duplikat (Flight-JSON bereits über Average-MySQL abgedeckt; OA-Pfad SQLite-Feature)

## Fachentscheidung offen (D3d-2b)

Ob und ab welchem Lifecycle-Punkt ein **geschlossener** OA-Flight verpflichtend
sein soll (`period_open=false` + gültiger Zeitraum), ist **nicht** in D3d-2a
entschieden. D3d-2a bestätigt und testet nur den Ist-Vertrag.

## Statuszeile

> D3d-2a bestätigt und testet den bestehenden Flight-Vertrag. Es führt keine neuen Pflichtfelder und keine neuen Status-Gates ein.
