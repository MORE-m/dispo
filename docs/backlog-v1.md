# Backlog V1

Ausführbare Arbeitspakete aus [`umsetzungsplan.md`](umsetzungsplan.md).
Statuswerte: `offen`, `in Arbeit`, `blockiert`, `erledigt`.

Der Umsetzungsplan bleibt die Phasenübersicht; dieses Dokument steuert die Arbeit.

## Phase 0 – Entscheidungen und Projektbasis

### BL-P0-01 – Technologie- und Betriebs-ADRs

- **Phase:** 0
- **Status:** erledigt
- **Anforderungen:** `GEN-001`, `GEN-003`, Kapitel 23–24
- **Abhängigkeiten:** keine
- **Ergebnis:** ADR-001, ADR-002, ADR-003 akzeptiert und konkretisiert
- **Akzeptanz:** Stack, Dateispeicher, Queue ohne Redis, Speedit-Betrieb dokumentiert; keine stillschweigende ADR-Änderung
- **Tests:** nicht codebezogen

### BL-P0-02 – Autonome Arbeitssteuerung

- **Phase:** 0
- **Status:** erledigt
- **Anforderungen:** Arbeitsweise gemäß `AGENTS.md`
- **Abhängigkeiten:** keine
- **Ergebnis:** Backlog, Fortschritt, Entscheidungslog, `autonomous-execution.mdc`
- **Akzeptanz:** nächste Aufgabe ist ohne Rückfrage bestimmbar
- **Tests:** nicht codebezogen

### BL-P0-03 – Laravel-13-Projektbasis

- **Phase:** 0
- **Status:** erledigt
- **Anforderungen:** Kapitel 24 (Sicherheit, Betrieb), `GEN-003`
- **Abhängigkeiten:** BL-P0-01
- **Ergebnis:** Laravel 13.x im Repository-Root mit offiziellem React-/Inertia-Starter; Dokumentation und Cursor-Regeln erhalten; V1-Auth ohne Passkeys/2FA/E-Mail-Verifizierung; öffentliche Registrierung aus; Queue/Cache/Sessions über Datenbank; `.env.example` vollständig; lokale Entwicklungsanleitung; Health-Endpunkt; leerer Standardseeder; `proprietary`-Lizenz
- **Akzeptanz:** neuer Entwickler startet anhand README und führt einen Smoke-Test aus; keine produktiven Secrets
- **Tests:** Pest-Health/Smoke; Produktionsbuild der Assets

### BL-P0-04 – Qualitätssicherung und CI

- **Phase:** 0
- **Status:** erledigt
- **Anforderungen:** Kapitel 24, `GEN-001`
- **Abhängigkeiten:** BL-P0-03
- **Ergebnis:** Pint, PHPStan/Larastan, Pest (SQLite und MySQL-CI), Vitest, TypeScript, kanonisches `npm run check`, Playwright-Smoke, GitHub Actions inkl. MySQL-Job, Dependabot, Composer-/npm-Audit
- **Akzeptanz:** CI-Workflow läuft lokal nachbildbar und ist dokumentiert; GitHub-Actions-Jobs `ci` und `mysql` auf PR #1 grün nachgewiesen (BLK-004 erledigt)
- **Tests:** `pint --test`, PHPStan, Pest (SQLite/MySQL), `npm run check`, Vitest, `tsc`, Asset-Build; GitHub Actions `ci`/`mysql`

## UX/UI-Gate (zwischen Phase 0 und Phase 1)

### BL-GATE-UXUI – Verbindliches UX/UI-Gate

