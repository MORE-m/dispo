# Dynamisches Feldsystem

## Ziel

Das System kombiniert feste fachkritische Kernobjekte mit administrierbaren
Zusatzfeldern. Berechnungs-, Freigabe-, Status-, Audit- und Snapshotlogik bleiben
Systemlogik. Produktabhängige Zusatzinformationen, Sichtbarkeit, Pflichtregeln und
Validierungen werden konfiguriert.

Verbindliche Anforderungen: `DYN-001` bis `DYN-008`, `VER-001` bis `VER-007`,
`ADM-001` bis `ADM-003`.

## Umsetzungsstand DF-1 (Kalkulation)

Produktiv nutzbare Grundlage ohne Admin-UI:

- Systemfelder: `campaign_period` (Kopf), `period_open` und
  `position_flight_period` (Position).
- Feldset `system_calculation_core` Version 1; Revision über
  `current_revision_id`, Aktivierung über `active_version_id`.
- Neue Kalkulationen materialisieren einen unveränderlichen
  `configuration_snapshot`; Legacy-Kalkulationen erhalten denselben Snapshot
  einmalig per Migration inkl. `period_open = true` je Position.
- Werte liegen in `calculation_field_values` /
  `calculation_position_field_values` (keine festen Perioden-Spalten).
- Serverseitig ausgewertet: `field_equals` + `require_field` auf Snapshot-Regeln
  (`period_open = false` → `position_flight_period` Pflicht).
- Bewusst nicht in DF-1: Admin-Feldeditor, Dispo-Werte,
  `billing_special_features` / `disposition_notes` (DF-2), Custom Fields,
  Optionskatalog, volle Regelmatrix.

## Umsetzungsstand DF-2 (Dispoauftrag)

- Feldset `system_dispo_order_core` mit `billing_special_features` und
  `disposition_notes` (`long_text`, Header, `applies_to=dispo_order`).
- Compose-Snapshot je Dispoauftrag: kopiert Calc-origin-Definitionen/Regeln
  (`campaign_period`, `period_open`, `position_flight_period`) aus dem
  Kalkulationssnapshot und materialisiert die zwei Dispo-Texte aus dem aktiven
  Dispo-Feldset. `field_set_*` zeigt auf `system_dispo_order_core`;
  `source_configuration_snapshot_id` hält die Calc-Herkunft (kein Laufzeit-Lesen).
  Quellsnapshot muss Calculation-Source (`seed_active` / `legacy_backfill`) sein.
- Werte in `dispo_order_field_values` / `dispo_order_position_field_values`.
- Capture-Semantik: neue Create/Revision legen für jedes Calc-origin-Feld immer
  eine Value-Zeile an (auch bei optional leerem Zeitraum). Legacy-Backfill legt
  keine Calc-origin-Zeilen an („Nicht erfasst“). Draft-Sync füllt nur fehlende
  Capture-Zeilen positionsbezogen; vorhandene Werte und Dispo-Texte bleiben.
- Missing-/Submit-Prüfung je Position; bewusst leere Capture-Zeilen sind vollständig.
- Draft: Texte editierbar; Calc-origin read-only; Hilfetexte aus dem DA-Snapshot.
- Revision (PO-DF2-1): Texte aus Vorgänger per Schlüssel, Calc-Werte frisch.

## Umsetzungsstand DF-3.1 (Admin Systemfelder / Kern-Feldsets)

Erster technischer Teilslice von DF-3 – **kein Abschluss von DF-3**.

- UX-GATE-D Teilfreigabe „Administration dynamischer Felder“.
- Admin kann geschützte Systemdefinitionen revisionieren (Label, Hilfe, Gruppe,
  Sort-Default, reportable); Key/Typ/Scope bleiben unveränderlich.
- Feldsets `system_calculation_core` und `system_dispo_order_core`: Draft aus
  aktiver/archivierter Version, Membership (Revision-Pin, Sort, Overrides),
  Copy-as-template, Activate mit `lock_version`, Audit (`ADM-001`).
