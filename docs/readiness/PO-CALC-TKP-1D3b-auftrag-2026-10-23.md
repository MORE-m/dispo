# PO-CALC-TKP-1D3b – Adoption-API + Dispo-Create-Dialog

Stand: 23. Oktober 2026 – **auf `main`** (PR #175)  
Merge-Commit: `6e4f9bbfbc0e800429bee6c87a1c876c22d64158`  
Base vor Merge: `99eea365f0782522b850fb7f820e710961655edf`

## Ziel

Gespeicherte OA-TKP-Positionen im bestehenden Dispo-Create-Dialog auswählen und
übergeben. Kein neuer Dispo-Prozess. DSP-001/003/004 bleiben verbindlich.

## IN

- Additive Adoption-API-Felder aus gespeicherten Calc-Werten:
  `calculation_method_key`, `engine_profile_key`, `impressions`, `final_tkp`
- `dispo-order-create-dialog.tsx`: OA-Zeile mit Impressions/TKP ohne Spotlänge
- Mixed Spot+OA in derselben Positionsliste; Mehrfachübernahme unverändert
- Wizard-Guard: Create bei persistierter Kalkulation; Preview-only ohne Speicherung
  ohne produktiven Handoff
- UX: Create nutzt zuletzt gespeicherte Positionen (keine Wizard-Drafts)
- Registry planned; HTTP Calculation Store/Update geschlossen

## OUT (folgt)

- D3c Dispo-Show OA-Darstellung → **PO-CALC-TKP-1D3c**
- D3d operative Härte / OA-Playwright-Smoke
- D4 Registry Release + HTTP Persistenz

## Statuszeile

> D3b ist auf `main` (PR #175). Show-Vollständigkeit folgt in D3c.
