# PO-CALC-TKP-1D4c-4j-4a – Plattform-Grundbestand (Repo-Vertrag)

Stand: 4. November 2026 – **Repo-Slice** (Feature-Branch)  
Base: `3ab7b41aa11124c414ffbbf5bdc1b4d804106130` (PR #207)  
Branch: `feat/po-calc-tkp-1d4c4j4a-platform-base-data`  
Worktree: `dispo-wt-po-calc-tkp-1d4c4j4a`  
PO-Vertrag: `PO-CALC-TKP-1D4c4j4-po-entscheidungsvorlage-2026-11-03.md`

## Ziel

Versionierter, zunächst **inaktiver** OA-Plattform-Grundbestand für Midroll:

| Anzeigename | Code | Sort | is_active | is_adserver_relevant |
|---|---|---:|---:|---:|
| Spotify | `spotify` | 10 | `false` | `false` |
| YouTube | `youtube` | 20 | `false` | `false` |
| Deezer | `deezer` | 30 | `false` | `false` |

Keine Platform×Medium-Rules in diesem Slice. Keine Aktivierung.

## Repo-Scope (dieser PR)

| Baustein | Status |
|---|---|
| Lieferdatei `oa-platforms.4j4a-midroll-grundbestand.import.json` | ja |
| Kompatibel zu `oa:import-platforms` (JSON-Array) | ja |
| Dry-Run-Validierung (ohne Mutation) | vorgesehen / dokumentiert |
| Auftrags-/Statusdoku | ja |
| Neue Migration | **nein** (4j-1 liegt bereits auf `main`) |
| Importer-/CLI-Änderung | **nein** |
| Echter Import / Schema-Migrate auf `dispo_mat_core` | **nein** (separater Datenlauf) |

## Nach Merge dieses Repo-PRs – operativer Datenlauf (separater Auftrag)

Nur auf ausdrücklich freigegebener DB `dispo_mat_core`, nach Backup:

1. Backup  
2. `DB_DATABASE` prüfen (`dispo_mat_core`)  
3. Pre-State lesen (`oa_platforms` = 0; Rules-Tabelle fehlt; pending nur 4j-1)  
4. Gezielte Migration:
   `php artisan migrate --path=database/migrations/2026_10_31_100000_po_calc_tkp_1d4c4j1_oa_platform_medium_rules.php --force`  
5. Schema-Read-back (Tabelle da, Migration registriert, Platforms/Rules = 0)  
6. Plattform-Pre-State erneut prüfen (weiterhin 0, sonst STOP)  
7. Dry-Run:
   `php artisan oa:import-platforms docs/readiness/import-templates/oa-platforms.4j4a-midroll-grundbestand.import.json --dry-run`  
8. Echter Import (ohne `--dry-run`)  
9. Daten-Read-back (exakt drei inaktive Plattformen; Rules = 0; übrige OA-Daten unverändert; Registry `planned`)  
10. **STOP** vor 4j-4b

## Noch nicht

- Rules / Midroll-Matrix (4j-4b)
- Aktivierung der drei Plattformen (Teil 4j-4b nach Rule-Prüfung)
- 4j-5 Resolver-Enforcement
- Wizard-Filter / Mediumwechsel-Cleanup
- Registry-Release
- OA-004 / Adserver-Aufschlag
- Preiswirkung / `FD-OA-PLAT`
- Änderung historischer Snapshots

## Lieferdatei

`docs/readiness/import-templates/oa-platforms.4j4a-midroll-grundbestand.import.json`

Importvertrag: JSON-Array → `OaPlatformImporter` via `oa:import-platforms`.  
`is_active=false` und `is_adserver_relevant=false` sind **explizit** gesetzt (Importer-Default für `is_active` wäre sonst `true`).

## STOP

Kein Merge ohne CI. Kein operativer Datenlauf in diesem Repo-PR. Kein 4j-4b, kein 4j-5.