- Statische Vorschau mit Beispielwerten (`ADM-002`); Regeln nur lesbar.
- Historische Snapshots unverändert (`VER-007`, AT-14).
- Bewusst nicht in DF-3.1: Custom Fields, Optionen, Assignments, Regel-Editor,
  neue Feldtypen, Provenance-Mehrquellen.

## Umsetzungsstand DF-3.2a (Custom Header-Textfelder)

Zweiter Teilslice von DF-3 auf Feature-Branch
`feat/df3-2a-custom-header-text-fields` – **DF-3 bleibt unvollständig**.

- Custom-Definitionen: nur `short_text` / `long_text`, Scope fest `header`,
  `applies_to` calculation|dispo_order|both; Key aus Label (vor Save
  editierbar), danach unveränderlich.
- `max_length` in `validation_json`: short_text max. 255, long_text max. 20000;
  Speicher Calc/Dispo Header-Text als MEDIUMTEXT bzw. erweiterte String-Spalte.
- Admin: Index System vs. Eigene Felder; Anlegen; Show mit struktureller
  Änderung solange unbenutzt, sonst Revision; Deaktivieren/Reaktivieren/Löschen
  mit `lock_version` und deutscher 409-Meldung.
- Kern-Feldset-Draft: aktive Custom-Header mit passendem `applies_to`
  hinzufügen/entfernen; Positions-Memberships damals abgelehnt (jetzt DF-3.2b).
- Runtime: Kalkulations-Wizard Abschnitt „Weitere Angaben“; Dispo-Show
  editierbare native Custom-Header sowie Calc-origin Custom read-only
  „Aus Kalkulation“.
- Bewusst später in DF-3.2a: Position-Custom (DF-3.2b), Optionen, Assignments,
  Regel-Editor.

## Umsetzungsstand DF-3.3-fs (freie Feldsets)

Teilslice nach ADV-001a – **kein** Abschluss von DF-3, **kein** Start von
DF-3.3a/b. Freie Feldsets haben **noch keine Runtime-Wirkung** auf Kalkulation
oder Dispoauftrag.

- `field_sets`: `is_system`, `applies_to` (calculation|dispo_order|both),
  `is_assignable`; Core-Backfill fail-closed nur für
  `system_calculation_core` / `system_dispo_order_core`.
- Freie Feldsets anlegen: Key (ohne `system_`, nach Save immutable), Name,
  Gültigkeit; atomar leerer Draft v1; `is_assignable=false` bis erste Activate.
- Membership nur Custom-Definitionen; `applies_to=both` darf Calc-/Dispo-/both-
  Definitionen mischen; leere Version nicht aktivierbar.
- Deaktivieren setzt `is_assignable=false` (Active-Version bleibt); spätere
  Versionsaktivierung reaktiviert nicht automatisch; Reaktivieren ist eigene Aktion.
- Kern-Feldsets: dauerhaft `is_assignable=false`, Metadaten geschützt.
- Admin unter Dyn-Feld-Teilfreigabe; E2E isoliert `playwright.df33fs.config.ts`.
- Bewusst nicht: Assignments, Merge, Snapshot-Quellengraph, Runtime-Auswertung.

## Umsetzungsstand DF-3.2b (Custom Position-Textfelder)

Dritter Teilslice von DF-3 auf Feature-Branch
`feat/df3-2b-custom-position-text-fields` – **DF-3 bleibt unvollständig**.

- Custom-Definitionen: nur `short_text` / `long_text`, Scope fest `position`,
  `applies_to` calculation|dispo_order|both (both wie DF-3.2a, PO-32b-4).
- Identity: Calc-Sync über Positions-`id`/`client_key`; Dispo-Zuordnung über
  `calculation_position_id`.
