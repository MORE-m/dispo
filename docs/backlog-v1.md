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

## UX/UI-Gates (zwischen Phase 0 und den Fachpaketen)

### UX-GATE-A – Designsystem und App-Shell

- **Phase:** Gate
- **Status:** erledigt (Freigabe Product Owner 29.08.2026; Umsetzung im Slice App-Shell)
- **Anforderungen:** `GEN-003`, Kapitel 24 Barrierearmut/Browser, [`ui-ux-konzept.md`](ui-ux-konzept.md), [`ux-ui-gate.md`](ux-ui-gate.md)
- **Abhängigkeiten:** BL-P0-04
- **Ergebnis:** linke Navigation, keine obere Hauptnavigation, volle Breite, Tablet-Icon-Leiste, gemeinsame Komponenten und Zustände, Logo-Slots
- **Akzeptanz:** Checkliste UX-GATE-A; Menüpunkte rollenabhängig; gesperrte Bereiche ohne Schein-Fachseiten
- **Tests:** Feature-Tests Navigation/Rechte; Vitest für gemeinsame Komponenten

### UX-GATE-B – Wizard, Mehrsender, Spot Classic

- **Phase:** Gate
- **Status:** erledigt (Freigabe Product Owner 29.08.2026; Umsetzung im Slice Spot Classic)
- **Anforderungen:** `CAL-001`–`CAL-005`, `SPT-009`, `SPT-015`, `SPT-016`, `COM-001`–`COM-008`, `BUD-001`–`BUD-009`, `GEN-001`, `GEN-002`
- **Abhängigkeiten:** UX-GATE-A
- **Ergebnis:** Wizard Grunddaten/Werbeelemente/Konditionen/Zusammenfassung; Selbst planen und Mit Budget planen; Spot Classic mehrsenderfähig
- **Akzeptanz:** Live-Summe; Beispiel Radio Hamburg + ROCK ANTENNE Hamburg; Vorschlag nur nach Übernahme; keine KI-/Reichweitenbehauptung
- **Tests:** Unit-/Feature-Tests Formeln und Budget; Vitest Wizard; Playwright-Ablauf

### UX-GATE-C – Trailer, Influencer, Social, weitere Elemente

- **Phase:** Gate
- **Status:** blockiert
- **Anforderungen:** `SWF-*`, `SOC-*` und weitere Elementoberflächen
- **Abhängigkeiten:** UX-GATE-B
- **Blocker:** Product-Owner-Freigabe ausstehend (BLK-005)

### UX-GATE-D – Dispo, Freigaben, Standardangebote, Administration

- **Phase:** Gate
- **Status:** teilweise freigegeben (Entwurf + Vier-Augen-Freigabe + Inventar-Admin-Lifecycle + Preislisten-Admin-Lifecycle ohne Excel-Import); Rest blockiert
- **Anforderungen:** `DSP-*`, `APR-*`, `AUTH-004`, `STD-*` (Fachoberflächen), Admin-Kataloge
- **Abhängigkeiten:** UX-GATE-B
- **Blocker:** Product-Owner-Freigabe für Restumfang (BLK-006); Kombi-Mitgliedschaften sind kein Restumfang (PO-BL-P2-01-KOMBI)

### BL-P1-A – App-Shell und gemeinsame Grundlage (UX-GATE-A)

- **Phase:** 1 / Gate A
- **Status:** erledigt
- **Anforderungen:** `GEN-003`, `AUTH-001`, `STD-003`
- **Abhängigkeiten:** UX-GATE-A
- **Ergebnis:** App-Shell, Navigation, gemeinsame UI-Komponenten, Sperrzustände für C/D
- **Tests:** Navigation je Rolle; keine obere Hauptnavigation

### BL-P1-B – Kalkulation Spot Classic (UX-GATE-B)

- **Phase:** 1 / Gate B
- **Status:** erledigt
- **Anforderungen:** `CAL-*`, `SPT-015`, `SPT-016`, `COM-001`–`COM-008`, `BUD-*`, `AUD-001`
- **Abhängigkeiten:** BL-P1-A
- **Ergebnis:** Modelle, Berechnungsservice, Wizard, Preview, Budgetvorschlag mit Übernahme
- **Tests:** `AT-06`/`AT-07`-Vorstufe, `AT-23`, `AT-24`, `AT-25`–`AT-27` für die V1-Logiken dieses Slices

