# Fortschritt V1

Stand: 29. August 2026 (Review-Nacharbeit UX-GATE-A/B)

## Aktuelle Phase

Phase 0 bleibt technisch abgenommen. `UX-GATE-A` und `UX-GATE-B` sind **fachlich
freigegeben**. Die **technische Abnahme** von UX-GATE-A/B bleibt an grüne CI auf
dem Nacharbeit-Commit gebunden (siehe unten).

## Aktuelle Aufgabe

Review-Nacharbeit UX-GATE-A/B: Durchschnittskalkulation, Preislisten-Snapshot,
Budgetvorschlag, Read-only, Audit, Tests und Workflow-Korrektur.

## Zuletzt abgeschlossene Aufgabe

Review-Nacharbeit (Code): siehe Commit auf Branch `ux-gate-a-b-review-nacharbeit`.

## Technische Abnahme UX-GATE-A/B

| Kriterium | Status |
|---|---|
| Fachliche Freigabe PO | **freigegeben** (UX-GATE-A/B) |
| Durchschnittskalkulation (SPT-001–SPT-004, SPT-016) | umgesetzt |
| Kalenderplaner (SPT-005–SPT-008) | bewusst offen |
| Festpreis Spot | bewusst offen (kein Gate-B-Umfang) |
| Preislisten-Snapshot / keine Rückwirkung | umgesetzt |
| Budgetvorschlag + Übernahme + lock_version | umgesetzt |
| Read-only-Zusammenfassung | umgesetzt |
| GitHub Actions `tests.yml` gültig | repariert |
| CI `ci` + `mysql` auf HEAD | **ausstehend bis Push/PR grün** |

## Ausgeführte Prüfungen (Nacharbeit, lokal)

Stand nach Commit `9aaf6b4` auf Branch `ux-gate-a-b-review-nacharbeit`:

- `.github/workflows/tests.yml`: YAML-Syntax repariert (extensions-Einrückung)
- `composer validate`: gültig
- Pest (SQLite): **62 bestanden**, 3 übersprungen, 202 Assertions
- Pint, PHPStan (0 Fehler): bestanden
- `npm run check`, `npm run types:check`: bestanden
- Vitest: **5/5** bestanden
- Produktionsbuild: bestanden
- Playwright: erweitert (CAL-001, BUD-008); Lauf lokal/CI nach Push
- `composer audit` / `npm audit --omit=dev`: ausstehend im CI-Lauf

**Technische Abnahme UX-GATE-A/B:** ausstehend bis GitHub Actions `ci` und `mysql` auf HEAD grün (PR noch anzulegen).

## Bewusst offen / temporär

| Thema | Status |
|---|---|
| CRM-001 | **nicht erfüllt** – Kalkulation nutzt Freitextfelder Kunde/Agentur (Slice) |
| SPT-005–SPT-008 Kalenderplaner | offen (UX-GATE-B nicht freigegeben für Planer-UI) |
| Festpreis Spot | offen, nicht vortäuschen |
| BLK-007 Budget-Stundenverteilung innerhalb Sender | PO-Entscheidung ausstehend |
| Headless-Stammdaten, Benachrichtigungen | nicht begonnen (Auftrag) |
| UX-GATE-C/D | blockiert (BLK-005/006) |

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-005 | UX-GATE-C |
| BLK-006 | UX-GATE-D |
| BLK-007 | Budgetvorschlag: sinnvolle Stundenverteilung innerhalb Sender (Greedy dokumentiert) |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy |

## Exakt nächste ausführbare Aufgabe

Nach grüner CI: technische Abnahme UX-GATE-A/B markieren. Anschließend erst
Headless-Stammdaten oder BL-P1-05 – nicht vor grüner Nacharbeit-CI.
