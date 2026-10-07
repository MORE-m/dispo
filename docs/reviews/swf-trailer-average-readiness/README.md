# Bericht: SWF Trailer × Durchschnitt – BL-P5-01a

Stand: 7. Oktober 2026 (Review-Nachzug PR #129)
Arbeitsbasis Feature: `feat/bl-p5-01a-swf-trailer-average` (Draft-PR #129)
PO-BLP501A-1 **A1 + B1 unverändert freigegeben**. **Kein** Merge/Deploy.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP501A-1-swf-trailer-average.md`](../../entscheidungen/PO-BLP501A-1-swf-trailer-average.md) | **A1 + B1 akzeptiert** |
| [`docs/readiness/BL-P5-01a-swf-trailer-average-2026-10-07.md`](../../readiness/BL-P5-01a-swf-trailer-average-2026-10-07.md) | Readiness + Umsetzungsstand |

## Scope

- Trailer × Average, inventarübergreifend (Matrix: RH, ROCK, OLDIE, CARAVAN)
- Preisbasis B1: Spot-Sekundenpreise + inventarspezifischer Trailer-Aufschlag
- Formel `Anzahl × Ø-Sekundenpreis × Länge × (1 + Aufschlag/100)`; kein Spotindex
- Fixture-Parität: 520,00 / 168,75 / 688,75

## Review-Nachzug (PR #129)

### Ursachen

1. **Komponenten:** `resolveComponentsAfterMediumChange` übernahm Spot-Komponenten auch für Trailer; UI-Ausblenden reichte nicht.
2. **Festpreis:** `PricingSettlementSection` mit `hideFixedPrice={false}`; Spot-Festpreis blieb nach Mediumwechsel aktiv.
3. **Migration:** `nullTrailerRuleConfiguration()` nullte alle Trailer-Regeln – inklusive individueller Werte; erneutes `up()` hätte gepflegte Länge/Aufschlag gelöscht. `down()` löschte ggf. vorbestehende Kategorie-Defaults.
4. **Settlement-Cleanup (finaler Nachzug):** `clearTrailerSettlementValidation` wurde im funktionalen `setPositions`-Updater gesetzt und danach gelesen – unzuverlässig bei asynchronem/gebündeltem React-Updater.

### Korrekturen

1. Helper `wizard-trailer-medium-change.ts`: Trailer strippt Komponenten/Strategie, Länge aus Ziel-Regel, kein Stash-Restore in Trailer; Stash-Restore Spot/Tandem/Tridem erhalten. Inventar-Rebind strippt Trailer-Komponenten.
2. `hideFixedPrice` für Trailer; Settlement-Reset auf `normal`, Festpreis-Input/Validierung bereinigt; Server-Ablehnung bleibt.
3. Migration: nur Altdefaults (0 + Medium-Default/NULL-Länge) nullen; bei kind=NULL und individuellen Werten Preflight-Abbruch; bei bereits aktivem kind keine Regel-Mutation; `down()` entfernt nur `engine_profile_key=swf_trailer`, Default unberührt.
4. Zielmedium vor dem Positions-Updater bestimmen (`resolveTargetMediumIdAfterPatch` + `shouldClearTrailerSettlementForTargetMedium`); Festpreis-Touched/Feldfehler/Save-Banner außerhalb bereinigen; keine Nebenwirkung im Updater.

### Nachweise

- Browser-Smoke Port **8055**, `--retries=0`: 7/7
  - Spot Komponenten → Trailer → Preview/Save/Reload
  - Spot Festpreis → Trailer (Normal, keine Festpreiswahl)
  - Spot Festpreis mit leerem Betrag → Validierungsfehler → Trailer: Fehler weg, Preview/Save/Reload
  - Trailer Inventar A → B (Ziel-Länge/Aufschlag/Preise)
  - Unvollständig konfiguriertes Inventar: Sperre
  - Rückwechsel Trailer → Spot ohne Regression
  - Zwei-Inventar-Parität 520/168,75/688,75 + Dispo
- Pest: Migration (Schutz individueller Werte, Re-up, Default-Erhalt), Acceptance (Jahreswechsel, Spot↔Trailer, Nachfolger-Pin Mengenänderung, Matrix ohne Regel ohne Mutation), Vitest Mediumwechsel/Rebind/Cleanup-Entscheidung
- Rechenbeispiele und Snapshot-/Dispo-Parität unverändert

## Offen

Operative Freischaltung je Inventar erst nach Datenlieferung. **Kein** Merge/Deploy – Stopp zur finalen Review.
