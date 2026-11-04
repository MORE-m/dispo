# PO-CALC-TKP-1D4c-4j-4a – Plattform-Grundbestand

Stand: 4. November 2026
Repo: auf `main` (PR **#208**, Merge `6e0688dbba14b761260758ac852da12b3db7b05d`;
Feature-HEAD `9c8b225256608ab6f0b53ff6e029f109d6fabea5`; Post-Merge-CI
[`41815398103`](https://github.com/MORE-m/dispo/actions/runs/41815398103) success)
Lokaler Datenlauf: `dispo_mat_core` (kontrolliert, kein Deploy)
PO-Vertrag: `PO-CALC-TKP-1D4c4j4-po-entscheidungsvorlage-2026-11-03.md`

## Ziel

Versionierter, zunächst **inaktiver** OA-Plattform-Grundbestand für Midroll:

| Anzeigename | Code | Sort | is_active | is_adserver_relevant |
|---|---|---:|---:|---:|
| Spotify | `spotify` | 10 | `false` | `false` |
| YouTube | `youtube` | 20 | `false` | `false` |
| Deezer | `deezer` | 30 | `false` | `false` |

Keine Platform×Medium-Rules in diesem Slice. Keine Aktivierung.

## Repo-Scope (PR #208)

| Baustein | Status |
|---|---|
| Lieferdatei `oa-platforms.4j4a-midroll-grundbestand.import.json` | auf `main` |
| Kompatibel zu `oa:import-platforms` | ja |
| Neue Migration / Importer-Änderung | **nein** |

## Lokaler Datenlauf (erledigt 04.11.2026)

Backup: `local-backups/po-calc-tkp-1d4c4j4a-20261104-124313/` (nicht in Git;
SHA-256 `1f2d34c3f288034c83c6574265aa5bb7ccf429517ca3878512703f4c6b47efbe`).

| Schritt | Ergebnis |
|---|---|
| `DB_DATABASE=dispo_mat_core` | bestätigt |
| Pending vor Lauf | nur `2026_10_31_100000_po_calc_tkp_1d4c4j1_oa_platform_medium_rules` |
| Pre-State | `oa_platforms=0`; Rules-Tabelle fehlte; Preisliste 2027.1 Draft / 41 Items; Targetings dmp+geo; Adserver 0; Allocations 0; Registry `planned` |
| Checkpoint A | `migrate --path=…4j1_oa_platform_medium_rules.php --force` → Tabelle da, Unique/FKs `RESTRICT`, Rules=0, Platforms=0 |
| Dry-Run | `rows=3 dry_run=yes`; Platforms weiterhin 0 |
| Checkpoint B | Import `created=3`; exakt drei inaktive Plattformen |
| Rules nach Import | **0** |
| Admin-Matrix (read-only) | 3 Inaktiv-Zeilen × 5 Medien; alle Cells false / `rule_id=null` |

## Noch nicht

- Rules / Midroll-Matrix (**4j-4b**)
- Aktivierung der drei Plattformen (Teil 4j-4b nach Rule-Prüfung)
- 4j-5 Resolver-Enforcement
- Wizard-Filter / Mediumwechsel-Cleanup
- Registry-Release
- OA-004 / Adserver-Aufschlag
- Preiswirkung / `FD-OA-PLAT`
- Änderung historischer Snapshots
- Deploy

## Lieferdatei

`docs/readiness/import-templates/oa-platforms.4j4a-midroll-grundbestand.import.json`

## STOP

4j-4a lokal abgeschlossen. Kein 4j-4b, kein 4j-5 in diesem Auftrag.