- **Phase:** Gate zwischen 0 und 1
- **Status:** blockiert
- **Anforderungen:** `GEN-003`, Kapitel 24 Barrierearmut/Browser, [`ui-ux-konzept.md`](ui-ux-konzept.md)
- **Abhängigkeiten:** BL-P0-04
- **Ergebnis:** mit dem Product Owner festgelegtes visuelles Designsystem, Navigation/App-Shell, Muster für Tabellen/Formulare/Filter, Statusdarstellung, Kalkulations-Wizard, Assistenten-/Pop-up-Logik, Desktop-/Tabletverhalten, Lade-/Leer-/Fehler-/Erfolgszustände und UX-Abnahmekriterien
- **Akzeptanz:** schriftliche Freigabe; Checkliste in [`ux-ui-gate.md`](ux-ui-gate.md)
- **Tests:** nach Freigabe UI-Abnahme gegen die Gate-Kriterien; bis dahin keine Fachseiten-Gestaltung
- **Blocker:** Product-Owner-Workshop und Artefakte fehlen (BLK-003)

Bis zur Freigabe: nur technische Grundlagen und Headless-Komponenten. Keine
endgültigen Fachseiten.

## Phase 1 – Anmeldung, Benutzer und Auditfundament

Fachliche Oberflächen dieser Phase hängen an `BL-GATE-UXUI`. Headless-Backend
darf vorbereitet werden, ersetzt das Gate aber nicht.

### BL-P1-01 – Lokaler Login und Passwort-Reset

- **Phase:** 1
- **Status:** blockiert
- **Anforderungen:** `AUTH-*` (Zugang), Kapitel 24 Sicherheit, kein öffentliches Self-Registration, keine verpflichtende 2FA
- **Abhängigkeiten:** BL-P0-04, BL-GATE-UXUI (für endgültige Login-/Reset-Oberfläche)
- **Ergebnis:** E-Mail/Passwort-Login, Logout, Passwort-Reset-Flow; Registrierung unerreichbar
- **Akzeptanz:** Unauthentifizierte Nutzer sehen interne Seiten nicht; Reset erzeugt kein Secret im Repo; UI erst nach Gate-Freigabe final
- **Tests:** Pest Feature-Tests Login Erfolg/Fehler, Reset, fehlende Registrierungsroute; UI-Smoke nach Gate
- **Blocker:** BL-GATE-UXUI

### BL-P1-02 – Rollen und Policy-Grundlage

- **Phase:** 1
- **Status:** offen
- **Anforderungen:** `AUTH-001` bis `AUTH-003`
- **Abhängigkeiten:** BL-P1-01 (Headless möglich vor UI-Freigabe)
- **Ergebnis:** Rollen Admin, Vertrieb, Disposition, Geschäftsführung; serverseitige Policies; **keine** Admin-Fachoberfläche vor Gate-Freigabe
- **Akzeptanz:** jede Rolle positiv und negativ getestet
- **Tests:** Pest Policies Erlaubnis/Verweigerung

### BL-P1-03 – Rabattgrenze und Sonderfreigaberecht

- **Phase:** 1
- **Status:** offen
- **Anforderungen:** `AUTH-001`, `COM-002`, `COM-003`, Sonderfreigabe Kapitel 4.2
- **Abhängigkeiten:** BL-P1-02
- **Ergebnis:** nutzerbezogene Rabattgrenze und Sonderfreigabe-Flag am Benutzer
- **Akzeptanz:** Werte nur admin-pflegbar; keine erfundenen Default-Grenzen außer dokumentiert offen
- **Tests:** Pest Lesen/Schreiben berechtigt vs. unberechtigt

### BL-P1-04 – Append-only-Audit

- **Phase:** 1
- **Status:** offen
- **Anforderungen:** `AUD-001` bis `AUD-004`
- **Abhängigkeiten:** BL-P1-01
- **Ergebnis:** Auditereignisse unveränderbar; keine View-Logs
- **Akzeptanz:** Update/Delete am Audit serverseitig unmöglich bzw. ungenutzt
- **Tests:** Pest Schreiben, Unveränderbarkeit, keine View-Ereignisse

### BL-P1-05 – In-App-Benachrichtigungsgrundlage

