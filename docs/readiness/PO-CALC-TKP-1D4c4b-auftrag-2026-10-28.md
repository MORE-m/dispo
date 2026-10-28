# PO-CALC-TKP-1D4c-4b – Gesamt-Mindest-TKP und Sonderfreigabe

**Status:** Feature-Branch / Draft-PR – **STOP vor Merge ohne PO-Abnahme.**  
Keine Admin-UI, kein Fachimport, kein Deploy, keine Registry-Freigabe.

## Verbindliche Fachentscheidung (FD-D4c-4b)

```
final_tkp              = basis_tkp + Σ list_tkp_surcharge
effective_minimum_tkp  = price_list.minimum_tkp + Σ minimum_tkp_surcharge
Sonderfreigabe         ⇔ final_tkp < effective_minimum_tkp
```

- Gleichheit ist zulässig (keine Sonderfreigabe).
- `basis_tkp < price_list.minimum_tkp` löst **allein** keine OA-Sonderfreigabe aus.
- Positive Basis-TKP-Eingabevalidierung unverändert.
- Reason-Code bleibt `oa_tkp_basis_below_minimum` (keine zweite Freigabeart).

## Persistenzvertrag

| Feld | Bedeutung ab D4c-4b |
|---|---|
| `calculation_positions.minimum_tkp_snapshot` | **Effektive** Mindestschwelle |
| `calculation_positions.price_list_minimum_tkp_snapshot` | Preislisten-Grundminimum (nullable) |
| `…_oa_targeting_selections.tkp_surcharge_snapshot` | Listen-Aufschlag |
| `…_oa_targeting_selections.minimum_tkp_surcharge_snapshot` | Mindest-Aufschlag (nullable; Lesen → 0) |

**Historie:** Zeilen ohne `price_list_minimum_tkp_snapshot` → Legacy-Assessor
(`basis_tkp` vs. `minimum_tkp_snapshot`, 1C1). Keine Migration/Neuberechnung alter Calcs.
Bestehende Dispo-Snapshots unverändert. Neue Dispo-Übergaben: `minimum_tkp_snapshot`
= effektiv; Targeting-JSON additiv `minimum_tkp_surcharge_snapshot`.
**Kein** neues Dispo-Spaltenfeld für Grundminimum in 4b (Schema-Risiko vermieden;
Grundminimum bleibt Calc-seitig nachvollziehbar).

## Engine / Writer / Assessor

- `OaTkpNormalEngine`: Input-`minimumTkp` = Grundminimum; Result-`minimumTkp` = effektiv;
  zusätzlich `priceListMinimumTkp`, `targetingMinimumSurchargeTotal`.
- Pin/Rebind: Engine erhält nur Grundminimum (kein Doppelzählen der Mindestaufschläge).
- Preview/Persist: Under-Min = `final < effective`.
- Assessor: neuer Vertrag wenn `price_list_minimum_tkp_snapshot` gesetzt, sonst Legacy.

## Isolierte Testwerte (nur Fixtures)

| Medium | Liste | Grundminimum |
|---|---:|---:|
| Pre-Stream | 56,00 | 14,00 |

| Targeting | Listenaufschlag | Mindestaufschlag |
|---|---:|---:|
| DMP | 25,00 | 5,00 |
| Geo | 15,00 | 3,00 |

Keine produktive Datenbefüllung, keine Übertragung auf 41 Inventarkombinationen.

## OUT

- Admin-Preisverwaltung / UI-Neugestaltung
- Fachpreisimporte / Registry-Release / Deploy
- Zweite Sonderfreigabeart
- Historische Backfills

## Abnahme-Checkliste

1. OA ohne / nur DMP / nur Geo / DMP+Geo
2. Final oberhalb / exakt / unterhalb effektivem Minimum
3. Basis unter Grundmin, Final ≥ effektiv → **keine** Sonderfreigabe
4. Targetings genau einmal; Mindestaufschlag 0 rückwärtskompatibel
5. Inaktive Targetings abgewiesen; Client-Overrides ignoriert
6. Targeting-Änderung aktualisiert Minimum / invalidiert überholte Freigabe
7. Historische Snapshots unverändert; Mixed Spot/OA; Spot/SWF unverändert
8. Registry planned → HTTP Store/Update geschlossen
9. CI inkl. OA-Smoke

## Release-Protection

Registry `oa_tkp`/`tkp` bleibt **planned**. HTTP Store/Update ohne Fake geschlossen.