## Phase 1 – Anmeldung, Benutzer und Auditfundament

Fachoberflächen außerhalb von UX-GATE-A/B bleiben an C/D gebunden. Login existiert
headless aus Phase 0; die App-Shell gilt nach UX-GATE-A als verbindliche Hülle.

### BL-P1-01 – Lokaler Login und Passwort-Reset

- **Phase:** 1
- **Status:** erledigt
- **Anforderungen:** `AUTH-*` (Zugang), Kapitel 24 Sicherheit, kein öffentliches Self-Registration, keine verpflichtende 2FA
- **Abhängigkeiten:** BL-P0-04, UX-GATE-A (für die Anwendungs-Hülle)
- **Ergebnis:** E-Mail/Passwort-Login, Logout, Passwort-Reset-Flow; Registrierung unerreichbar; Anmeldung führt in die App-Shell
- **Akzeptanz:** Unauthentifizierte Nutzer sehen interne Seiten nicht; Reset erzeugt kein Secret im Repo
- **Tests:** Pest Feature-Tests Login Erfolg/Fehler, Reset, fehlende Registrierungsroute

### BL-P1-02 – Rollen und Policy-Grundlage

- **Phase:** 1
- **Status:** erledigt (Slice-Umfang: Rolle Produktmanagement, Gates, Kalkulations-Policy)
- **Anforderungen:** `AUTH-001` bis `AUTH-003`, `AUTH-006`, `AUTH-007`
- **Abhängigkeiten:** BL-P1-01
- **Ergebnis:** Rollen inkl. Produktmanagement; serverseitige Policies für Kalkulationen; **keine** Admin-Fachoberfläche (`UX-GATE-D`)
- **Akzeptanz:** Produktmanagement ohne Extra-Recht sieht keine Kundenkalkulationen/Dispoaufträge
- **Tests:** Pest Policies Erlaubnis/Verweigerung inkl. `AT-30`-Vorstufe

### BL-P1-03 – Rabattgrenze und Sonderfreigaberecht

- **Phase:** 1
- **Status:** erledigt (Datenfelder und Erkennung; Freigabe-UI bleibt UX-GATE-D)
- **Anforderungen:** `AUTH-001`, `COM-002`, `COM-003`
- **Abhängigkeiten:** BL-P1-02
- **Ergebnis:** nutzerbezogene Rabattgrenze und Sonderfreigabe-Flag; Überschreitung wird markiert, nicht umgangen
- **Akzeptanz:** keine erfundenen Default-Grenzen; `null` bedeutet keine persönliche Grenze
- **Tests:** Pest Lesen der Markierung berechtigt vs. Grenze überschritten

### BL-P1-04 – Append-only-Audit

- **Phase:** 1
- **Status:** erledigt (Fundament für Kalkulationsänderungen)
- **Anforderungen:** `AUD-001` bis `AUD-004`
- **Abhängigkeiten:** BL-P1-01
- **Ergebnis:** Auditereignisse unveränderbar; keine View-Logs
- **Akzeptanz:** Update/Delete am Audit serverseitig unmöglich
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
- **Status:** erledigt (`BL-P2-01a` Inventar-Admin-Lifecycle; Kombi-Mitgliedschaften entfallen durch PO-BL-P2-01-KOMBI)
- **Anforderungen:** `ORG-001` bis `ORG-003`
- **Abhängigkeiten:** BL-P1-02
- **Ergebnis:** eine Organisation; Inventare der Typen `sender` und `kombi` als eigenständige Buchungsobjekte; Admin-Pflege
- **Akzeptanz:** Deaktivierung verhindert Neuanlage, historische IDs bleiben; Kombis ohne Sender-Membership
- **Tests:** Pest CRUD, Aktivstatus, Typfilter `sender`/`kombi`; keine Membership-Beziehung
- **BL-P2-01a erledigt:** Inventar-Admin Liste/Detail/Create/Edit/Lifecycle,
  Impact-Preview, Locking, Audit, historischer Namens-/Code-Freeze an
  Kalkulationspositionen. Kein Preislisten-Admin, kein Auto-Import der
  14 Startinventare.
- **BL-P2-01b entfällt:** Keine Pflege enthaltener Sender, keine
  Membership-Tabelle, kein Membership-Snapshot in Kalkulation oder Dispo.

