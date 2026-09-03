# Fortschritt V1

Stand: 2. September 2026 (Dispoauftrag-Entwurf aus Kalkulation)

## Aktuelle Phase

Phase 8 (Dispoauftrag) – **Teilslice umgesetzt:** Entwurf aus Kalkulation mit
Snapshot, Nummerierung, Liste/Detail, Berechtigungen und Tests. UX-GATE-D ist
**nur für diesen Teilbereich** freigegeben; Freigabe- und Dispo-Workflow
bleiben offen.

Ausgangsbasis für den Slice: `main` @ `e6238c8`.

## Aktuelle Aufgabe

Branch `feat/dispo-order-draft-from-calculation`: Dispoauftrag im Status
`Entwurf` aus gespeicherter Kalkulation anlegen (PR ausstehend).

## Zuletzt abgeschlossene Aufgabe

Budgetplanung mit automatischer Spotverteilung (PR #10) und gehärteter
Dev-Server-Start (PR #11) auf `main` (`e6238c8`).

## Dispoauftrag-Entwurf (September 2026)

| Kriterium | Status |
|---|---|
| Dispoauftrag aus Kalkulation (`DSP-001`, `DSP-003`, `DSP-004`) | umgesetzt |
| Positionsauswahl inkl. „bereits übernommen“ | umgesetzt |
| Snapshot-Isolation (Kalkulation/Stammdaten) | umgesetzt + getestet |
| Nummernformat `DA-YYYY-NNNNNN-SS` (`TEC-001`, `TEC-002`) | umgesetzt |
| Rollen: Anlegen Admin/Vertrieb/GF; Lesen + Disposition | umgesetzt |
| Audit `dispo_order.created` | umgesetzt |
| UX-GATE-D gesamt | **nicht** abgeschlossen – nur Entwurfsteil freigegeben |
| Statusworkflow, Freigaben, Material, Kommentare | bewusst offen |

## Technische Abnahme UX-GATE-A/B

| Kriterium | Status |
|---|---|
| Fachliche Freigabe PO | **freigegeben** (UX-GATE-A/B) |
| Technische Abnahme | **abgenommen** (29.08.2026, HEAD `976aae5`) |
| Durchschnittskalkulation (SPT-001–SPT-004, SPT-016) | umgesetzt |
| Kalenderplaner (SPT-005–SPT-008) | bewusst offen |
| Festpreis Spot | bewusst offen (kein Gate-B-Umfang) |
| Preislisten-Snapshot / keine Rückwirkung (`PRI-004`) | umgesetzt |
| Historische Positionen bei deaktivierten Stammdaten | umgesetzt (v4/v5) |
| `length_index`-Snapshot (`SPT-009`) | umgesetzt (v4/v5) |
| Budgetvorschlag + Übernahme + lock_version | umgesetzt |
| Atomare Nummernvergabe (`TEC-001`) | umgesetzt (v6) |
| MySQL-Paralleltest (`CalculationWriter::create`) | ausgeführt und grün (v7) |
| Read-only-Zusammenfassung | umgesetzt |
| GitHub Actions `ci` + `mysql` auf Abnahme-HEAD | **grün** ([Run 33252415668](https://github.com/MORE-m/dispo/actions/runs/33252415668)) |

## Nachweis CI (technische Abnahme)

| Job | Ergebnis | Link |
|---|---|---|
| `ci` | success (1m37s) | [Job 99100268479](https://github.com/MORE-m/dispo/actions/runs/33252415668/job/99100268479) |
| `mysql` | success (58s), **95 passed** | [Job 99100268408](https://github.com/MORE-m/dispo/actions/runs/33252415668/job/99100268408) |

MySQL-Paralleltest `gen_001_mysql_parallel_workers_create_calculations_with_unique_numbers`:
ausgeführt (2,06s), nicht übersprungen.

## Review-Nacharbeit (v5/v6/v7, Kurzüberblick)

| Version | Inhalt |
|---|---|
| v5 | Playwright, `length_index`, historische Kataloge, Snapshot-Tests, `client_key`, deterministische Migration |
| v6 | Atomare Nummernvergabe (`TEC-001`), isolierter MySQL-Paralleltest, Export per `git archive` |
| v7 | MySQL-Paralleltest Process-API-Fix, technische Abnahme dokumentiert |

## Migrationen (Nacharbeit)

| Migration | Zweck |
|---|---|
| `2026_08_29_140000_calculation_review_nacharbeit.php` | `client_key`, `spot_method`, `total_spot_count` |
| `2026_08_29_150000_calculation_snapshot_and_sequences.php` | Snapshot-Spalten, Sequenztabelle, Backfill |
| `2026_08_29_160000_backfill_length_index.php` | deterministischer `length_index`-Backfill |
| `2026_08_31_100000_add_spot_time_ranges_and_layered_discounts.php` | Preiszeiträume, Rabattzeilen, AE-Flag, Bestands-Backfill |

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

PR #2 mergen (nach PO-Freigabe); UX-GATE-C/D und BLK-001/002 weiterhin offen.
