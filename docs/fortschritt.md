# Fortschritt V1

Stand: 29. August 2026 (Review-Nacharbeit UX-GATE-A/B v6)

## Aktuelle Phase

Phase 0 bleibt technisch abgenommen. `UX-GATE-A` und `UX-GATE-B` sind **fachlich
freigegeben**. Die **technische Abnahme** von UX-GATE-A/B bleibt an grüne CI auf
dem finalen Nacharbeit-HEAD gebunden.

## Aktuelle Aufgabe

Review-Nacharbeit v6: atomare Nummernvergabe (`TEC-001`), isolierter MySQL-Paralleltest,
Review-Export per `git archive`, Doku-Stand v5/v6. Commit/Push/PR und grüne CI
ausstehend.

## Zuletzt abgeschlossene Aufgabe

Review-Nacharbeit v5: Playwright CAL-001/BUD-008, `length_index` im Budgetpfad,
historische Katalogdarstellung, Snapshot-/Negativtests, `client_key`-Backfill,
deterministische Migration, MySQL-Paralleltest über `CalculationWriter::create()`
(Commit `bab2477`).

## Technische Abnahme UX-GATE-A/B

| Kriterium | Status |
|---|---|
| Fachliche Freigabe PO | **freigegeben** (UX-GATE-A/B) |
| Durchschnittskalkulation (SPT-001–SPT-004, SPT-016) | umgesetzt |
| Kalenderplaner (SPT-005–SPT-008) | bewusst offen |
| Festpreis Spot | bewusst offen (kein Gate-B-Umfang) |
| Preislisten-Snapshot / keine Rückwirkung (`PRI-004`) | umgesetzt |
| Historische Positionen bei deaktivierten Stammdaten | umgesetzt (v4/v5) |
| `length_index`-Snapshot (`SPT-009`) | umgesetzt (v4/v5) |
| Budgetvorschlag + Übernahme + lock_version | umgesetzt |
| Atomare Nummernvergabe (`TEC-001`) | umgesetzt (v6) |
| Read-only-Zusammenfassung | umgesetzt |
| GitHub Actions `tests.yml` gültig | repariert |
| CI `ci` + `mysql` auf HEAD | **ausstehend bis Push/PR grün** |

## Ausgeführte Prüfungen (Nacharbeit v6, lokal)

- `composer validate`: gültig
- `vendor/bin/pint --test`: bestanden
- Pest (SQLite): siehe Abschlusslauf
- PHPStan: 0 Fehler
- `npm run check`, `npm run types:check`, Vitest, Build: bestanden
- Playwright: 5/5 lokal
- MySQL-Paralleltest: im GitHub-Job `mysql` (lokal ohne MySQL-Server übersprungen)

**Technische Abnahme UX-GATE-A/B:** ausstehend bis GitHub Actions `ci` und `mysql`
auf finalem HEAD grün.

## Migrationen (Nacharbeit)

| Migration | Zweck |
|---|---|
| `2026_08_29_140000_calculation_review_nacharbeit.php` | `client_key`, `spot_method`, `total_spot_count` |
| `2026_08_29_150000_calculation_snapshot_and_sequences.php` | Snapshot-Spalten, Sequenztabelle, Backfill |
| `2026_08_29_160000_backfill_length_index.php` | deterministischer `length_index`-Backfill |

`client_key` bleibt schema-seitig nullable; Writer erzwingt Werte für alle neuen
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

Commit, Push, PR gegen `phase-0-abschluss`, grüne CI abwarten, Review-ZIP v6
außerhalb des Repos mit Manifest = HEAD.
