# PO-CALC-TKP-1D3d-1 – Methodenkonsistenz und Fail-Closed-Guards

Stand: 24. Oktober 2026 (Feature-Branch / Draft-PR)  
Base: `3081741bf70186ca8fa47189e0a0cd9acb0036df` (D3c auf `main`, PR #176)

## Ziel

Einheitliche Method-/Engine-Klassifikation für Dispo-Adoption, Handoff und Show.
Inkonsistente Paare weder als Spot noch als OA behandeln (fail-closed).

## IN

- `DispoPositionMethodClassifier` (PHP) + TS-Spiegel
- Adoption-UI: AND-Erkennung (`tkp` ∧ `oa_tkp`); OR entfernt
- Adoption-API: `method_classification`, `adoptable`, `method_inconsistency_message`
- Create-Dialog: inkonsistente Positionen deaktiviert, keine „0 Spots“-Darstellung
- Writer/Mapper: Handoff fail-closed vor Persistenz; Mixed-Rollback
- Show: Read-only Fehlerdarstellung für historische inkonsistente Snapshots
- Tests: Unit/Feature/Vitest

## OUT

- D3d-2 Kampagnenzeitraum-Härte
- D3d-3 Material/Completion
- D3d-4 OA-Playwright-Smoke
- D4 Registry Release / HTTP Store/Update / Reporting
- Materialpflichten, Upload-Kategorien, Statusmaschine

## Gültige Matrix (Registry)

| Profil | Methode | Klassifikation |
|---|---|---|
| `oa_tkp` | `tkp` | OA |
| `spot_classic` | `average` / `calendar` / `fixed_price` | Spot-Familie |
| `swf_trailer` / `swf_allonge` / `swf_promo` / `swf_contest` | `average` / `calendar` | Spot-Familie |
| `null` / `null` | Legacy | Spot-Familie |
| sonst (Halbpaare, unbekannte Kombinationen) | — | inconsistent |

Registry `oa_tkp`/`tkp` bleibt **planned**; HTTP Store/Update geschlossen.

## Statuszeile

> D3d-1 härtet nur Methodenkonsistenz. Material/Completion/Perioden folgen in D3d-2/3.