- Provenance Calc-Origin: Quell-Snapshot (`source_configuration_snapshot_id` +
  Key in Source-Defs), kein neues Provenance-Flag (PO-32b-3).
- Pflicht-Vollständigkeit sichtbarer Positionsfelder nur bei Dispo-Create/Revision
  aus Calc – nicht bei Calc-Store/Update, Budget-Apply oder Re-Optimize
  (PO-32b-1).
- Native Dispo-Positions-Customs: atomarer Partial-Save (PO-32b-2); Calc-Origin
  read-only.
- E2E isoliert: `playwright.df32b.config.ts` mit eigener SQLite-DB und Port.
- Bewusst später: Optionen, Assignments, Regel-Editor.

## Konfigurationsebenen

```mermaid
flowchart TD
    A[Oberkategorie] --> C[Effektive Feldkonfiguration]
    B[Wiederverwendbare Feldsets] --> C
    D[Werbemittel-Ergänzungen] --> C
    E[Systemfeld-Eigenschaften] --> C
    C --> F[Kalkulations-Snapshot]
    F --> G[Positionskonfiguration]
    G --> H[Dispo-Snapshot]
```

Prioritäten und Konfliktauflösung müssen deterministisch sein. Empfohlen ist:

1. Kategorie liefert Defaults.
2. Feldsets ergänzen wiederverwendbare Felder.
3. Werbemittel ergänzt oder überschreibt ausdrücklich erlaubte Eigenschaften.
4. Systemfelder behalten unabhängig von Sichtbarkeit ihre technische Identität.

Die genaue Merge-Reihenfolge wird vor Implementierung als Testfall festgeschrieben.

## Felddefinition

Eine Felddefinition besitzt mindestens:

| Eigenschaft | Bedeutung |
|---|---|
| stabile ID | unveränderbare technische Identität |
| interner Schlüssel | maschinenlesbarer, innerhalb des Kontexts eindeutiger Name |
| sichtbarer Name | versionierter deutscher Anzeigename |
| Feldtyp | bestimmt Speicherung, Eingabe und Validierung |
| Hilfetext | kontextbezogene Unterstützung für Nutzer |
| Gruppe | visuelle und fachliche Gruppierung |
| Reihenfolge | sortierbarer Anzeigewert |
| Aktivstatus | neu verwendbar oder nur historisch sichtbar |
| Pflicht-/Sichtbarkeitsregeln | versionierte Regelausdrücke |
| Validierung | Grenzen und Formatregeln |
| Reporteigenschaften | suchbar, filterbar, gruppierbar oder summierbar |

## Feldtypen V1

- kurzer Text, langer Text
- Zahl, Geldbetrag, Prozent
- Datum, Zeitraum von/bis, Uhrzeit, Monat-Auswahl
- Ja/Nein, Einfachauswahl, Mehrfachauswahl
- URL, E-Mail, Telefon
- Datei-Upload
- Inventar-, Werbemittel-, Ansprechpartner- und Kunden-Auswahl

Jeder Typ besitzt eine klar definierte kanonische Speicherung. Geld und Prozent
werden nicht als formatierter Text gespeichert. Referenzfelder speichern stabile
IDs und zusätzlich im Snapshot die damals sichtbare Bezeichnung.

## Auswahloptionen

Optionen besitzen stabile ID, internen Wert, sichtbaren Namen, Sortierung und
Aktivstatus. Deaktivierte Optionen:

- bleiben in alten Vorgängen lesbar,
- bleiben in Reports historisch gruppierbar,
- sind für neue Werte nicht mehr auswählbar.

## Feldsets

Feldsets sind wiederverwendbare, versionierbare Gruppen, beispielsweise:

- Targeting,
- Reporting,
- Eventdaten,
- Material,
- Social Media.

Statusmodell: `Entwurf → Aktiv → Archiviert`. Änderungen an einer aktiven Version
erzeugen eine neue Entwurfsversion; die aktive Version wird nicht in-place geändert.

