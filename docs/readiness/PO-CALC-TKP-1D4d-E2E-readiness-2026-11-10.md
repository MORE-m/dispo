# PO-CALC-TKP-1D4d-E2E – Readiness 2026-11-10 (Integration)

## Stack (lokale Integration)

| | |
|---|---|
| Basis | UI-WIRE `014f4c5` + REL-Commits + Lintfix |
| Branch | `test/po-calc-tkp-1d4d-e2e-release-integration` |
| Worktree | `dispo-wt-po-calc-tkp-1d4d-e2e-integration` |
| UI-WIRE PR | `#225` (CI; nicht mergen in diesem Auftrag) |
| REL PR | `#224` (nicht mergen; lokal lint-fix ahead) |

Kein Push. Kein PR. Kein Merge. Kein Deploy.

## Historischer STOP (vormittags)

Browser-Blocker: React band `showActivePickers` an Soft-Open-Capability.
Nach Registry-Release ohne Capability blieben Metadata/Picker zu.

## Auflösung

UI-WIRE `#225` liefert `oa_tkp_preview_surface_allowed` und koppelt Picker an die Preview-Surface.

Dieser Integrationslauf prüft erstmals den **echten Releasezustand**:

```text
Capability = false
Registry = released / v1
Preview Surface = true
HTTP Mutation = true
```

Ohne `OA_TKP_WIZARD_CAPABILITY=true` als Ersatz.

## Isolation

Playwright-Config Port **8075**, SQLite
`database/e2e-po-calc-tkp-1d4d-release-smoke.sqlite`.
Niemals `dispo_mat_core`.

## Spec / Seeder

- `tests/e2e/po-calc-tkp-1d4d-release-smoke.spec.ts`
- `playwright.po-calc-tkp-1d4d-e2e.config.ts`
- `database/seeders/E2EOaTkpReleaseSmokeSeeder.php`
