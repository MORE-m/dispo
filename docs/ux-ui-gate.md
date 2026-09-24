# UX/UI-Gates (gestuft)

- **Stand:** 23. September 2026
- **Product-Owner-Entscheidung:** UX-GATE-A und UX-GATE-B freigegeben;
  UX-GATE-C blockiert; UX-GATE-D teilweise freigegeben (Entwurf + Vier-Augen-Freigabe
  + Dyn-Feld-Admin + Katalog Kat/Medien + Inventar-Admin-Lifecycle BL-P2-01a
  + Preislisten-Admin-Lifecycle BL-P4-01a + Excel-Import BL-P4-01b ohne Auto-Aktivierung
  + Wizard-Jahreswahl BL-P4-01c / PO-PRI-YEAR-1
  + **operativer Statuskern BL-P8-02a / PO-BLP802A-1**
  + **Rückfrage Vertrieb BL-P8-02b / PO-BLP802B-1** + **Kundenbestätigung Ausnahmeweg BL-P8-02c / PO-BLP802C-1**)
- **Technische Abnahme:** UX-GATE-A/B abgenommen (HEAD `976aae5`,
  Actions [33252415668](https://github.com/MORE-m/dispo/actions/runs/33252415668))
- **Hinweis Stand 22.09.2026:** Dispo-Slices SPT-008 (Spotplanungs-XLSX) und
  DSP-DCP-001 (abgeleiteter Kampagnenzeitraum) liegen auf `main` innerhalb der
  bereits freigegebenen Dispoentwurf-/Show-Fläche.
- **Hinweis Stand 23.09.2026:** PO-BLP802A-1 gibt **nur** den operativen Statuskern
  bis Disponiert inkl. Wiederöffnung frei – **nicht** die gesamte operative Disposition.
- **Hinweis Stand 23.09.2026 (PO-BLP802C-1):** zusätzlich freigegeben ist ausschließlich der Kundenbestätigungs-**Ausnahmeweg ohne Upload** (UPL-001 Variante B + UPL-002), inkl. Mitfreigabe zweiter Freigeber. **Nicht** freigegeben: Datei-Upload, BL-P9-01, UPL-004–UPL-007.
- **Hinweis Stand 23.09.2026 (PO-BLP802B-1):** zusätzlich freigegeben ist ausschließlich
  der strukturierte Rückfrage-/Antwortprozess (`STA-002` / `CMT-003` / `AT-17`) –
  **nicht** allgemeine Kommentare, Notifications oder weitere operative Module.

Das frühere Einzelgate `BL-GATE-UXUI` ist durch vier Teil-Gates ersetzt.
[`ui-ux-konzept.md`](ui-ux-konzept.md) bleibt die fachliche Navigations- und
Interaktionsbasis. Die Teil-Gates steuern, welche Oberflächen umgesetzt werden
dürfen.

## Übersicht

| Gate | Umfang | Status |
|---|---|---|
| `UX-GATE-A` | Designsystem, App-Shell, linke Navigation, Seitenlayout, gemeinsame UI-Komponenten | **fachlich freigegeben** · **technisch abgenommen** (29.08.2026) |
| `UX-GATE-B` | Kalkulations-Wizard, Mehrsenderplanung, Spot Classic (Durchschnitt) | **fachlich freigegeben** · **technisch abgenommen** (29.08.2026) |
| `UX-GATE-C` | Trailer/SWF, Influencer, Social Media und weitere Werbeelemente | blockiert |
| `UX-GATE-D` | Dispoauftrag, Freigaben, Standardangebots-Fachoberflächen, Administration, abschließende Fachoberflächen | **teilweise freigegeben** (Entwurf + Vier-Augen-Freigabe + Inventar-Admin-Lifecycle + Preislisten-Admin-Lifecycle + Excel-Import ohne Auto-Aktivierung + Wizard-Jahreswahl PO-PRI-YEAR-1 + operativer Statuskern BL-P8-02a / PO-BLP802A-1 + Rückfrage Vertrieb BL-P8-02b / PO-BLP802B-1 + Kundenbestätigung Ausnahmeweg BL-P8-02c / PO-BLP802C-1) · übrige Teile blockiert |

Gesperrte Gates erzeugen **keine** vorgetäuschten fertigen Fachseiten. Menüpunkte
dürfen abhängig von Berechtigungen sichtbar sein und auf einen klaren Leer- bzw.
Sperrzustand führen.

## UX-GATE-A – Anwendungsgrundlage

### Verbindliche Vorgaben

- dauerhaft linke Navigation auf Desktop
- auf Tablet schmale linke Icon-Leiste mit ausklappbarer Navigation
- keine obere Hauptnavigation
- gesamte verfügbare Bildschirmbreite nutzen
- responsive Desktop- und Tabletdarstellung
- gemeinsame App-Shell
- einheitliche Formular-, Tabellen-, Button-, Dialog-, Status- und
  Rückmeldungskomponenten
- verständliche Lade-, Leer-, Fehler- und Erfolgszustände
- barrierearme Tastaturbedienung und sichtbare Fokuszustände
- keine unnötigen Erklärungstexte
- automatisch berechnete Felder rechnen still
- Hinweise nur bei fehlenden, widersprüchlichen oder handlungsrelevanten Angaben
- Logo-Slots für Sender, Kombis und Plattformen, zunächst mit neutralen Platzhaltern
- bestehendes visuelles Grundkonzept: modern, ruhig, großzügig

### Navigation (linke Leiste)

1. Übersicht
2. Kalkulationen
3. Standardangebote
4. Dispoaufträge
5. Auswertungen
6. Stammdaten
7. Administration

Sichtbarkeit richtet sich nach Rolle (`AUTH-001`, `STD-003`, `AUTH-006`,
`AUTH-007`). Profil und Abmeldung liegen in der linken Leiste, nicht in einer
oberen Hauptnavigation.

### Abnahme

Gemeinsame Shell, Navigation, Zustände und Komponenten sind gegen diese Liste
prüfbar. Fachmodule außerhalb von UX-GATE-B bleiben hinter Sperrzuständen.

## UX-GATE-B – Kalkulation und Spot Classic

### Wizard

1. Grunddaten
2. Werbeelemente
3. Konditionen
4. Zusammenfassung

Briefing ist optional. Eine Kalkulation muss ohne Briefing angelegt werden können.

### Planungswege

**Selbst planen:** Sender/Kombis, Werbeelemente, Preiszeiträume, Spotlängen
und Konditionen legt der Benutzer fest. Die Gesamtspotzahl ergibt sich aus
den Zeiträumen. Ein optionales Zielbudget N/N dient nur als Vergleich mit dem
aktuellen N/N-Invest.

**Mit Budget planen:** Zuerst Zielbudget N/N, Sender/Kombis, erlaubte
Preiszeiträume, Spotlänge je Spot-Classic-Werbeelement, Rabatte der
Werbeelemente, Auftragsrabatte, AE-Checkbox und Verteilungslogik. Danach
erzeugt das System einen **neuen** Vorschlag. Es gibt kein „bestehendes
Senderverhältnis“, weil keine manuelle Vorplanung vorausgesetzt wird.

V1-Verteilungslogiken:

- Budget je ausgewähltem Sender gleich verteilen
- Spotanzahl innerhalb der gewählten Preisstunden maximieren

Der Vorschlag wird erst nach ausdrücklicher Übernahme Teil der Kalkulation und
bleibt anschließend vollständig editierbar. Keine KI-, Reichweiten- oder
Leistungsoptimierung (`BUD-008`, `BUD-009`).

Aktuelle Screenshots: Schritt 2
[`wizard-step-2-time-ranges-desktop.png`](screenshots/wizard-step-2-time-ranges-desktop.png),
Schritt 3 Desktop
[`wizard-step-3-conditions-desktop.png`](screenshots/wizard-step-3-conditions-desktop.png)
und Mobil
[`wizard-step-3-conditions-mobile.png`](screenshots/wizard-step-3-conditions-mobile.png).

### Spot Classic und Konditionen

Review-Nacharbeit v5/v6 (technisch, ohne Gate-Erweiterung):

- unveränderte Positionen bleiben bei deaktivierten Stammdaten speicherbar
  (`PRI-004`, `VER-002`);
- gespeicherter Spotlängenindex bei unveränderter Länge (`SPT-009`);
- Budgetvorschlag nur einmal übernehmbar (`BUD-008`);
- atomare Nummernvergabe ohne Lücken bei fehlgeschlagenem Create (`TEC-001`, v6);
- Anzeigenamen historischer Sender/Werbemittel: Referenz auf Stammdaten, kein
  dedizierter Namens-Snapshot in Gate B (Grenze dokumentiert).

Verbindliche Interaktions- und Fachartefakte:

| Artefakt | Fachbezug |
|---|---|
| Sichtbares, frei editierbares Längenfeld in Sekunden | `SPT-015` |
| Mehrere Sender/Kombis und unterschiedliche Werbeelemente | `CAL-001` |
| Preiszeiträume mit exklusivem Ende und Spots je Zeitraum | `SPT-001`–`SPT-004`, `SPT-016` |
| Live-Summe je Werbeelement und für die Kalkulation | `CAL-005` |
| Beispiel 10 Spots Radio Hamburg und 5 Spots ROCK ANTENNE Hamburg | `CAL-001` |
| Konditionen je Werbeelement plus kalkulationsweiter Auftragsrabatt | `COM-001`–`COM-008` |
| Budgetvorschlag nur nach Übernahme | `BUD-001`–`BUD-009` |

Rabattgrenzen und Sonderfreigabeerkennung dürfen nicht umgangen werden (`COM-002`).
Freigabeoberflächen selbst gehören zu UX-GATE-D.

## UX-GATE-C – weitere Werbeelemente (blockiert)

Nicht umsetzen, bis der Product Owner freigibt:

- Trailer-/SWF-Fachoberflächen
- Influencer-/Social-Media-Fachoberflächen
- weitere Werbeelemente außerhalb Spot Classic

## UX-GATE-D – Abschlussprozesse (teilweise freigegeben)

**Product-Owner-Teilfreigabe (September 2026):** Für die Vertical Slices
„Dispoauftrag-Entwurf aus Kalkulation“ und „Vier-Augen-Freigabe“ sind folgende
UX-GATE-D-Bestandteile **freigegeben**:

- Dispoauftrag aus gespeicherter Kalkulation anlegen
- Positionsauswahl vor der Anlage
- Dispoauftragsliste und Detailansicht (Snapshot)
- Navigation zum Dispoauftragsmodul
- Einreichen zur Freigabe, Genehmigen/Ablehnen, Vier-Augen-Prinzip
- Statusübergänge bis `Liegt bei Disposition` / `Freigabe abgelehnt`
- Nachbesserung abgelehnter Aufträge über neuen verknüpften Entwurf
- zugehörige Policies, Persistenz, Auditierung und Tests

**Product-Owner-Teilfreigabe (September 2026, DF-3.1 / DF-3.2a):** Zusätzlich
freigegeben:

- Administration dynamischer Felder (Systemfeld-Revisionen, Kern-Feldset-
  Versionierung, statische Vorschau mit Beispielwerten)
- Custom Header-Textfelder (DF-3.2a) inkl. Runtime in Kalkulation/Dispo
- Navigation Administration → Hub mit freigeschaltetem Dyn-Feld-Modul

**Product-Owner-Teilfreigabe (September 2026, PO-33b-2):** Innerhalb der bereits
freigegebenen Administration dynamischer Felder zusätzlich freigegeben:

- Field-Set-Assignment-Admin-UI (Liste, Anlegen, Detail, Preview, Activate/Deactivate)
- Herkunfts- und Konfliktvorschau für Assignments

Diese Entscheidung gibt **ausschließlich** die Assignment-Verwaltung im Bereich
„Dynamische Felder“ frei. Sie öffnet weder den allgemeinen Katalog-Admin noch
andere UX-GATE-D-Module.

**Product-Owner-Teilfreigabe (September 2026, PO-DF3-REST-1):** Innerhalb der
bereits freigegebenen Administration dynamischer Felder zusätzlich bestätigt:

- Options-Admin (Auswahlfelder / Optionspflege) und späterer Regel-Editor
  gehören zur Dyn-Feld-Teilfreigabe
- keine zusätzliche UX-GATE-D-Freigabe erforderlich

DF-3-REST-A (Options-Fundament) enthält bewusst noch keine neue Admin- oder
Runtime-UI. Zum damaligen Zeitpunkt blieben Inventar-/Preislisten-/Kombinations-
Admin und übrige UX-GATE-D-Module gesperrt; spätere Teilfreigaben siehe unten
(BL-P2-01a, BL-P4-01a/b/c).

**Product-Owner-Teilfreigabe (September 2026, PO-ADV001b-1):** Zusätzlich
freigegeben:

- Administration Oberkategorien und Werbemittel (ADV-001b)
- Anlegen, Bearbeiten, Deaktivieren/Reaktivieren, Sortierung, fachliche Zuordnung
- Auswirkungsvorschau für kritische Änderungen

**Product-Owner-Teilfreigabe (13. September 2026, UX-GATE-D / BL-P2-01a):**
Für `BL-P2-01 – Administration Inventare und Kombis` sind innerhalb von
UX-GATE-D **ausschließlich** folgende Bestandteile freigegeben:

- Administration-Hub und Navigation zum Inventarmodul
- Inventarliste
- Inventar-Detailansicht
- Inventar anlegen
- Inventar-Metadaten bearbeiten
- Aktivieren und Deaktivieren
- Sortierung
- Impact Preview
- Optimistic Locking und Konfliktzustände
- Auditierung
- Leer-, Validierungs-, Fehler- und Read-only-Zustände

Kombi-Mitgliedschaften (`BL-P2-01b`) gehören **nicht** zum Lieferumfang: laut
PO-BL-P2-01-KOMBI sind Kombis eigenständige Inventare ohne gepflegte Sender-
Mitgliedschaften. Der Typfilter `Typ: Alle / Sender / Kombi` bleibt korrekt.

Ausdrücklich **nicht** freigegeben bleiben:

- allgemeine Preislistenauswahl im Wizard als zusätzlicher Bedienablauf
- Kombinationstabellen-Admin
- Standardangebote
- operative Bearbeitung durch die Disposition
- Material, Uploads, Kommentare, Rückfragen, Reports
- übrige UX-GATE-D-Module
- sonstige Kalkulationsarten
- allgemeine Organisationsverwaltung oder Mehrmandantenfähigkeit

`BL-P2-01a` (Inventar-Admin-Lifecycle) ist umgesetzt. `BL-P2-01b`
(Kombi-Mitgliedschaften) **entfällt** durch PO-BL-P2-01-KOMBI. `BL-P2-01` ist
damit **vollständig** abgeschlossen.

**Product-Owner-Teilfreigabe (13. September 2026, UX-GATE-D / BL-P4-01a):**
Für `BL-P4-01` sind innerhalb von UX-GATE-D **ausschließlich** folgende
Bestandteile freigegeben:

- Administration → Preislisten
- Liste und Detail
- Entwurf anlegen
- neue Version durch Kopieren
- manuelle Bearbeitung der Stundenpreise im Entwurf
- Validierung und Auswirkungsvorschau
- Aktivieren und Archivieren
- Status- und Versionsdarstellung
- serverseitige Berechtigungen, Audit, Optimistic Locking
- Lade-, Leer-, Fehler- und Read-only-Zustände
- Jahresvorauswahl der aktiven Liste des aktuellen Kalenderjahres (Europe/Berlin)
- minimale Anpassungen an Preisauflösung und Budget-Aktualitätsprüfung

Diese Entscheidung gibt **nicht** das gesamte UX-GATE-D frei.

Ausdrücklich **nicht** in BL-P4-01a enthalten:

- Excel-Importoberfläche, Upload oder Parser (**BL-P4-01b**)
- allgemeine Preislistenauswahl im Wizard als zusätzlicher Bedienablauf
- Kombinationstabellen-Admin
- neue Kalkulationsarten, Online-Audio-/TKP-Admin, Produktionspreislisten
- Standardangebote, operative Disposition, Reports, ADV-002

**Product-Owner-Teilfreigabe (14. September 2026, UX-GATE-D / BL-P4-01b):**
Excel-Import für Spot-Stundenpreislisten freigegeben: Upload XLSX/XLS, Prüfung,
Preview mit Fingerprint, Bestätigung, atomare Draft-Erzeugung, privater Storage
und Report. **Keine Auto-Aktivierung.** Kanonischer V1-Spaltenvertrag; Adapter für
eine echte MORE-Produktivdatei bleibt bis Beispieldatei offen.

**Product-Owner-Teilfreigabe (15. September 2026, UX-GATE-D / BL-P4-01c / PO-PRI-YEAR-1):**
Explizite Preisjahrwahl im Kalkulationswizard freigegeben: je Position aktuelles
Kalenderjahr als Default (`Europe/Berlin`); Folgejahr nur bei vorhandener Active-Liste
des Inventars; keine Quartals-Hardcodierung; Rebind nur bei tatsächlichem Jahrwechsel
und bewusstem Speichern; historische Pins stabil; Budget denselben Jahresvertrag;
`expected_price_list_id` mit HTTP 409 bei Active-Drift. `BL-P4-01` ist damit
abgeschlossen. MORE-Produktiv-Workbook-Mapping bleibt Lieferdaten-/Adapterpunkt.

**Product-Owner-Teilfreigabe (23. September 2026, UX-GATE-D / BL-P8-02a / PO-BLP802A-1):**
Ausschließlich der **operative Statuskern** freigegeben:

- bewusste Statusübergänge Disposition/Admin/Geschäftsführung:
  `at_disposition` → `in_progress`;
  `in_progress` ↔ `material_missing` / `material_received`;
  `in_progress` / `material_received` → `disposed`;
  Wiederöffnung `disposed` → `in_progress` mit Pflichtbegründung
- Statushistorie (append-only) und Anzeige auf der Dispo-Detailseite
- bestehende Vier-Augen-Freigabe unverändert

Diese Entscheidung gibt **nicht** die gesamte operative Disposition frei.

**Product-Owner-Teilfreigabe (23. September 2026, UX-GATE-D / BL-P8-02b / PO-BLP802B-1):**
Ausschließlich der **strukturierte Rückfrage-/Antwortprozess** freigegeben
(`STA-002`, `CMT-003`, `AT-17`):

- Ask: Disposition/Admin/GF aus `at_disposition` / `in_progress` /
  `material_missing` / `material_received` → `sales_inquiry` mit Pflichtnotiz
- Answer: Vertrieb/Admin/GF → aktiv zurück `at_disposition` mit Pflichtnotiz
- append-only Kommunikationsfundament (`dispo_order_comments`, Typen
  `sales_inquiry` / `sales_inquiry_response`); CMT-002 für diese Einträge
- keine automatische Wiederherstellung des vorherigen Status
- kein Empfänger-Picker; keine Notifications in diesem Slice

Ausdrücklich **nicht** freigegeben bleiben weiterhin u. a.:

- allgemeine freie Kommentare (`CMT-001`) / vollständiges Kommentar-Modul BL-P9-02
- Benachrichtigungen / Mail (`NOT-001` / `NOT-002`)
- Material-Uploads / Audio
- Kundenbestätigung **Datei-Upload** (Ausnahmeweg ohne Upload: PO-BLP802C-1)
- Status `completed` / `cancelled`
- Rechnung-per-Ende / Abschlussprüfungen
- neue operative Fach-/Textfelder, Priorität, Bearbeitungsdatum
- Kombinationstabelle, Standardangebote, Reporting, SWF/OA/Social

**Weiterhin blockiert** (keine Umsetzung ohne erneute PO-Freigabe):

- operative Bearbeitung durch die Disposition **außerhalb** BL-P8-02a/02b
- Material, Uploads, allgemeine Kommentare
- vollständiger Statusworkflow inkl. Completed/Cancelled
- Überschreiben oder Rücksetzen desselben abgelehnten Snapshots auf `Entwurf`
- Standardangebots-Fachoberflächen
- Administration der übrigen Initialkataloge (Kombinationstabelle) –
  Oberkategorien/Werbemittel (ADV-001b), Inventar-Admin (BL-P2-01a),
  Preislisten-Lifecycle (BL-P4-01a), Excel-Import (BL-P4-01b) und
  Wizard-Jahreswahl (BL-P4-01c) sind teilfreigegeben; Kombi-Mitgliedschaften
  entfallen (PO-BL-P2-01-KOMBI)
- Auswertungen und abschließende Fachoberflächen
- Freigabe-Administration außerhalb der bereits freigegebenen Vier-Augen-Kette

Der Status `Entwurf` sowie die Freigabe-Kette bis Disposition/Ablehnung sind
technisch und fachlich umgesetzt. Der abgelehnte Dispoauftrag bleibt als
unveränderbarer, terminaler Snapshot erhalten; der Ersteller kann die Kalkulation
nachbessern und einen neuen verknüpften Entwurf erzeugen. Operative Statuswerte
bis Disponiert sind über BL-P8-02a erreichbar; Rückfrage Vertrieb über BL-P8-02b; Ausnahmeweg Kundenbestätigung über BL-P8-02c.
Completed/Cancelled bleiben definiert, aber unerreichbar.

## Erlaubt / nicht erlaubt

| Gate-Status | Erlaubt |
|---|---|
| A und B freigegeben | App-Shell, gemeinsame Komponenten, Kalkulations-Wizard, Spot Classic, serverseitige Berechnung |
| C blockiert | Trailer/SWF, Influencer, Social Media und weitere Werbeelemente |
| D teilweise freigegeben | Dispoauftrag-Entwurf + Vier-Augen-Freigabe + Dyn-Feld-Admin + Katalog + Inventar-Admin (BL-P2-01a) + Preislisten-Lifecycle (BL-P4-01a) + Excel-Import ohne Auto-Aktivierung (BL-P4-01b) + Wizard-Jahreswahl (BL-P4-01c / PO-PRI-YEAR-1) + operativer Statuskern (BL-P8-02a / PO-BLP802A-1) + Rückfrage Vertrieb (BL-P8-02b / PO-BLP802B-1) + Kundenbestätigung Ausnahmeweg (BL-P8-02c / PO-BLP802C-1); Datei-Uploads/allgemeine Kommentare/Completed/Cancelled/Notifications und Kombinationstabelle weiterhin gesperrt; Kombi-Mitgliedschaften entfallen |

Produktivdeployment und erfundene produktive Preis- oder Stammdaten bleiben
unabhängig von den Gates unzulässig.