- **Phase:** 1
- **Status:** offen
- **Anforderungen:** `NOT-001`, `NOT-002`
- **Abhängigkeiten:** BL-P1-04
- **Ergebnis:** Benachrichtigungsmodell und Zustellstatus ohne Fachereignisse vollständig verdrahtet
- **Akzeptanz:** Schema und Service vorbereitet; konkrete Ereignisse folgen mit Workflows
- **Tests:** Pest Persistenz und fehlgeschlagene Zustellung ohne Status-Rollback-Hook

## Phase 2 – Stammdaten und Kombinationstabelle

### BL-P2-01 – Organisation und Inventare

- **Phase:** 2
- **Status:** offen
- **Anforderungen:** `ORG-001` bis `ORG-003`
- **Abhängigkeiten:** BL-P1-02
- **Ergebnis:** eine Organisation, Inventare inkl. Kombi-Mitgliedschaft, Admin-Pflege
- **Akzeptanz:** Deaktivierung verhindert Neuanlage, historische IDs bleiben
- **Tests:** Pest CRUD, Aktivstatus, Kombi-Beziehung

### BL-P2-02 – Oberkategorien, Werbemittel, Kombinationstabelle

- **Phase:** 2
- **Status:** offen
- **Anforderungen:** `ADV-001` bis `ADV-003`, `MAT-001` bis `MAT-003`
- **Abhängigkeiten:** BL-P2-01
- **Ergebnis:** Katalog und Whitelist mit Buchungskennzeichen, Einplanung, Hinweisen, Filtern
- **Akzeptanz:** nur aktive erlaubte Kombinationen auswählbar; `MAT-004` nicht umsetzen
- **Tests:** Pest Filter, Planungsverbot, eindeutiger fachlicher Schlüssel

### BL-P2-03 – Kunden, Agenturen, Kontakte

- **Phase:** 2
- **Status:** offen
- **Anforderungen:** `CRM-001` bis `CRM-004`
- **Abhängigkeiten:** BL-P1-02
- **Ergebnis:** Stammdatenpflege inkl. Meridian-Nummer
- **Akzeptanz:** Rechnungsempfänger nur Kunde oder Agentur modellierbar
- **Tests:** Pest Validierung und Berechtigung

## Phase 3 – Versionen, dynamische Felder und Snapshots

### BL-P3-01 – Felddefinitionen, Feldsets, Regeln

- **Phase:** 3
- **Status:** offen
- **Anforderungen:** `DYN-001` bis `DYN-008`, `ADM-001`, `ADM-002`
- **Abhängigkeiten:** BL-P2-02
- **Ergebnis:** Typen, Optionen, Pflicht/Sichtbarkeit, Admin-Vorschau, Versionen
- **Akzeptanz:** serverseitige Auswertung; ausgeblendete Felder ohne versehentliche Pflichtfehler
- **Tests:** Pest Regelmatrix positiv/negativ, Versionsaktivierung

### BL-P3-02 – Snapshot-Fundament

- **Phase:** 3
- **Status:** offen
- **Anforderungen:** `VER-001` bis `VER-007`
- **Abhängigkeiten:** BL-P3-01
- **Ergebnis:** unveränderbare Konfigurations- und Positionssnapshots
- **Akzeptanz:** Adminänderung ändert alte Vorgänge nicht; muss vor produktiver Kalkulation stehen
- **Tests:** AT-14-Vorstufe; Mutation historischer Snapshots schlägt fehl

## Phase 4 – Preislisten und Spotkalkulation

### BL-P4-01 – Preislisten, Import, Tagesgruppen

- **Phase:** 4
- **Status:** offen
- **Anforderungen:** `PRI-001` bis `PRI-006`
- **Abhängigkeiten:** BL-P3-02
- **Ergebnis:** Versionen, Excel-Import mit Vorschau, atomare Aktivierung, abgeleitete Tagesgruppen
- **Akzeptanz:** fehlerhafter Import aktiviert nichts; Intern mind. 4 Dezimalstellen
- **Tests:** `AT-21`, Zahlenbeispiele Tagesgruppen

### BL-P4-02 – Spot Durchschnitt, Planer, Index, Komponenten

