# PO-CALC-TKP-1D2b – React TKP Wizard UI

Stand: 22. Oktober 2026 (`main`, PR #173)

## Ziel

Vollständige Wizard-Oberfläche für Normal-TKP unter `OA_TKP_WIZARD_CAPABILITY`,
aufbauend auf D2a-Serververtrag. **Kein** HTTP Store/Update, **kein** Registry-Release.

## UI

- `OaTkpPositionEditor` – Impressions, Basis-TKP, Preisjahr, Platforms, Targetings
- Metadata Hook (`use-oa-tkp-pricing-metadata`) mit Abort/Sequence
- Preview Readiness clientseitig; Debounce über bestehendes `useCalculationPreview`
- Summary TKP-fähig (keine „0 Spots“)
- Save bei OA/Mixed disabled + defensiver Guard
- Budget-/Dispo-UI geschützt; Standard Offers ohne Soft-Open

## Persistenz

D2b endet vor Persistenz. Registry bleibt:

```text
oa_tkp / tkp: pair_status=planned, current_released_version=null, v1=implemented
```

## Folge

- D3: OA Dispo Snapshot/Handoff
- D4: Registry Release + HTTP Persistenz
