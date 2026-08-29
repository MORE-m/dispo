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
| 29.08.2026 | Kontoselbstlöschung | Keine DELETE-Route `/settings/profile`; Konten nur administrativ | Phase-0-Nacharbeit, kein Self-Service-Löschen |
| 29.08.2026 | Spot Classic | Tatsächliche Spotlänge je Position frei editierbar; Standardlänge nur Vorbelegung; keine gruppierten Zeitschienen | SPT-015, SPT-016 |
| 29.08.2026 | Mehrsender | Eine Kalkulation parallel mehrere Sender/Kombis; Live-Summe; Beispiel 10 RH + 5 ROCK ANTENNE Hamburg | CAL-001, CAL-005 |
| 29.08.2026 | Produktmanagement | Neue Rolle für Standardangebote; kein automatischer Zugriff auf Kundenkalkulationen/Dispo | AUTH-006, AUTH-007 |
| 29.08.2026 | Standardangebote | Versionierte Vorlagen ohne Kundenbindung; Übernahme als Snapshot; Dispo nur aus Kundenkalkulation | STD-001–STD-009, DSP-007 |
| 29.08.2026 | UX/UI-Gates | `BL-GATE-UXUI` durch UX-GATE-A/B/C/D ersetzt; A und B freigegeben, C und D blockiert | Product-Owner-Auftrag 29.08.2026 |
| 29.08.2026 | Navigation | Linke Navigation: Übersicht, Kalkulationen, Standardangebote, Dispoaufträge, Auswertungen, Stammdaten, Administration; keine obere Hauptnavigation | UX-GATE-A |
| 29.08.2026 | Budgetpfade | Zwei Wege: Selbst planen (Zielbudget nur Vergleich) und Mit Budget planen (neuer Vorschlag). Kein bestehendes Senderverhältnis. Zielgröße N/N-Invest. V1-Logiken: gleich verteilen, Spotanzahl maximieren. | BUD-001–BUD-009, PO 29.08.2026 |
| 29.08.2026 | Wizard | Schritte Grunddaten, Werbeelemente, Konditionen, Zusammenfassung; Briefing optional | UX-GATE-B |
| 29.08.2026 | CRM im Slice | Ohne Kundenstammdaten: optionale Freitextfelder Kunde/Agentur; keine erfundenen CRM-Datensätze | Slice-Abgrenzung, CRM in Phase 2 |
| 29.08.2026 | Rabattgrenze | `null` am Benutzer = keine persönliche Grenze; Überschreitung markiert `requires_special_approval`, Freigabe-UI bleibt UX-GATE-D | COM-002, COM-003 |
| 29.08.2026 | Live-Summe | Frontend darf Vorschau anzeigen; autoritativ ist `POST` Preview/Save auf dem Server | GEN-001, GEN-002 |
| 29.08.2026 | Budget Stundenverteilung | Stunden nur Preisbasis; Budgetvorschlag setzt nur `total_spot_count` je Position; BLK-007 aufgelöst | PO 29.08.2026, Review-Nacharbeit v3 |
| 29.08.2026 | Kalkulationsarten | Planungsweg (manual/budget) ≠ Spot-Methode (average/calendar/fixed); Gate B nur Durchschnitt | CAL-002, Review-Nacharbeit |
| 29.08.2026 | CRM Slice | Freitext Kunde/Agentur temporär; CRM-001 nicht als erledigt markiert | Slice-Abgrenzung |
| 29.08.2026 | Technische Abnahme UX-GATE-A/B | Erst nach grüner CI auf Nacharbeit-Commit; alte Läufe kein Nachweis | Review-Nacharbeit |
| 29.08.2026 | Spot-Classic-Zeilen | Durchschnitt: eindeutige Preisstunden + Gesamtspotanzahl; kein Kalender in Gate B | SPT-001–SPT-004, SPT-016 |
| 29.08.2026 | Historische Snapshots | Unveränderte Positionen nutzen gespeicherte Preise/Regeln auch bei deaktivierten Stammdaten; Wechsel nur über aktive Kombinationen | PRI-004, VER-002, Review v4 |
| 29.08.2026 | Spotlängenindex | Gespeicherter `length_index` bei unveränderter Länge; Neuberechnung bei Längenänderung | SPT-009, Review v4 |
| 29.08.2026 | client_key nullable | Schema nullable aus Migrationskompatibilität; Writer setzt UUID verbindlich | Review v4 |

## Offene Blocker

| ID | Betrifft | Beschreibung | Wirkung |
|---|---|---|---|
| BLK-005 | UX-GATE-C | Trailer/SWF, Influencer, Social Media und weitere Werbeelement-Oberflächen nicht freigegeben | Keine Fachseiten für diese Elemente |
| BLK-006 | UX-GATE-D | Dispo, Freigaben, Standardangebots-Fach-UI, Administration der Initialkataloge nicht freigegeben | Nur Sperrzustände in der Navigation |
| BLK-001 | BL-P11-01 | Initialkataloge Kapitel 27 noch nicht als geprüfte Lieferdaten im Repo | Produktivsetzung; UI zeigt Leerzustände |
| BLK-002 | BL-P11-01 | Speedit-Parameter (Domain, SMTP, SSH-Pfad, MySQL-Version, Cron, Backup, PHP-Extensions, Speicher, **PHP-CLI-Pfad**) unverifiziert | Produktiv-Deploy |

## Erledigte Blocker

| ID | Betrifft | Auflösung |
|---|---|---|
| BLK-004 | BL-P0-04 | GitHub-Actions-Jobs `ci` und `mysql` auf Branch `phase-0-abschluss` (Lauf [33232656289](https://github.com/MORE-m/dispo/actions/runs/33232656289), Commit `4915baa`) beide `success` |
| BLK-003 | BL-GATE-UXUI | Ersetzt durch UX-GATE-A/B/C/D. A und B freigegeben; Rest in BLK-005/BLK-006 |
| BLK-007 | UX-GATE-B Budget Durchschnitt | PO-Entscheidung 29.08.2026: Stunden nur Preisbasis; Budgetvorschlag setzt nur `total_spot_count` je Position | Review-Nacharbeit v3 |

Phase 0 bleibt technisch endgültig abgenommen. UX-GATE-A/B dürfen Fachoberflächen
im freigegebenen Umfang umsetzen.