- **Phase:** 4
- **Status:** offen
- **Anforderungen:** `CAL-001` bis `CAL-004`, `SPT-001` bis `SPT-015`
- **Abhängigkeiten:** BL-P4-01
- **Ergebnis:** Spotkalkulation mit Rechenerklärung
- **Akzeptanz:** `AT-01` bis `AT-04`
- **Tests:** Pest Formeln inkl. Rundung; UI an Serverregeln

## Phase 5 – SWF, Produktion und freie Preisbestandteile

### BL-P5-01 – SWF ohne Spotlängenindex

- **Phase:** 5
- **Status:** offen
- **Anforderungen:** `SWF-001` bis `SWF-007`, `ADM-003`
- **Abhängigkeiten:** BL-P4-02
- **Ergebnis:** SWF Durchschnitt/Planer/Festpreis; CityLife als Variante über Admin-Daten
- **Akzeptanz:** `AT-05`; Aufschläge nicht hardcodiert
- **Tests:** Pest Formel ohne Index

### BL-P5-02 – Produktion/Sonstiges

- **Phase:** 5
- **Status:** offen
- **Anforderungen:** `PRO-001` bis `PRO-007`
- **Abhängigkeiten:** BL-P5-01
- **Ergebnis:** Zusatzzeilen, Produktionspreisliste, Überschreibungsfreigabe
- **Akzeptanz:** `AT-11`
- **Tests:** Pest Standardmenge 0, Buchungskennzeichen S, Sonderfreigabe bei Überschreibung

## Phase 6 – Online Audio, Social Media und Events

### BL-P6-01 – Online Audio TKP

- **Phase:** 6
- **Status:** offen
- **Anforderungen:** `OA-001` bis `OA-009`
- **Abhängigkeiten:** BL-P4-01
- **Ergebnis:** TKP, Mengenverteilung, Targeting
- **Akzeptanz:** `AT-08`, `AT-09` (Speichern vs. Übergabe)
- **Tests:** Pest Mindest-TKP gegen Basis-TKP

### BL-P6-02 – Social Media und Events

- **Phase:** 6
- **Status:** offen
- **Anforderungen:** `SOC-001` bis `SOC-006`, `EVT-001` bis `EVT-003`
- **Abhängigkeiten:** BL-P3-01, BL-P6-01
- **Ergebnis:** Pakete, Influencer-Unterpositionen, Booster, Event-Feldsets
- **Akzeptanz:** `AT-10`; Initialpreise nur als Admin-Daten
- **Tests:** Pest Medien/Booster-Trennung, kein initialer Rabatt/AE

## Phase 7 – Rabatt, AE, Festpreis und Sonderfreigaben

### BL-P7-01 – Kommerzielle Berechnung

- **Phase:** 7
- **Status:** offen
- **Anforderungen:** `COM-001` bis `COM-011`
- **Abhängigkeiten:** BL-P4-02, BL-P6-01
- **Ergebnis:** konsekutiver Rabatt, AE-Hierarchie, Festpreis, drei Payfaktoren
- **Akzeptanz:** `AT-06`, `AT-07`; Division durch 0 bei Mediabrutto 0 verhindert
- **Tests:** Zahlenbeispiel 10 %+10 % = 19 %

### BL-P7-02 – Sonderfreigabe und Invalidierung

- **Phase:** 7
- **Status:** offen
- **Anforderungen:** `APR-001` bis `APR-004`, `AUTH-004`, `AUTH-005`
- **Abhängigkeiten:** BL-P7-01, BL-P1-03, BL-P1-04
- **Ergebnis:** Auslöser, Vier-Augen-Trennung, Invalidierung
- **Akzeptanz:** `AT-09`, `AT-12`, `AT-13`
- **Tests:** Pest Ersteller ≠ Freigeber; Invalidierung mit Auditgrund

## Phase 8 – Dispoauftrag und Statusworkflow

### BL-P8-01 – Snapshot, Nummerierung, Positionsübernahme

