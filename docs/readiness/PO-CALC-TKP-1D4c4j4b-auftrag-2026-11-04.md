# PO-CALC-TKP-1D4c-4j-4b – Midroll-Rules + Aktivierung

Stand: 4. November 2026
Repo-Doku: dieser Auftrag (Docs-only; kein Feature-Code)
Vorbedingung: PR **#209** auf `main` (Merge `5dfa646bb7f4d44617f2f8fbc040183b2a114038`;
Feature-HEAD `0d9e82e329e90b1044bd24ff8832c134ce479e1b`; Post-Merge-CI
[`41831598822`](https://github.com/MORE-m/dispo/actions/runs/41831598822) success:
`ci` / `mysql` / `e2e-spt008`)
Lokaler Datenlauf: `dispo_mat_core` (kontrolliert, kein Deploy)
PO-Vertrag: `PO-CALC-TKP-1D4c4j4-po-entscheidungsvorlage-2026-11-03.md`
Vorgänger: `PO-CALC-TKP-1D4c4j4a-auftrag-2026-11-04.md`

## Ziel

Nur Midroll-Zuordnungen und anschließende Plattform-Aktivierung:

| Plattform | Medium | Rule | is_active (Plattform) |
|---|---|---|---|
| Spotify (`spotify`) | `midroll_music_environment` | aktiv | `true` |
| YouTube (`youtube`) | `midroll_music_environment` | aktiv | `true` |
| Deezer (`deezer`) | `midroll_music_environment` | aktiv | `true` |

Keine Rules für Pre-/In-Stream (inkl. Influencer). Kein 4j-5. Kein Registry-Release.

## Lokaler Datenlauf (erledigt 04.11.2026)

Backup: `local-backups/po-calc-tkp-1d4c4j4b-20261104-144510/` (nicht in Git;
SHA-256 `a178cd155c0087fc62a12ef0cd1e1a46b006430ee2adf87a64941b119d137420`).

| Schritt | Ergebnis |
|---|---|
| `DB_DATABASE=dispo_mat_core` | bestätigt |
| Pre-State | 3 Plattformen inaktiv Sort 10/20/30; Rules=0; Midroll-Medium 1×; Preisliste Draft 1 / Items 41; Targetings 2; Adserver 0; Allocations 0; Snapshots 49; Registry `planned` |
| Actor | User ID 7 Admin, Gate `manage-oa-tkp-master-data` |
| Checkpoint A | HTTP `GET`+`PUT /administration/online-audio/plattform-medien` (15 Cells; nur 3× Midroll `true`) → HTTP 200; Rules=3 aktiv; Audit 3× `oa_platform_medium_rule.created` (Actor 7); Plattformen weiter inaktiv; `isActivelyAssigned` Midroll = false |
| Checkpoint B | HTTP `POST …/plattformen/{id}/aktivieren` Spotify → YouTube → Deezer; je Read-back + Audit `oa_platform.updated` (Actor 7); `is_adserver_relevant=false`; Rules weiterhin 3 |
| Endzustand | 3 aktive Plattformen; 3 aktive Midroll-Rules; andere Medien 0 Rules; Midroll-Helper true; Registry `planned` |
| Nebenwirkungen | Preislisten/Items/Targetings/Adserver/Allocations/Snapshots unverändert |
| Admin-Matrix Smoke | read-only: 3 aktive Zeilen; Midroll checked; andere unchecked; kein Save |

## Noch nicht / gesperrt

- Pre-Stream / In-Stream / Pre-Stream Influencer / In-Stream Influencer (PO-Entscheidung)
- **4j-5** Resolver-Enforcement
- **4j-6** Wizard-Filter / Mediumwechsel-Cleanup / E2E
- Registry-Release `oa_tkp`/`tkp`
- OA-004 / Adserver-Aufschlag
- Deploy

## STOP

4j-4b lokal abgeschlossen. **Kein** 4j-5. Nächster fachlicher Schritt:
PO-Entscheidung für Plattformzuordnungen der vier offenen Stream-/Influencer-Medien.