### BL-P2-02 – Oberkategorien, Werbemittel, Kombinationstabelle

- **Phase:** 2
- **Status:** teilweise (`ADV-001a` Datenbasis; `ADV-001b` Katalog-Admin; `ADV-001c1` Methoden-Schema; `ADV-001c2` Dual-Read/Write + Freeze; `ADV-001c3a` engine-unabhängige Medienpflege; `ADV-001c3b1` Methoden-Lifecycle; `ADV-001c3b2` Kategorie-Desired-State; `ADV-001c3c` Medium-Overrides/Mode/Default; `ADV-001c4a` Methodenoptions-/Freeze-Persistenz; `ADV-001c4b` sichtbare Wizard-Methodenauswahl; weitere Defaults offen)
- **Anforderungen:** `ADV-001` bis `ADV-003`, `MAT-001` bis `MAT-003`
- **Abhängigkeiten:** BL-P2-01 (für Inventar-Admin; ADV-001a/b/c1 braucht BL-P2-01 nicht)
- **Ergebnis:** Katalog und Whitelist mit Buchungskennzeichen, Einplanung, Hinweisen, Filtern
- **Akzeptanz:** nur aktive erlaubte Kombinationen auswählbar; `MAT-004` nicht umsetzen
- **Tests:** Pest Filter, Planungsverbot, eindeutiger fachlicher Schlüssel
- **ADV-001a erledigt:** `advertising_categories` + `advertising_media.category_id`
  NOT NULL; sechs kanonische Keys; explizite Bestands-Map
- **ADV-001b erledigt:** Katalog-Admin CRUD/Lifecycle/Impact-Preview; Key/Code
  immutable; kein Hard Delete; `spot_classic`↔`spots`. **ADV-001 nicht vollständig**
  (Defaults fehlen). Kombinationstabelle-Admin (`MAT-*`) offen.

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
- **Status:** erledigt (DF-1 … DF-3-RULE-C; Dyn-Feld-Pfad inkl. Optionen und Regel-Editor)
- **Anforderungen:** `DYN-001` bis `DYN-008`, `ADM-001`, `ADM-002`
- **Geplante Folge:** ADV-001a → … → Optionen / Regel-Editor – **erledigt**; Inventar-Admin erledigt; danach Preislisten-Admin / ADV-002
- **Offen außerhalb DF-3:** ADV-002.
- **Abhängigkeiten:** BL-P2-02 / ADV-001a (für Vererbung/Assignments; nicht für DF-3.1/3.2a/b/fs)
- **Ergebnis:** Typen, Optionen, Pflicht/Sichtbarkeit, Admin-Vorschau, Versionen, Regel-Editor
- **Akzeptanz:** serverseitige Auswertung; ausgeblendete Felder ohne versehentliche Pflichtfehler
- **Tests:** Pest Regelmatrix positiv/negativ, Versionsaktivierung, RULE-C Preview/Apply
- **DF-1 … DF-3.3b / REST / RULE-A/B:** siehe `fortschritt.md`
- **DF-3-RULE-C erledigt:** Desired-State Regel-Editor; Seed-Schutz; Audit; E2E Port 8015.
  **DF-3 Dyn-Feld-Pfad abgeschlossen** (ADV-002 außerhalb).
- **Historische Folge (erledigt):** ADV-001a → DF-3.3-fs → a1 → a2α/β → 3b → ADV-001b
  → Optionen → RULE-A/B/C

### BL-P3-02 – Snapshot-Fundament

