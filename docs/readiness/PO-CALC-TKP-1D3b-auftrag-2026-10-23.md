# PO-CALC-TKP-1D3b – Adoption-API + Dispo-Create-Dialog

Stand: 23. Oktober 2026 (Feature-Branch / Draft-PR)  
Base: `99eea365f0782522b850fb7f820e710961655edf` (D3a auf `main`)

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
- Registry planned; HTTP Calculation Store/Update geschlossen

## OUT

- D3c Dispo-Show OA-Darstellung (nach Create kann die Show noch Spot-zentriert sein)
- D3d operative Härte / OA-Playwright-Smoke
- D4 Registry Release + HTTP Persistenz
- Budget/SO TKP; neue Mutation-Hintertüren

## Draft vs. Saved

Create-Dialog und Adoption-API lesen ausschließlich persistierte Positionen
(`GET dispo-orders.positions` → DB). Wizard-Drafts (ungespeicherte TKP-/Spot-
Änderungen) fließen nicht ein. UX-Hinweis im Dialog:

> Für den Dispoauftrag werden die zuletzt gespeicherten Kalkulationspositionen
> verwendet. Nicht gespeicherte Änderungen im Wizard werden nicht übernommen.

Kein zusätzlicher Dirty-State-Mechanismus; der Hinweis gilt immer beim Create.

## Statuszeile

> D3b macht gespeicherte OA-Positionen im Create-Dialog auswählbar.
> Show-Vollständigkeit folgt in D3c.