- **Phase:** 8
- **Status:** offen
- **Anforderungen:** `DSP-001` bis `DSP-006`, `TEC-001`, `TEC-002`
- **Abhängigkeiten:** BL-P7-02, BL-P3-02
- **Ergebnis:** unabhängiger Dispo-Snapshot, Nummernvergabe
- **Akzeptanz:** `AT-15`; keine Sync zurück zur Kalkulation
- **Tests:** Pest Nummer transaktionssicher, erneute Positionsauswahl

### BL-P8-02 – Statusmodell und Kundenbestätigung

- **Phase:** 8
- **Status:** offen
- **Anforderungen:** `STA-001` bis `STA-006`, `UPL-001` bis `UPL-003`
- **Abhängigkeiten:** BL-P8-01
- **Ergebnis:** vollständiges Statusmodell, Rückfrage, Sperren, Bestätigung/Ausnahme
- **Akzeptanz:** `AT-12` bis `AT-19`
- **Tests:** Pest erlaubte/verbotene Kanten, Pflichtbegründungen

## Phase 9 – Dateien, Kommentare und Benachrichtigungen

### BL-P9-01 – Uploads und Audio

- **Phase:** 9
- **Status:** offen
- **Anforderungen:** `UPL-004` bis `UPL-007`
- **Abhängigkeiten:** BL-P8-02
- **Ergebnis:** zentrale Uploadliste, Archivierung statt Löschen, autorisierte Downloads, Audio-Wiedergabe
- **Akzeptanz:** keine öffentlichen URLs; max. 50 MB Default
- **Tests:** Pest MIME/Größe, Archiv, Download-Audit

### BL-P9-02 – Kommentare und Nachrichten

- **Phase:** 9
- **Status:** offen
- **Anforderungen:** `CMT-001` bis `CMT-003`, `NOT-001`, `NOT-002`
- **Abhängigkeiten:** BL-P1-05, BL-P8-02
- **Ergebnis:** append-only Kommentare, Rückfrage-Ereignisse, E-Mail-Queue mit Protokoll
- **Akzeptanz:** Mailfehler rollt Status nicht zurück
- **Tests:** Pest Unveränderbarkeit Kommentare; Mail-Retry ohne Status-Rollback

## Phase 10 – Listen, Reports und Exporte

### BL-P10-01 – Listen und Reports

- **Phase:** 10
- **Status:** offen
- **Anforderungen:** `REP-001` bis `REP-004`
- **Abhängigkeiten:** BL-P8-02
- **Ergebnis:** Suche, Filter, Sortierung, Spalten, Umsatzdimensionen
- **Akzeptanz:** inventarspezifisch und übergreifend
- **Tests:** Pest Filterkombinationen, Rollen-Sichtbarkeit

### BL-P10-02 – PDF, Excel, CSV

- **Phase:** 10
- **Status:** offen
- **Anforderungen:** `REP-005` bis `REP-007`, `AUD-002`
- **Abhängigkeiten:** BL-P10-01, BL-P9-01
- **Ergebnis:** interne PDFs (Dompdf/Blade), Excel/CSV, Download-Audit
- **Akzeptanz:** nur rollensichtbare Daten
- **Tests:** Pest Exportinhalt und Audit; Smoke PDF-Erzeugung

## Phase 11 – Härtung und Produktivsetzung

### BL-P11-01 – Abnahme und Betrieb

- **Phase:** 11
- **Status:** blockiert
- **Anforderungen:** `AT-01` bis `AT-22`, Kapitel 24, Kapitel 27
- **Abhängigkeiten:** BL-P10-02; Initialdaten Kapitel 27; ADR-003-Verifikation
- **Ergebnis:** Berechtigungs-/Negativtests, Performance, Backup-Restore, Browser, Initialimport, Monitoring
- **Akzeptanz:** gesamter Abnahmekatalog
- **Tests:** vollständiger AT-Katalog, Restore-Übung
- **Blocker:** Initialkataloge und Speedit-Betriebsparameter (Kapitel 27, ADR-003) fehlen noch als Liefergegenstände; Umsetzung der vorherigen Phasen ist nicht blockiert
