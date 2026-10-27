# PO-CALC-TKP-1D3d-3a – OA Material & Completion Contract

Stand: 26. Oktober 2026 (auf `main`, PR #189,
Merge `23e9f0c775bb52f46e4ded343987b64e9c3b9a07`)  
Base: `7b0951fd20a02abd55f80dd468ad6220056f2082` (D3d-2b-2 auf `main`, PR #188)

## Ziel

Bestehenden Online-Audio-Materialworkflow und die Abgrenzung zur Spotproduktion
durch gezielte Tests und Dokumentation absichern. **Keine** neuen Materialpflichten,
**keine** neuen Material-/Completion-Gates.

## Fachvertrag (beschlossen für diesen Slice)

1. Materialstatus bleibt auftragsebene (STA-001).
2. Bestehende Upload-Kategorien gelten auch für OA-Aufträge.
3. Uploads ändern den Materialstatus nicht automatisch.
4. Keine Pflichtkategorien und keine positionsbezogene Materialstatusmaschine.
5. Completion-Readiness enthält **kein** Material-Gate.
6. OA erzeugt keine Spot-Produktionszeilen; `length_seconds=0` löst keine Produktion aus.
7. Operativer OA-Flight bleibt hart (D3d-2b) und nicht per `forceComplete` übersteuerbar.
8. `native_ad` bleibt außerhalb des OA-TKP-Kernvertrags.
9. Keine Influencer-Sonderregeln ohne eigene Fachentscheidung.

## IN

- Feature-Tests `PoCalcTkp1d3d3aMaterialCompletionContractTest`
- Doku: Fortschritt, Workflows (Completion: sechs Checks), Testkatalog, dieser Auftrag

## OUT

- Neue Materialpflichten / UI / Statusmaschine / Migrationen
- D3d-3b (optionales Material-Gate nach späterer Fachentscheidung)
- D3d-4 Playwright, D4 Registry/HTTP Release

## Statuszeile

> D3d-3a bestätigt: OA nutzt den bestehenden auftragsbezogenen Materialworkflow,
> erzeugt keine Spotproduktion und erhält kein Material-Completion-Gate.
> Die verbindliche OA-Flight-Pflicht bleibt. Neue Materialpflichten sind nicht Teil von 3a.
