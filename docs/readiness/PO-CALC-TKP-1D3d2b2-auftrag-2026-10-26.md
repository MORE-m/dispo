# PO-CALC-TKP-1D3d-2b-2 – Operative Flight-UI

Stand: 26. Oktober 2026 (Feature-Branch / Draft-PR)  
Base: `cb012b11c501445f047d06c3d45a15098f27fd15` (D3d-2b-1 auf `main`, PR #187)

## Ziel

React-Oberfläche zur Pflege des verbindlichen operativen Kampagnenzeitraums
für Online-Audio-TKP-Positionen im bestehenden Dispoauftrag-Show.

## Voraussetzung

D3d-2b-1 (Backend/API/Gates) ist auf `main`. FD-D3d-2-01 gilt unverändert.

## IN

- `DispoOrderOperationalFlightEditor` (positionsbezogen)
- Klare Trennung: Kalkulationszeitraum (Snapshot) vs. verbindlicher Ops-Flight
- Edit nur bei `canUpdateOperationalFlight` (Server-Prop inkl. Statusfenster)
- Optionale Aktion „Zeitraum aus Kalkulation übernehmen“ (nur gültiger
  geschlossener Handoff-Flight → Felder, kein Auto-Save)
- Speichern/Abbrechen/Entfernen über bestehenden PUT-Vertrag
- 403 / 409 (Reload) / 422-Feldfehler
- Abschluss-Hinweis bei fehlendem Ops-Flight
- Vitest für Editor + Helpers

## OUT

- Keine neuen Backend-Fachregeln / keine neuen Gates
- Kein Prefill, kein Snapshot-Schreiben
- Kein D3d-3 Material, kein D3d-4 Playwright-Harness-Ausbau, kein D4 Release
- Registry planned; HTTP Calculation Store/Update geschlossen

## Statuszeile

> D3d-2b-1 Backend abgeschlossen. D3d-2b-2 liefert die operative Show-UI.
> Der ursprüngliche Snapshot bleibt unveränderlich; der verbindliche OA-Flight
> wird bewusst operativ gepflegt. D3d-3/D3d-4/D4 bleiben offen.
