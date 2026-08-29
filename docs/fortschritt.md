# Fortschritt V1

Stand: 29. August 2026 (Review-Nacharbeit UX-GATE-A/B v4)

## Aktuelle Phase

Phase 0 bleibt technisch abgenommen. `UX-GATE-A` und `UX-GATE-B` sind **fachlich
freigegeben**. Die **technische Abnahme** von UX-GATE-A/B bleibt an grüne CI auf
dem finalen Nacharbeit-HEAD gebunden.

## Aktuelle Aufgabe

Review-Nacharbeit v4: historische Snapshots bei deaktivierten Stammdaten,
`length_index`-Snapshot, Traceability PRI-004/PRI-006, echter MySQL-Paralleltest,
Budget-Doppelübernahme. Commit/Push/PR und grüne CI ausstehend.

## Zuletzt abgeschlossene Aufgabe

Review-Nacharbeit v3: BLK-007, Durchschnittspreis-Snapshot, Sequenznummern,
Review-ZIP v3 (Commit `5f71013`).

## Technische Abnahme UX-GATE-A/B

| Kriterium | Status |
|---|---|
| Fachliche Freigabe PO | **freigegeben** (UX-GATE-A/B) |
| Durchschnittskalkulation (SPT-001–SPT-004, SPT-016) | umgesetzt |
| Kalenderplaner (SPT-005–SPT-008) | bewusst offen |
| Festpreis Spot | bewusst offen (kein Gate-B-Umfang) |
| Preislisten-Snapshot / keine Rückwirkung (`PRI-004`) | umgesetzt |
| Historische Positionen bei deaktivierten Stammdaten | umgesetzt (v4) |
| `length_index`-Snapshot (`SPT-009`) | umgesetzt (v4) |
| Budgetvorschlag + Übernahme + lock_version | umgesetzt |
| Read-only-Zusammenfassung | umgesetzt |
| GitHub Actions `tests.yml` gültig | repariert |
| CI `ci` + `mysql` auf HEAD | **ausstehend bis Push/PR grün** |

## Ausgeführte Prüfungen (Nacharbeit v4, lokal)

- `composer validate`: gültig
- `vendor/bin/pint --test`: bestanden
- Pest (SQLite): **79 bestanden**, 4 übersprungen
- PHPStan: 0 Fehler
- `npm run check`, `npm run types:check`, Vitest **5/5**, Build: bestanden
- MySQL-Pest + Paralleltest: im GitHub-Job `mysql` (lokal ohne MySQL-Server)
- Playwright: nach Commit/CI

**Technische Abnahme UX-GATE-A/B:** ausstehend bis GitHub Actions `ci` und `mysql`
auf finalem HEAD grün.

## Migrationen (Nacharbeit)

| Migration | Zweck |
|---|---|
| `2026_08_29_140000_calculation_review_nacharbeit.php` | `client_key`, `spot_method`, `total_spot_count` |
| `2026_08_29_150000_calculation_snapshot_and_sequences.php` | Snapshot-Spalten, Sequenztabelle, Backfill |
| `2026_08_29_160000_backfill_length_index.php` | `length_index` aus gespeicherter Länge |

`client_key` bleibt schema-seitig nullable (kein `doctrine/dbal` für sicheres
`NOT NULL` nach Backfill auf SQLite/MySQL); Writer erzwingt Werte für alle neuen
und aktualisierten Positionen.

## Bewusst offen / temporär

| Thema | Status |
|---|---|
| CRM-001 | **nicht erfüllt** – Freitext Kunde/Agentur |
| Anzeigenamen-Snapshot auf Position | Gate-B-Grenze dokumentiert |
| SPT-005–SPT-008 Kalenderplaner | offen |
| Festpreis Spot | offen |
| Headless-Stammdaten, Benachrichtigungen | nicht begonnen |
| UX-GATE-C/D | blockiert (BLK-005/006) |

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-005 | UX-GATE-C |
| BLK-006 | UX-GATE-D |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy |

## Exakt nächste ausführbare Aufgabe

Commit, Push, PR gegen `phase-0-abschluss`, grüne CI abwarten, Review-ZIP v4
mit Manifest = HEAD.
