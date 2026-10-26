# PO-CALC-TKP-1D3d-2b-1 – Operativer Flight-Service und Abschluss-Gates

Stand: 24. Oktober 2026 (Feature-Branch / Draft-PR)  
Base: `5673dd7e89eb413bee70b66c7d6aef7df54dc932` (D3d-2a auf `main`, PR #186)

## Ziel

Backend für den verbindlichen operativen Kampagnenzeitraum (OA-TKP) gemäß
**FD-D3d-2-01**, inklusive disposed-/completed-Gates. Keine React-Edit-UI (D3d-2b-2).

## FD-D3d-2-01 (beschlossen)

1. Offener Calc-/Handoff-Flight während Kalkulation, Handoff und Ops erlaubt.
2. Vor `disposed`: jede OA-TKP-Media-Position braucht gültigen Ops-Flight (Start+Ende).
3. `completed` zusätzlich serverseitig abgesichert.
4. Admin-`forceComplete` **kann den Ops-Flight-Check nicht umgehen** (harter Guard in
   `DispoOrderCompletionService` vor Readiness-Override).
5. Positionsbezogen; Mixed prüft alle OA-Positionen.
6. Spot/SWF unverändert.
7. Calc-/Handoff-DF und Derived bleiben unveränderlich; kein Prefill beim Create.

## IN

- Migration: `operational_flight_start` / `operational_flight_end` (nullable date)
- `OperationalFlightContract`, `DispoOrderOperationalFlightService`
- Policy `updateOperationalFlight`, HTTP PUT, Show-Props read-only + `canUpdateOperationalFlight`
- Gate in `DispoOrderOperationalStatusService` → `disposed`
- Harter Guard + Readiness-Check `oa_operational_flight` für Completion
- Tests Unit/Feature/MySQL-Smoke; Doku

## OUT

- React-Edit-UI / „Übernehmen aus Kalkulation“ (D3d-2b-2)
- D3d-3 Material, D3d-4 Playwright, D4 Registry/HTTP Release
- Prefill, Backfill, neue Statusmaschine, Invoice-Logik-Änderung

## Statuszeile

> D3d-2b-1 liefert Backend, API und Abschluss-Gates für den operativen OA-Flight.
> Die editierbare Show-Oberfläche folgt in D3d-2b-2.