- **Phase:** 3
- **Status:** teilweise (DF-1/DF-2 + DF-3.1 AT-14 Admin + DF-3.2a/b Custom-Text + DF-3.3a2α VER-002-Quellengraph)
- **Anforderungen:** `VER-001` bis `VER-007`
- **Abhängigkeiten:** BL-P3-01
- **Ergebnis:** unveränderbare Konfigurations- und Positionssnapshots
- **Akzeptanz:** Adminänderung ändert alte Vorgänge nicht; muss vor produktiver Kalkulation stehen
- **Tests:** AT-14-Vorstufe; Mutation historischer Snapshots schlägt fehl
- **DF-1 erledigt:** unveränderlicher Config-Snapshot an Kalkulationen inkl.
  Legacy-Backfill (`VER-002`, `VER-007`); dynamische Kopf-/Positionswerte.
  **DF-2:** Dispo-Compose-Snapshot (`VER-004`) inkl. `source_configuration_snapshot_id`,
  Dispo-Wertetabellen, Draft-Texte, Legacy ohne erfundene Dyn-Werte.
  **DF-3.1:** Admin-Activate ändert historische Snapshots nicht (AT-14).
  **DF-3.2a:** Custom-Header in neuen Snapshots nach Activate; Historie unverändert.
  **DF-3.2b:** Position-Custom in Snapshots; Calc-Origin über Source-Snapshot;
  Historie unverändert.
  **DF-3.3a2α:** `VER-002`-Quellengraph (`configuration_snapshot_sources` inkl.
  eingefrorener Quellfelder/-regeln), Property-Provenance je Snapshot-Definition,
  `format_version` als Generationsmarker mit fail-closed Lesepfad,
  `schema_fingerprint` + 409 bei Drift, Dispo-Revision klont Snapshot statt neu
  aufzulösen. **DF-3 nicht abgeschlossen.**
  **DF-3.3a2β (`main`, PR #25):** `VER-003` Positions-Effektiv-Snapshots,
  Kategorie-/Werbemittelquellen im Quellengraph, historischer Kontext.
  **DF-3.3b:** Assignment-Admin-UI (keine Schemaänderung).
  **DF-3-REST / RULE-A–C:** Optionen und Regel-Editor erledigt; Dyn-Feld-Pfad abgeschlossen.

## Phase 4 – Preislisten und Spotkalkulation

### BL-P4-01 – Preislisten, Import, Tagesgruppen

- **Phase:** 4
- **Status:** erledigt (`BL-P4-01a` Lifecycle, `BL-P4-01b` Excel-Import, `BL-P4-01c` Wizard-Jahreswahl / PO-PRI-YEAR-1)
- **Anforderungen:** `PRI-001` bis `PRI-006`
- **Abhängigkeiten:** BL-P3-02
- **Ergebnis 01a:** Jahresversionen, Draft/Copy/Activate/Archive, atomare Aktivierung, abgeleitete Tagesgruppen, Jahresdefault, Budget-Fingerprint
- **Ergebnis 01b:** Excel-Import XLSX/XLS, Preview/Fingerprint, atomare Drafts, privater Storage/Report (`AT-21`)
- **Ergebnis 01c:** explizite Jahreswahl je Position und im Budget (aktuelles + Folgejahr), Rebind nur bei Jahrwechsel + Speichern, historische Pins stabil, 409 bei Active-Drift
- **Offen außerhalb 01:** MORE-Produktiv-Workbook-Adapter nach Beispieldatei (Lieferdaten; macht `BL-P4-01` nicht unvollständig)
- **Akzeptanz 01a:** höchstens eine Active je Inventar/Jahr; historische Versionen unverändert; aktuelles Kalenderjahr als Default
- **Tests:** Feature-/Constraint-/Runtime-Tests; Playwright `blp401a` (8017), `blp401b` (8018), `blp401c` (8019)

### BL-P4-02 – Spot Durchschnitt, Planer, Index, Komponenten

- **Phase:** 4
- **Status:** offen (`BL-P4-02a`/`02b`/`02c` auf `main`; **`BL-P4-02d` umgesetzt**, offener PR)
- **Anforderungen:** `CAL-001` bis `CAL-005`, `SPT-001` bis `SPT-016`
- **Abhängigkeiten:** BL-P4-01
- **Ergebnis 02a (`main`, PR #56):** Methodenwechsel bei unverändertem Inventar/Preisjahr behält historischen Preislisten-Pin; AT-01/03/23/24 gezielt gehärtet
- **Ergebnis 02b (PR #58):** Kalenderplaner/AT-02 als echte **Wochenmatrix** Mo–So × Preisstunden; Spotanzahl direkt je Datum-/Stundenzelle; Jahresvertrag (ein Preisjahr je Position); Wochen-/Monatsnavigation mit State-Erhalt; Registry `calendar` **released/v1**; Persistenz + Dispo-Snapshot + Anzeige (`SPT-008` Snapshot/Anzeige umgesetzt, Export offen); Pin-Vertrag 02a unverändert kompatibel
- **Ergebnis 02c (PR #59, manuell abgenommen):** Hauptspot+Allonge; Strategien `shared_total_length`/`individual` für Average+Calendar; Admin-Freeze; Dispo-Snapshot/Anzeige; Calendar-Einträge bleiben beim kompatiblen Inventar-/Strategiewechsel erhalten; SPT-012 offen; SPT-013 teilweise; SPT-014 Hauptspot/Allonge umgesetzt
- **Ergebnis 02d (Worktree, offener PR):** Preisabschluss `pricing_settlement_mode` `normal`|`fixed_price` auf Basis `average`|`calendar`; N/N-Festpreis; rückwärts Payfaktor/Abschlag; Pin 02a; Dispo-Snapshot; Budget-Apply → `normal`. Registry-Methode **`fixed_price`** weiter **`planned`** (nicht der Live-Weg)
- **Offen:** SPT-008 Export, Abbinder/Reminder/Tandem/Tridem (SPT-012/013)
- **Akzeptanz:** `AT-01` bis `AT-04`, `AT-23`, `AT-24` (02c: AT-04 abgenommen; 02d: Festpreis-Abschluss Spot Classic)
- **Tests 02a:** `PriceListPinOnMethodChangeTest` (+ MySQL), `SpotClassicAverageAcceptanceHardeningTest`
- **Tests 02b:** `CalendarCalculationTest`, `SpotClassicCalendarCalculationTest`, `BlP402bWithOriginRetentionCompatTest`; Playwright `playwright.blp402b.config.ts` (Port 8022)
- **Tests 02c:** `SpotComponentCalculationTest`, `SpotClassicComponentsTest` (+ MySQL); Playwright `playwright.blp402c.config.ts` (Port 8025)
- **Tests 02d:** `SpotClassicFixedPriceSettlementTest` (+ MySQL); Vitest `pricing-settlement.test.ts`, `pricing-settlement-section.test.tsx`; Playwright `playwright.blp402d.config.ts` (Port 8026)
- **Hinweis:** Mehrsender-Spot-Classic, Längenfeld und Live-Summe sind im Slice UX-GATE-B enthalten. Gesamtblock bleibt für SPT-012/013 und SPT-008 Export offen.
### BL-P4-03 – Standardangebote

- **Phase:** 4
- **Status:** offen
- **Anforderungen:** `STD-001` bis `STD-009`, `AUTH-006`, `AUTH-007`, `VER-004`
- **Abhängigkeiten:** BL-P4-02, UX-GATE-D
- **Ergebnis:** versionierte Vorlagen ohne Kundenbindung; Navigation; Übernahme als Kundenkalkulations-Snapshot; Historie/Audit
- **Akzeptanz:** `AT-28` bis `AT-31`; Dispo nur aus übernommener Kundenkalkulation; Änderungen isoliert
- **Tests:** Pest Statuswechsel, Snapshot-Isolation, Rechte Produktmanagement vs. Vertrieb

## Phase 5 – SWF, Produktion und freie Preisbestandteile

### BL-P5-01 – SWF ohne Spotlängenindex

- **Phase:** 5
- **Status:** offen
- **Anforderungen:** `SWF-001` bis `SWF-008`, `ADM-003`
- **Abhängigkeiten:** BL-P4-02, UX-GATE-C
- **Ergebnis:** SWF Durchschnitt/Planer/Festpreis; CityLife als Variante über Admin-Daten
- **Akzeptanz:** `AT-05`; Aufschläge nicht hardcodiert
- **Tests:** Pest Formel ohne Index; gruppierte Zeitschienen abweichend von Spot Classic (`SPT-016`)

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
- **Status:** **teilweise umgesetzt** (September 2026): Auslöser + Vier-Augen-Entscheidung + Nachbesserung im Dispo-Freigabe-Slice; Invalidierung offen
- **Anforderungen:** `APR-001` bis `APR-004`, `AUTH-004`, `AUTH-005`
- **Abhängigkeiten:** BL-P7-01, BL-P1-03, BL-P1-04
- **Ergebnis (Slice):** Freigabeart regulär/special als Snapshot; Rollenmatrix; Ersteller-Ausschluss; abgelehnter Auftrag bleibt terminaler Snapshot, Nachbesserung über neuen Entwurf
- **Offen:** Freigabeinvalidierung nach Änderungen, Rückzug, AUTH-005 als zwei getrennte Ereignisse
- **Akzeptanz:** `AT-12` (Vorstufe); `AT-13` offen
- **Tests:** Pest Feature-/Unit-/Concurrency-Tests, Vitest, Playwright

### BL-P7-03 – Budget-Assistent

- **Phase:** 7
- **Status:** offen
- **Anforderungen:** `BUD-001` bis `BUD-009`
- **Abhängigkeiten:** BL-P7-01, BL-P4-02, UX-GATE-B
- **Ergebnis:** optionales EUR-Zielbudget N/N, V1-Logiken gleich verteilen / Spotanzahl maximieren, Rest/Überschreitung, explizite Übernahme
- **Akzeptanz:** `AT-25` bis `AT-27`; kein automatisches Schreiben; keine Reichweiten-/KI-Behauptung
- **Hinweis:** Der Slice UX-GATE-B enthält die V1-Logiken bereits für Spot Classic; dieses Paket bleibt für die vollständige Assistenten-UI späterer Elemente.
- **Tests:** Pest Determinismus Mehrsender-Vorschlag, Restausweis, Übernahme vs. Verwerfen

## Phase 8 – Dispoauftrag und Statusworkflow

### BL-P8-01 – Snapshot, Nummerierung, Positionsübernahme

- **Phase:** 8
- **Status:** **umgesetzt** (September 2026)
- **Anforderungen:** `DSP-001` bis `DSP-007`, `TEC-001`, `TEC-002` (Entwurfsteil)
- **Abhängigkeiten:** BL-P7-02, BL-P3-02
- **Ergebnis:** unabhängiger Dispo-Snapshot beim Anlegen, Nummernvergabe aus Kalkulationsnummer (`K-YYYY-NNNNN` → `DA-YYYY-NNNNN-SS`; Legacy-Familien behalten Stamm), Positionsauswahl inkl. Kennzeichnung bereits übernommener Positionen
- **Akzeptanz:** `AT-15`, `AT-29` (Entwurf); keine Sync zurück zur Kalkulation; kein Dispo aus Standardangebot
- **Tests:** Pest (Anlage, Berechtigungen, Snapshot-Isolation, Nummern), Vitest (Dialog), Playwright (Happy Path)

### BL-P8-01b – Vier-Augen-Freigabe (Vertical Slice)

- **Phase:** 8
- **Status:** **umgesetzt** (September 2026, Branch `feat/dispo-order-approval`, PR #13)
- **Anforderungen:** `AUTH-004`, `APR-001` (Teil), Statusübergänge Entwurf/Freigabe
- **Abhängigkeiten:** BL-P8-01
- **Ergebnis:** Einreichen → Genehmigen/Ablehnen; Status `Liegt bei Disposition` bzw. `Freigabe abgelehnt`; persistente Freigabehistorie; Nachbesserung über neuen verknüpften Entwurf; Listenstatus ohne Browser-Reload
- **Akzeptanz:** jeder Auftrag braucht Freigabe; Ersteller entscheidet nie; Sonderfreigabe nur Admin/GF; Ersteller bessert abgelehnte Aufträge nach
- **Tests:** Pest, Vitest, Playwright, MySQL-Concurrency

### BL-P8-02 – Statusmodell und Kundenbestätigung

- **Phase:** 8
- **Status:** offen (Freigabe-Kanten teilweise über BL-P8-01b erledigt)
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
- **Ergebnis:** Suche, Filter, Sortierung, Spalten, Umsatzdimensionen; Listen auch für Standardangebote
- **Akzeptanz:** inventarspezifisch und übergreifend; rollenbezogene Sicht auf Standardangebote
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
- **Anforderungen:** `AT-01` bis `AT-31`, Kapitel 24, Kapitel 27
- **Abhängigkeiten:** BL-P10-02; Initialdaten Kapitel 27; ADR-003-Verifikation
- **Ergebnis:** Berechtigungs-/Negativtests, Performance, Backup-Restore, Browser, Initialimport, Monitoring
- **Akzeptanz:** gesamter Abnahmekatalog
- **Tests:** vollständiger AT-Katalog, Restore-Übung
- **Blocker:** Initialkataloge und Speedit-Betriebsparameter (Kapitel 27, ADR-003) fehlen noch als Liefergegenstände; Umsetzung der vorherigen Phasen ist nicht blockiert
