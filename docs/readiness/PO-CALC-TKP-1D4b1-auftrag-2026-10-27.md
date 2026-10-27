# PO-CALC-TKP-1D4b-1 – OA-TKP Save-Gate an Registry koppeln

Stand: 27. Oktober 2026 (Feature-Branch / Draft-PR)  
Base: `96de1c035301c910bc6b2e37f79a4df4f4f6ce1b` (D3d-4 auf `main`, PR #190)

## Ziel

UI und Backend nutzen dasselbe serverseitige Registry-Release-Signal für die Frage,
ob OA-TKP-Kalkulationen gespeichert werden dürfen.

**Die UI ist für einen zukünftigen Registry-Release vorbereitet. Die produktive
OA-Speicherung bleibt gesperrt.**

## Vertrag

| Signal | Quelle | Wirkung |
|---|---|---|
| Preview Soft-Open | `OaTkpWizardCapability::allowsWizardPreviewSurface()` (Capability **oder** Pair released) | Method Options / Preview / Metadata |
| HTTP Mutation + UI-Save | `OaTkpWizardCapability::allowsHttpMutation()` (= Pair released) | Store/Update Guard + Wizard-Prop `oa_tkp_http_mutation_allowed` |

Capability allein öffnet Speichern **nicht**.

### Soll-Verhalten

**Registry planned (Produktiv / dieser Slice):**

- Prop `oa_tkp_http_mutation_allowed = false`
- `tkpSaveBlocked` bei OA-TKP-Positionen (regulärer Wizard)
- HTTP Store/Update → 422
- Budget-/SO-TKP weiterhin geschlossen
- LiveBookability unverändert blocked

**Registry released (nur Test-Fake, kein Catalog-Flip):**

- Prop `true`
- UI nicht mehr allein wegen Methode TKP am Speichern gehindert
- HTTP-Guard folgt demselben Signal
- Produktive `EngineProfileRegistry` bleibt `planned` / `current_released_version=null` / `v1=implemented`

## Umsetzung

- `CalculationController::wizardProps` → `oa_tkp_http_mutation_allowed`
- `isOaTkpSaveBlocked` + Wizard `tkpSaveBlocked`
- Test-only: `OaTkpWizardCapability::fakeHttpMutationAllowedForTests` (nur `runningUnitTests`)

## OUT

- Registry-Freigabe / Catalog-Flip (D4d)
- Positiver produktiver HTTP-Store (D4b-2 / D4d)
- Preislistenimport
- LiveBookability-Änderung
- Budget-/SO-TKP-Freigabe
- Reporting, Flight, Material, Handoff, Completion

## Statuszeile

> D4b-1: Save-UI an Registry-Release-Signal gekoppelt. Registry planned; HTTP Store
> geschlossen; D4b-2 und D4d offen.
