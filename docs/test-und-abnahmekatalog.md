# Test- und Abnahmekatalog

## Strategie

Die fachliche Mindestabnahme besteht aus den Szenarien `AT-01` bis `AT-31`.
Zusätzlich benötigt jede Umsetzung:

- Unit-Tests für Formeln, Regelauswertung und Statusentscheidungen,
- Integrationstests für Datenbank, Snapshots, Importe und Jobs,
- Feature-/HTTP-Tests für Berechtigungen und vollständige Nutzerabläufe,
- Exporttests für PDF, Excel und CSV,
- Regressionstests für jeden behobenen fachlichen Fehler.

Tests verwenden feste Preis- und Zeitdaten. Aktuelles Datum, Zeitzone und
Zufallswerte werden kontrolliert, damit Ergebnisse reproduzierbar bleiben.

### BL-P4-01a (ohne Excel-Import)

Zusätzlich zu `AT-21` (Import) gelten für den Lifecycle-Slice:

- höchstens eine Active je Inventar/Jahr; mehrere Drafts/Archive zulässig
- Jahresdefault = aktive Liste des aktuellen Kalenderjahres `Europe/Berlin`
- fehlende aktuelle Jahresliste ohne stillen Fallback
- historische Positionen bleiben an der gepinnten `price_list_id`
- Budget-Fingerprint unterscheidet gleiche Versionsstrings verschiedener Listen
- **PO-PRI-HOURS-1:** Stunden je Tagesgruppe unabhängig; fehlende Basiszeile =
  nicht buchbar (nicht 0); Aktivierung mit mind. einem Basispreis; Mo–Sa/Mo–So
  nur bei vollständigen Abhängigkeiten; keine 24×3-Pflicht
- isolierte Playwright-Suite `playwright.blp401a.config.ts` (Port 8017)

### BL-P4-01b (Excel-Import)

- Upload XLSX/XLS → strukturelle Prüfung → Preview → Bestätigung → neue Drafts
- keine Auto-Aktivierung; Aktivierung nur über BL-P4-01a-Lifecycle
- kanonischer Spaltenvertrag; inventarauflösung Code/Name + dokumentierte Aliase
- Formeln fail-closed (`formula_not_allowed`); Preflight vor Materialisierung
- atomar je Importlauf (mehrere Inventare möglich, keine Teilübernahme; Confirm-Audit in derselben Transaktion)
- Importdatei privat (temp→Archiv), Report JSON auditierbar
- isolierte Playwright-Suite `playwright.blp401b.config.ts` (Port 8018)

### BL-P4-01c (Wizard-Jahreswahl / PO-PRI-YEAR-1)

- Default = aktuelles Kalenderjahr `Europe/Berlin`; Folgejahr nur bei Active
- Rebind nur bei tatsächlichem Jahrwechsel + bewusstem Speichern
- historische Pins stabil (archiviert / neuere Active desselben Jahres)
- kein stiller Fallback; fehlende Active fail-closed; kein Preis 0
- `expected_price_list_id` → HTTP 409 bei Active-Wechsel zwischen Render und Save
- Budget denselben Jahresvertrag; keine gemischten Jahre im Vorschlag
- isolierte Playwright-Suite `playwright.blp401c.config.ts` (Port 8019)

### BL-P4-02a (Average-Abnahme + Pin bei Methodenwechsel)

