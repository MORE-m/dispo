# PO-CALC-TKP-1D4c-4j-2/3 – Admin-Matrix Plattform × Medium

Stand: 3. November 2026 (Feature-Branch, noch nicht gemergt)

Base: `607fd6096c8f26549d8aca36e58f931c44196b99` (PR #205)
Pre-Rebase-Feature: `d6feefd4143afef96beee22a5c02775d86152ba0`
Branch: `feat/po-calc-tkp-1d4c4j23-platform-medium-admin`
Worktree: `dispo-wt-po-calc-tkp-1d4c4j23`

Kanonische Folge bleibt: **4j-4** Fachdaten → **4j-5** Enforcement → **4j-6** Wizard/E2E.

## Ziel

Administratoren pflegen, welche der fünf OA-Kernmedien einer Plattform zugeordnet
sind. Die Pflege ist Stammdatenpflege. Sie erzwingt die Zuordnung in Kalkulation
und Wizard noch nicht.

## Umgesetzt

| Baustein | Status |
|---|---|
| Gate `manage-oa-tkp-master-data` (nur Admin) | ja |
| GET `/administration/online-audio/plattform-medien` (JSON oder Inertia) | ja |
| PUT Full Desired State (`cells` = jede Plattform × fünf Kernmedien) | ja |
| `revision_token` (SHA-256 über Plattform-IDs und Rule-Paare) | ja |
| 409 bei Token-Drift (`OaTkpAdminConflictException`) | ja |
| Soft-Deactivate über `is_active`; fehlende inaktive Paare legen keine Zeile an | ja |
| Audit `oa_platform_medium_rule.created` / `.updated` über bestehenden `AuditLogger` | ja |
| Historie der letzten 100 Rule-Audits | ja |
| DELETE → 405, kein Hard-Delete | ja |
| Hub-Karte „Plattform × Medium“ | ja |
| Dirty-State und Speichern in der Inertia-Matrix | ja |
| `native_ad` und Nicht-Kernmedien abgewiesen | ja |

## Ausdrücklich noch nicht

- 4j-4 fachliche Plattform- oder Rule-Befüllung
- 4j-5 Resolver-Enforcement (`OaTkpWriterPositionResolver` unverändert)
- 4j-6 Wizard-Filter, Mediumwechsel-Cleanup, E2E
- Registry-Release (`oa_tkp` / `tkp` bleibt `planned`)
- Migration auf `dispo_mat_core`
- Plattform-Seeds, keine Spotify-/YouTube-/Deezer-Annahmen
- Preiswirkung von Plattformen (`FD-OA-PLAT` separat)
- Änderung an Calc-/Dispo-Snapshots, Targetings oder Preislogik

Leere Plattformliste bleibt leer. Inaktive Plattformen dürfen Rules tragen;
operativ zählen sie erst bei aktiver Plattform und späterem Enforcement.

## Tests (lokal, 03.11.2026, nach Rebase auf PR #205)

- `PoCalcTkp1d4c4j2PlatformMediumRuleAdminTest` + `PoCalcTkp1d4c4dAdminUiTest`:
  SQLite 20/20, MySQL `dispo_test` 20/20
- Vitest `resources/js/lib/oa-tkp-admin.test.ts`: 8/8
- `npm run check`, `tsc --noEmit`, Pint, PHPStan (Repo-Pfade) grün

## STOP

Kein Merge ohne PO-Abnahme. Kein Enforcement, keine Fachdaten, kein Deploy,
keine Registry-Freigabe, kein `dispo_mat_core`-Migrate.
