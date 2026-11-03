# PO-CALC-TKP-1D4c-4j-1 – Platform×Medium-Rules (Datenmodell)

Stand zum Auftrag (31. Oktober 2026): Feature-Branch, damals Draft-PR.
Base damals: `391eaf440a0ae4b0c7ae2fcc088b890d629cd067` (PR #200 = D4c-4d).
Branch: `feat/po-calc-tkp-1d4c4j1-platform-medium-rules`.
Worktree: `dispo-wt-po-calc-tkp-1d4c4j1`.
Discovery: `PO-CALC-TKP-1D4c4j-discovery-2026-10-30.md`.

## Nachzug 03.11.2026 (nach Merge, Historie des Auftrags unverändert)

| Punkt | Stand |
|---|---|
| PR | **#204** gemergt (Merge-Commit, kein Squash) |
| PO-Abnahme | erfolgt (PR-Body, 31.10.2026) |
| Feature-HEAD | `2e6db748a3a57f801d189514aa13e919c7420760` |
| Merge-Commit | `495499a7a409ddc3bfd8ea7f6c31473d402be899` |
| Post-Merge-CI | [`41199774870`](https://github.com/MORE-m/dispo/actions/runs/41199774870) success (`ci` / `mysql` / `e2e-spt008`) |
| `dispo_mat_core` | für 4j-1 weiterhin **nicht** migriert |
| Enforcement / Fachdaten / Admin-Matrix | weiterhin **nein** |
| Registry | bleibt `planned` |

## Ziel

Additives Datenmodell für werbeformabhängige OA-Plattformzuordnungen inkl.
Eloquent und Lese-/Prüf-Helper. **Noch kein** operatives Enforcement.

## Umgesetzt

| Baustein | Status |
|---|---|
| Migration `oa_platform_medium_rules` | ja |
| Model `OaPlatformMediumRule` | ja |
| Relationen `OaPlatform::mediumRules`, `AdvertisingMedium::oaPlatformMediumRules` | ja |
| Helper `App\Support\OnlineAudio\OaPlatformMediumRules` | ja |
| Writer-/Wizard-Enforce | **nein** (4j-5 / 4j-6) |
| Admin-Backend/UI / Plattformbefüllung | **nein** |
| Registry | bleibt `planned` |

## Schema

| Spalte | Typ | Hinweis |
|---|---|---|
| `oa_platform_id` | FK → `oa_platforms` | `restrictOnDelete` |
| `advertising_medium_id` | FK → `advertising_media` | `restrictOnDelete` |
| `is_active` | bool, default true | Soft-Deactivate |
| Unique | `(oa_platform_id, advertising_medium_id)` | |

Nur OA-Kernmedien (`OaTkpCoreMedia`); `native_ad` serverseitig abgewiesen.

## Helper-API (nicht im Resolver verdrahtet)

- `isAdvertisingMediumCodeAllowed` / `assertAdvertisingMediumAllowed`
- `activePlatformIdsForMedium` / `activeMediumIdsForPlatform`
- `isActivelyAssigned` (Rule aktiv + Plattform aktiv + Kernmedium)
- `rulesForPlatform` / `rulesForMedium` (optional nur aktiv)

Leerer Regelbestand ⇒ keine aktiven Zuordnungen (keine implizite Freigabe).

## Historienvertrag

Additiv: keine Änderung an Calc-/Dispo-Snapshots, Preislisten, Targetings.
Kein Backfill, keine Auto-Plattformen.

## Folgeslices (kanonische Nummerierung, PO-Entscheidung 03.11.2026)

Diese Tabelle ist ab 03.11.2026 der verbindliche Folgeplan. Die ältere
Discovery-Nummerierung vom 30.10.2026 ist historisch überholt. Fachlich bleibt:
kein fail-closed Enforcement vor administrierbarer und ausreichend befüllter Rule-Basis.
Reihenfolge: **4j-2 → 4j-3 → 4j-4 → 4j-5 → 4j-6**. Kein Vorziehen von 4j-5.

| Slice | Inhalt |
|---|---|
| **4j-2** | Admin-Backend Rules CRUD/Matrix-PUT + Audit |
| **4j-3** | Admin-UI Matrix Plattform × 5 Medien |
| **4j-4** | Fachliche Plattform-/Rule-Befüllung (eigene PO-Freigabe) |
| **4j-5** | Enforce in `OaTkpWriterPositionResolver::resolvePlatformAllocations` (Pin/Inaktiv analog) |
| **4j-6** | Wizard-Filter + Mediumwechsel-Cleanup + E2E/Regression |

**Wichtig:** Das Rule-Modell existiert, wird aber noch **nicht** operativ erzwungen.

## Tests

`PoCalcTkp1d4c4j1PlatformMediumRulesTest` – SQLite + MySQL (`dispo_test`).

## STOP (historischer Pre-Merge-Gate, 31.10.2026)

Zum Auftragzeitpunkt: kein Merge ohne PO-Abnahme. Keine Migration auf
`dispo_mat_core`, kein Enforcement, keine Admin-UI, kein Wizard-Filter, kein Deploy,
keine Registry-Freigabe.

Nachzug: Merge und PO-Abnahme sind erfolgt (PR #204). Die übrigen Grenzen gelten
weiter: kein `dispo_mat_core`-Migrate aus 4j-1, kein Enforcement, keine Admin-Matrix,
kein Wizard-Filter, kein Deploy, keine Registry-Freigabe, keine Fachdaten.