- reiner Methodenwechsel bei gleichem Inventar/Preisjahr behält `price_list_id`/`price_list_version`
- Neubindung nur: neue Position, Inventarwechsel, expliziter Jahrwechsel (01c-Vertrag)
- AT-01/03/23/24 gezielt gehärtet (auf `main`, PR #56)
- Feature: `PriceListPinOnMethodChangeTest`, `SpotClassicAverageAcceptanceHardeningTest`
- MySQL: `PriceListPinOnMethodChangeMysqlTest` (`phpunit.mysql.xml`)

### BL-P4-02c (Spot-Komponenten / AT-04)

- Abnahme **AT-04** (Hauptspot + Allonge) **umgesetzt und manuell abgenommen** (PR #59):
  beide Strategien, Average + Calendar, Preview=Store, Freeze/Admin-Stabilität,
  Dispo-Snapshot; spotgewichteter Durchschnittspreis; Komponenten-Payload
  absent/`null`/`[]`; Strategie-Mismatch 422; kanonische Labels; Admin-`lock_version`/409;
  Calendar-Einträge bleiben beim kompatiblen Inventar-/Strategiewechsel erhalten
- Unit-/Feature-/MySQL-/Vitest-/Playwright (`playwright.blp402c.config.ts`, Port 8025)
- SPT-012 offen; SPT-013 nur teilweise; SPT-014 Hauptspot/Allonge umgesetzt;
  SPT-015 unverändert; `fixed_price` planned; SPT-008 Export offen; `BL-P4-02` insgesamt offen

### BL-P4-02b (Kalenderplaner / AT-02)

- Abnahme **AT-02** mit `SPT-005`–`SPT-007` (+ Anzeige-Teil `SPT-008`): **Wochenmatrix**
  Mo–So × Preisstunden; Datum bestimmt Tagesgruppe; jede Zelle `(Datum, Stunde)` mit
  eigener Spotanzahl und Stundenpreis; Mo–Fr/Sa/So korrekt (nur Basisgruppen, keine
  abgeleiteten `mo_sa`/`mo_so`)
- Jahresvertrag: Kalenderdaten müssen zum Preisjahr der gepinnten Liste passen; gemischte Jahre fail-closed; UI zeigt Preisjahr und Hinweis auf getrennte Positionen
- Wochen-/Monatsnavigation + „Aktuelle Woche“; Einträge außerhalb der sichtbaren Woche bleiben im React-State (`SPT-007`)
- Methode `calendar`: keine parallelen Average-`time_ranges`; leere/0-Zellen entfernen den Eintrag
- Registry-Freigabe `spot_classic`/`calendar` **released/v1** (`BL-P4-02b` umgesetzt, PR #58); `fixed_price` weiter `planned`
- Persistenz/Roundtrip `planner_entries`; Dispo übernimmt `planner_entries_snapshot`; Show/Create-Dialog lesbare Anzeige (chronologisch unverändert)
- Dispo-Export der Verteilung (`SPT-008` Export) **nicht** Teil dieses PR
- Kompatibel mit Origin-Retention (PR #57): kein AT-04-/Festpreis-Claim
- Unit: `CalendarCalculationTest`
- Feature: `SpotClassicCalendarCalculationTest` (inkl. `price_list_hours_by_id` in Wizard-Props), `SpotClassicCalendarYearContractMysqlTest`, `BlP402bWithOriginRetentionCompatTest`
- Vitest: `spot-calendar-planner.test.tsx`, `pricing-calendar.test.ts`, `dispo-planner-display.test.ts`
- isolierte Playwright-Suite `playwright.blp402b.config.ts` (Port **8022**, DB `database/e2e-bl-p4-02b.sqlite`)

## Mindestabnahme

| ID | Betroffene Anforderungen | Szenario | Erwartung |
|---|---|---|---|
| AT-01 | SPT-001–SPT-004, SPT-009, PRI-006 | Mo–Fr, zwei Preiszeiträume mit eigener Spotanzahl | Jeder Zeitraum separat; Summe der Zeitraumssummen; Index und Rundung unverändert |
| AT-02 | SPT-005–SPT-007 (+ Anzeige SPT-008) | Planer über Mo–Fr, Samstag und Sonntag | Datum bestimmt Tagesgruppe; jede Zelle nutzt richtigen Stundenpreis; ein Preisjahr je Position |
| AT-03 | SPT-009, SPT-010 | Single-Spots mit 46 s und 100 s | 46 s erzeugt nur Hinweis; beide berechenbar mit Index 95 |
| AT-04 | SPT-012–SPT-014 | Hauptspot plus Allonge | Komponenten sichtbar; je Regel einzeln oder über Gesamtlänge gerechnet |
| AT-05 | SWF-001–SWF-005 | Trailer 20 s, +30 %, Zeitfenster | Sekundenpreis × 20 × 1,30; kein Spotlängenindex |
| AT-06 | COM-001–COM-004 | 10 % und danach 5 % Positionsrabatt, optional 10 % Auftrag | 14,5 % bzw. 23,05 % effektiv; Grenze gegen kumulierten Nachlass; keine Addition zu 15 % |
| AT-07 | COM-005–COM-008 | 15 % AE mit AE-fähigen und nicht AE-fähigen Zeilen | AE nach Rabatt ausschließlich auf AE-fähiger Basis |
| AT-08 | OA-005–OA-009 | Zwei Plattformen, Pre-/In-Stream und Targeting | Detailmengen ergeben Gesamtmenge; Targeting additiv; Invest korrekt |
| AT-09 | OA-001–OA-003 | Basis-TKP unter Minimum | Speichern möglich; Übergabe ohne Begründung/Sonderfreigabe blockiert |
| AT-10 | SOC-001–SOC-006 | Paket aus Story, Reel und Booster | Unterpositionen und Medien-/Boostertrennung; initial kein Rabatt/AE |
| AT-11 | PRO-001–PRO-007 | Produktion mit Menge 0, regulär und Sonstiges | Preislistenbezug; reguläre Überschreibung nur mit Begründung/Freigabe |
| AT-12 | AUTH-004, AUTH-005 | Ersteller versucht eigene Freigabe | Server blockiert; andere berechtigte Person kann freigeben |
| AT-13 | APR-004 | Preis nach Freigabe ändern | Freigaben werden zurückgesetzt; Ursache vollständig auditiert |
| AT-14 | VER-001–VER-007 | Admin ändert Feldname und Preis | Alter Vorgang unverändert; neuer Vorgang verwendet neue Version |
| AT-15 | DSP-001–DSP-003 | Position zweimal in getrennte Dispoaufträge übernehmen | Kennzeichnung, erneute Auswahl und unabhängige Snapshots |
| AT-16 | UPL-001–UPL-003 | Kein Upload, aber Ausnahme | Ausnahmegrund Pflicht und ausdrücklich mitfreigegeben |
| AT-17 | STA-002, CMT-003 | Dispo stellt Rückfrage, Vertrieb antwortet | Pflichtnotizen, Historie und aktive Rückkehr zu Liegt bei Disposition |
| AT-18 | STA-006, INV-003 | Rechnungsmonat fehlt | Abschluss blockiert; Admin-Override nur mit Begründung und Audit |
| AT-19 | STA-004, STA-005 | Storno nach Abschluss | Nur berechtigt und mit Begründung; Historie vollständig |
| AT-20 | AUD-001–AUD-004 | Änderung, Download, Export und Kommentar | Aktionen protokolliert; reine Ansicht nicht protokolliert |
| AT-21 | PRI-001–PRI-006 | Fehlerhafte Preisimportdatei | Vorschau und Fehlerbericht; keine Teilaktivierung |
| AT-22 | AUTH-001, STA-003 | Disposition versucht Rabatt zu ändern | Server blockiert; Rückfrage an Vertrieb bleibt möglich |
| AT-23 | CAL-001, CAL-005 | 10 Spot Classic Radio Hamburg und 5 ROCK ANTENNE Hamburg, abweichende Längen/Stunden/Rabatte | Eine Kalkulation; Live-Gesamtsumme; Positionen separat editierbar |
| AT-24 | SPT-015 | Standardlänge vorbelegt, dann abweichende Sekunden | Feld sichtbar und frei; Preis und Dispo-Snapshot nutzen die tatsächliche Länge |
| AT-25 | BUD-003–BUD-006 | Zielbudget N/N, zwei Sender, gleich verteilen | Deterministischer Mehrsender-Vorschlag ohne bestehendes Senderverhältnis; editierbar |
| AT-26 | BUD-007 | Zielbudget, das sich nicht ganzzahlig teilt | Ganzzahlige Mengen; Rest oder Überschreitung transparent |
| AT-27 | BUD-008 | Vorschlag anzeigen, verwerfen, dann explizit übernehmen | Ohne Übernahme unverändert; nach Übernahme Mengen gesetzt und weiter editierbar |
| AT-28 | STD-004, STD-005 | Veröffentlichung, Vertrieb übernimmt und ändert Mengen | Eigenständige Kundenkalkulation; Standardangebot unverändert |
| AT-29 | STD-007, DSP-007 | Dispoauftrag direkt am Standardangebot | Serverseitig unzulässig; nur aus Kundenkalkulation |
| AT-30 | AUTH-006, AUTH-007 | Nutzer nur Produktmanagement | Standardangebote erlaubt; Kundenkalkulationen/Dispo ohne Extra-Recht verweigert |
| AT-31 | STD-002, STD-008 | Entwurf veröffentlichen, archivieren | Status, Autor, Veröffentlichungszeitpunkt und Audit vollständig |

## Pflichtklassen für Negativtests

- Zugriff ohne Anmeldung,
- Rolle ohne Berechtigung,
- Ersteller = Freigeber,
- veraltete Versionsnummer bei konkurrierender Bearbeitung,
- veralteter Schema-Fingerprint beim Anlegen (Konfigurationsdrift, 409 vor 422),
- deaktivierte Kombination oder Option,
- ungültiger Statusübergang,
- fehlende Pflichtbegründung,
- negative oder nicht ganzzahlige Mengen,
- ungültige Preislisten-/Snapshotversion,
- Upload mit falschem MIME-Typ oder über Größenlimit,
- Division durch null bei TKP oder Payfaktor,
- verstecktes bedingtes Pflichtfeld,
- Rundungsgrenzfälle mit mehr als vier Dezimalstellen.

## Traceability

Jeder fachliche Test nennt mindestens eine Anforderungs-ID im Testnamen, Attribut
oder Kommentar. Für Anforderungen ohne sinnvollen Einzeltest wird dokumentiert,
durch welchen Integrations- oder Abnahmetest sie abgedeckt sind.

Empfohlenes Schema:

```text
test_SPT_004_average_is_unweighted
test_AUTH_004_creator_cannot_approve_own_order
test_VER_005_new_fieldset_version_does_not_mutate_existing_snapshot
```

## Definition der Fachabnahme

V1 ist fachlich abnahmefähig, wenn:

- alle 31 Mindestfälle erfolgreich sind,
- alle MUSS-Anforderungen tracebar abgedeckt sind,
- keine offenen Fehler der Schwere kritisch/hoch bestehen,
- Preislisten- und Kombinationsinitialdaten abgenommen wurden,
- PDF-/Excel-/CSV-Exporte anhand freigegebener Muster geprüft sind,
- Wiederherstellung und Berechtigungsmatrix getestet wurden.

V1 prüft **nicht**, dass eine Kombi in enthaltene Sender aufgelöst wird.
Eine Kombi erscheint in Kalkulation, Dispoauftrag und Dispo-PDF als
eigenständiges Inventar (`ORG-002`, `REP-007`, PO-BL-P2-01-KOMBI).
Typfilter `sender`/`kombi`, eigenständige Preislisten je Inventar und
historische Inventar-ID-/Namensstabilität bleiben Abnahmekriterien.

