# Bericht: SWF Trailer × Durchschnitt – BL-P5-01a

Stand: 8. Oktober 2026 (Statusnachzug nach lokaler Einrichtung + Browser-Abnahme)
Arbeitsbasis: `origin/main` @ `d97a5aefc3948e297fb510f7146e23bb38f71c14`
(Merge PR [#129](https://github.com/MORE-m/dispo/pull/129);
Post-Merge-CI [37668554937](https://github.com/MORE-m/dispo/actions/runs/37668554937) SUCCESS;
**kein** Deploy)
PO-BLP501A-1 **A1 + B1 unverändert freigegeben**. Lokal `dispo_mat_core` eingerichtet;
Deploy/andere Umgebungen **offen**.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP501A-1-swf-trailer-average.md`](../../entscheidungen/PO-BLP501A-1-swf-trailer-average.md) | **A1 + B1 akzeptiert** |
| [`docs/readiness/BL-P5-01a-swf-trailer-average-2026-10-07.md`](../../readiness/BL-P5-01a-swf-trailer-average-2026-10-07.md) | Feature-Readiness + Umsetzungsstand |
| [`docs/readiness/BL-P5-01a-swf-trailer-average-data-2026-10-07.md`](../../readiness/BL-P5-01a-swf-trailer-average-data-2026-10-07.md) | Operative Daten-Readiness (nach Merge) |
| [`docs/entscheidungen/PO-BLP501A-1-datenlieferung.md`](../../entscheidungen/PO-BLP501A-1-datenlieferung.md) | PO-Datenlieferungsanfrage |

## Scope

- Trailer × Average, inventarübergreifend (Matrix: RH, ROCK, OLDIE, CARAVAN)
- Preisbasis B1: Spot-Sekundenpreise + inventarspezifischer Trailer-Aufschlag
- Formel `Anzahl × Ø-Sekundenpreis × Länge × (1 + Aufschlag/100)`; kein Spotindex
- Fixture-Parität: 520,00 / 168,75 / 688,75 (kein operativer Datenbeleg)

## Review-Nachzug (PR #129; historisch vor Merge)

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
- Pest: Migration (Schutz individueller Werte, Re-up, Default-Erhalt), Acceptance, Vitest Mediumwechsel/Rebind
- Post-Merge-CI `37668554937` SUCCESS

## Offen

Lokale Einrichtung und Browser-Abnahme auf `dispo_mat_core` **bestanden**
(siehe [`swf-trailer-average-data-readiness`](../swf-trailer-average-data-readiness/)).
Deploy und andere Umgebungen weiter offen. **Kein** Deploy. `BL-P5-01` bleibt teilweise.
