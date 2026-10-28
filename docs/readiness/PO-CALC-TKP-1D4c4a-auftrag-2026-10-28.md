# PO-CALC-TKP-1D4c-4a – Targeting-Mindest-TKP Datenmodell und Importvertrag

Stand: 28. Oktober 2026 (Implementierung Feature-Branch)  
Base: `14de0f218a7842b46e658942bd0c384a989eb360`  
Discovery: `docs/readiness/PO-CALC-TKP-1D4c4-discovery-2026-10-28.md`

**STOP vor Merge ohne PO-Abnahme.** Keine Engine-/Under-Min-Änderung, keine Admin-UI, kein produktiver Import, kein Deploy, keine Registry-Freigabe.

## Umgesetzt

| Baustein | Status |
|---|---|
| Migration `oa_targeting_definitions.minimum_tkp_surcharge` decimal(16,4) default 0 | ja |
| Model fillable / Doku | ja |
| `OaTargetingDefinitionImporter` Listen + Mindest | ja |
| Fehlendes Feld → `0.0000`; negativ/Scale/Float fail-closed; `min ≤ list` | ja |
| Import-Vorlagen CSV/README | ja |
| Selection-Snapshot-Spalte / Writer / Engine | **OUT → D4c-4b** |
| Registry / HTTP Store / Admin UI | unverändert planned / geschlossen / fehlt |

## Semantik

- `tkp_surcharge` = **Listen-Aufschlag** (weiterhin Final-TKP-Eingang in der Engine)
- `minimum_tkp_surcharge` = **Mindest-Aufschlag** (Stammdaten + Import; **noch nicht** in Engine/Sonderfreigabe)

## Snapshot-Vertrag (Schnittstelle D4c-4b)

Für reproduzierbare Kalkulation nach Engine-Umstellung:

1. Spalte `minimum_tkp_surcharge_snapshot` an `calculation_position_oa_targeting_selections`
2. `OaTkpTargetingSelectionInput` um Mindestaufschlag erweitern
3. Writer lädt/pinnt beide Werte; Persister schreibt beide
4. Dispo-JSON-Snapshot additiv; Reader tolerant für Altzeilen (`null`/`fehlend` → 0)
5. Engine: `effektives Min = Preislisten-Min + Σ Mindest-Snapshots`; Assessor weiter Basis vs. `minimum_tkp_snapshot` (effektiv)

In **4a** bewusst nicht umgesetzt, damit Final-TKP und Under-Min unverändert bleiben.

## Tests

- `PoCalcTkp1d4c4aTargetingMinimumImportTest`
- `PoCalcTkp1d4c4aMysqlSchemaTest`
- Anpassungen: `OaTkpStammdatenImportTest`, `PoCalcTkp1d4c2ImportCliTest`

## OUT

- Engine / Under-Min / SpecialApproval
- Admin-UI
- Fachpreis-Import / Activate
- Registry D4d
- Selection-Snapshot-Persistenz
