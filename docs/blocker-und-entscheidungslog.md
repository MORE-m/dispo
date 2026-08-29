# Blocker- und Entscheidungslog

Technische Detailentscheidungen innerhalb akzeptierter ADRs und echte Blocker.
Keine stillschweigenden ADR-Änderungen.

## Entscheidungslog

| Datum | Thema | Entscheidung | Grundlage |
|---|---|---|---|
| 29.08.2026 | PHP | 8.3 Mindest- und Produktionsziel (Speedit); 8.4 nicht Voraussetzung | ADR-002 Konkretisierung |
| 29.08.2026 | Dateispeicher | Laravel Filesystem, lokal privat, Adapter austauschbar | ADR-001, ADR-002 |
| 29.08.2026 | Redis | kein V1-Muss; Queue/Cache/Sessions über Datenbank | ADR-002, ADR-003 |
| 29.08.2026 | Hosting | Speedit Apache, Cron-Scheduler, `queue:work --stop-when-empty` ohne Überlappung | ADR-003 |
| 29.08.2026 | Tests | Pest, Vitest/Testing Library, Playwright-Smoke, GitHub Actions | ADR-002 |
| 29.08.2026 | Auth | kein öffentliches Self-Registration, keine verpflichtende 2FA | ADR-002, Anforderungskatalog Kap. 3 |
| 29.08.2026 | UX/UI-Gate | Keine endgültigen Fachseiten vor PO-Freigabe; Headless erlaubt | Auftrag 29.08.2026, BL-GATE-UXUI |
| 29.08.2026 | Kontoselbstlöschung | Keine DELETE-Route `/settings/profile`; Konten nur administrativ | Phase-0-Nacharbeit, kein Self-Service-Löschen |

## Offene Blocker

| ID | Betrifft | Beschreibung | Wirkung |
|---|---|---|---|
| BLK-003 | BL-GATE-UXUI, Phase-1-Oberflächen | Visuelles Designsystem, App-Shell, Muster, Wizard, Assistenten, Zustände und UX-Abnahme mit Product Owner ausstehend | Keine endgültigen Fachseiten; Phase 0 ist davon nicht betroffen |
| BLK-001 | BL-P11-01 | Initialkataloge Kapitel 27 noch nicht als geprüfte Lieferdaten im Repo | Produktivsetzung |
| BLK-002 | BL-P11-01 | Speedit-Parameter (Domain, SMTP, SSH-Pfad, MySQL-Version, Cron, Backup, PHP-Extensions, Speicher, **PHP-CLI-Pfad**) unverifiziert | Produktiv-Deploy |
| BLK-004 | BL-P0-04 | GitHub-Actions-Jobs `ci` und `mysql` nach Push/PR noch nicht als grün nachgewiesen | Phase 0 nicht endgültig abgenommen |

Phase 0 ist lokal nachgearbeitet, aber **nicht endgültig abgenommen** (BLK-004).
Phase-1-Fachoberflächen warten auf BLK-003. Headless-Technik darf vorbereitet werden,
ersetzt das Gate nicht.