## Regelmodell

V1 unterstützt Bedingungen:

- Feld X hat Wert Y,
- Feld X ist oder ist nicht leer,
- Feld X ist größer/kleiner als Y,
- Kombination mehrerer Bedingungen mit UND/ODER.

Aktionen:

- Feld wird sichtbar/unsichtbar,
- Feld wird Pflicht/optional,
- konfigurierter Hinweis oder Validierungsfehler wird ausgelöst.

Regeln referenzieren stabile Feld- und Options-IDs, niemals nur Anzeigenamen.

### Sichtbarkeit und Pflicht

Ein unsichtbares Feld erzeugt standardmäßig keinen Pflichtfehler. Eine Ausnahme ist
nur zulässig, wenn die Regel ausdrücklich unabhängig von der Sichtbarkeit gilt
(`DYN-005`). Beim Unsichtbarwerden eines bereits gefüllten Felds darf der Wert nicht
stillschweigend gelöscht werden; die fachliche Behandlung muss explizit definiert
und getestet sein.

### Initialregeln

| Bedingung | Wirkung |
|---|---|
| Reporting = ja | Reporting-E-Mail sichtbar und Pflicht |
| Targeting gewählt | Targeting-Spezifikation Pflicht |
| Zeitraum offen = nein | Positions-Flugzeitraum Pflicht (`position_flight_period`; DF-1) |
| keine Kundenbestätigung als Upload | Ausnahmegrund Pflicht, sofern Ausnahme genutzt wird |

## Validierungen

- maximale Textlänge,
- Zahl min/max,
- URL-, E-Mail- und Telefonnummernformat,
- erlaubte Dateitypen und Dateigröße,
- Pflicht- und Referenzintegrität.

Das Frontend darf dieselben Regeln für direkte Rückmeldung auswerten. Die endgültige
Entscheidung trifft immer die Serverkomponente bei Speichern und Statuswechsel
(`DYN-004`).

## Snapshot-Lebenszyklus

### 1. Kalkulation anlegen

Die aktive Konfigurationsbasis aus Feldern, Feldsets, Regeln und Systemfeld-
Eigenschaften wird als Kalkulations-Snapshot festgelegt (`VER-002`).

### 2. Position anlegen

Die Position erhält aus dieser Snapshotbasis ihre Werbemittel-, Kombinations- und
Felddefinitionen sowie die gewählte Preislistenversion (`VER-003`).

### 3. Dispoauftrag erzeugen

Der gewählte Kalkulationsstand wird als eigener Dispo-Snapshot gespeichert
(`VER-004`). Es gibt keine automatische Synchronisation zurück oder vorwärts.

## Historische Darstellung

Ein Snapshot muss ohne Zugriff auf die aktuell aktive Definition verständlich
bleiben. Deshalb werden mindestens Schlüssel, Anzeigename, Typ, Optionen,
Formatierung, Regeln/Ergebnisse und relevante Quellversionen snapshot-stabil
aufbewahrt. Alte Feldnamen und deaktivierte Optionen bleiben sichtbar.

## Reporting

Dynamische Werte werden typisiert gespeichert und für Reports normalisiert:

- Zahlen/Geld summierbar,
- Auswahlwerte gruppierbar,
- Texte durchsuchbar,
- Daten als Zeitraumfilter,
- Mehrfachauswahl über einzelne Optionsbeziehungen auswertbar.

Admin kann Reportfähigkeit deaktivieren; diese Entscheidung ist versioniert.

## Admin-Vorschau

Vor Aktivierung zeigt ein Testformular:

- geerbte und eigene Felder in endgültiger Reihenfolge,
- sichtbare/unsichtbare Zustände für Beispielwerte,
- Pflicht- und Validierungsfehler,
- Herkunft jeder Definition und Regel,
- Unterschiede zur aktuell aktiven Version.

