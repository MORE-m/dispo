# PO-CALC-TKP-1D4c-4j-1 – Platform×Medium-Rules (Datenmodell)

Stand: 31. Oktober 2026 (Feature-Branch, Draft-PR)  
Base: `391eaf440a0ae4b0c7ae2fcc088b890d629cd067` (PR #200)  
Branch: `feat/po-calc-tkp-1d4c4j1-platform-medium-rules`  
Worktree: `dispo-wt-po-calc-tkp-1d4c4j1`  
Discovery: `PO-CALC-TKP-1D4c4j-discovery-2026-10-30.md`

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

## Folgeslices (nur Schnittstelle)

| Slice | Inhalt |
|---|---|
| **4j-2** | Admin-Backend Rules CRUD/Matrix-PUT + Audit |
| **4j-3** | Admin-UI Matrix Plattform × 5 Medien |
| **4j-4** | Fachliche Plattformbefüllung (eigene PO) |
| **4j-5** | Enforce in `OaTkpWriterPositionResolver::resolvePlatformAllocations` (Pin/Inaktiv analog) |
| **4j-6** | Wizard-Filter + Mediumwechsel-Cleanup + E2E |

**Wichtig:** Das Rule-Modell existiert, wird aber noch **nicht** operativ erzwungen.

## Tests

`PoCalcTkp1d4c4j1PlatformMediumRulesTest` – SQLite + MySQL (`dispo_test`).

## STOP

Kein Merge ohne PO-Abnahme. Keine Migration auf `dispo_mat_core`, kein Enforcement,
keine Admin-UI, kein Wizard-Filter, kein Deploy, keine Registry-Freigabe.
