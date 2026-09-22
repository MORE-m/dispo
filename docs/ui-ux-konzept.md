# UI-/UX-Konzept V1

## Zielbild

Die Oberfläche soll die heutige Excel-Logik verständlicher machen, ohne fachliche
Details zu verstecken. Nutzer müssen jederzeit erkennen:

- wo sie sich im Prozess befinden,
- welche Angaben noch fehlen,
- wie ein Preis zustande kommt,
- welche Freigabe oder Person als Nächstes zuständig ist,
- welche Daten historisch oder aktuell sind.

## Hauptnavigation

Die Hauptnavigation liegt dauerhaft links (Desktop: ausgeschriebene Leiste,
Tablet: Icon-Leiste mit Ausklappen). Es gibt keine obere Hauptnavigation.
Die Arbeitsfläche nutzt die verfügbare Breite (`UX-GATE-A`).

1. **Übersicht**
2. **Kalkulationen**
3. **Standardangebote**
4. **Dispoaufträge**
5. **Auswertungen**
6. **Stammdaten**
7. **Administration** – nur berechtigte Rollen

Menüpunkte sind rollenabhängig sichtbar (`AUTH-001`, `STD-003`, `AUTH-006`,
`AUTH-007`). Solange betroffene Teilbereiche von `UX-GATE-C` oder `UX-GATE-D`
nicht freigegeben sind (Details: [`ux-ui-gate.md`](ux-ui-gate.md)), führen die
betroffenen Punkte auf einen Sperr-/Leerzustand, nicht auf eine Schein-Fachseite.

**Standardangebote** bleibt ein eigener linker Navigationspunkt (`STD-003`). Die
Fachoberfläche gehört zu `UX-GATE-D`.

Profil und Abmeldung liegen in der linken Leiste.

## Übersicht

Die Übersicht zeigt rollenbezogene Arbeitsvorräte statt allgemeiner Dekoration:

- eigene offene Entwürfe,
- ausstehende Freigaben,
- Rückfragen an Vertrieb,
- neue Aufträge für die Disposition,
- Material fehlt,
- dringende oder überfällige Aufträge,
- zuletzt bearbeitete Kalkulationen und Dispoaufträge.

Geschäftsführung und Admin erhalten zusätzlich kompakte KPIs und Auffälligkeiten.

## Listenansichten

Kalkulations- und Dispolisten unterstützen:

- Volltextsuche,
- kombinierbare Filterchips,
- Sortierung und Pagination,
- speicherbare persönliche Spaltenauswahl,
- klar sichtbaren Status, Verantwortlichen und Aktualisierungszeitpunkt,
- direkte Aktionen nur, wenn sie im aktuellen Status erlaubt sind.

Filterzustände sollen in der URL abbildbar sein, damit Ansichten teilbar und nach
Navigation wiederherstellbar sind.

## Kalkulation bearbeiten

Die Erfassung folgt dem Wizard (`UX-GATE-B`):

1. Grunddaten
2. Werbeelemente
3. Konditionen
4. Zusammenfassung

Briefing ist optional.

Zwei Planungswege: **Selbst planen** und **Mit Budget planen** (siehe
[`ux-ui-gate.md`](ux-ui-gate.md)).

### Seitenaufbau

- **Kopfbereich:** Kunde, Agentur, Mediaberater, Kampagne, Produkt/Titel
  (kundenbezogene Stammdaten folgen mit CRM; im aktuellen Slice Freitextfelder).
- **Positionsnavigation:** mehrsenderfähige Liste oder Karten aller Positionen
  (Sender/Kombi, Werbemittel, Menge, Länge, Teilsumme).
- **Arbeitsbereich:** Eingaben der ausgewählten Position; bei Spot Classic ein
  **sichtbares, frei editierbares Längenfeld in Sekunden** (`SPT-015`).
- **Preiszusammenfassung:** Live-Kostensumme je Werbeelement und für die
  Kalkulation (`CAL-005`); automatisch berechnete Felder ohne Erklärungstext.
- **Fuß-/Aktionsleiste:** speichern, Werbeelement hinzufügen, bei Budgetpfad
  Vorschlag erzeugen und ausdrücklich übernehmen.

### Aktuelle Wizard-Oberfläche

Referenzscreenshots nach UX-GATE-A/B (Desktop/Mobil):

- Schritt 2 Preiszeiträume (Desktop):
  [`docs/screenshots/wizard-step-2-time-ranges-desktop.png`](screenshots/wizard-step-2-time-ranges-desktop.png)
- Schritt 3 Konditionen (Desktop):
  [`docs/screenshots/wizard-step-3-conditions-desktop.png`](screenshots/wizard-step-3-conditions-desktop.png)
- Schritt 3 Konditionen (Mobil):
  [`docs/screenshots/wizard-step-3-conditions-mobile.png`](screenshots/wizard-step-3-conditions-mobile.png)

Ende ist exklusiv: `08:00–18:00` bedeutet Preisstunden 08:00 bis 17:59 Uhr.
Gesamtspots sind die Summe der Zeitraum-Spots, nicht ein zweites Eingabefeld.

### Position anlegen

1. Inventar auswählen (Logo-Slot mit Platzhalter, sobald kein Logo vorliegt).
2. Nur erlaubte Werbemittel anzeigen; in diesem Slice Spot Classic.
3. Preiszeiträume mit Beginn, Ende, Tagesgruppe und Spots erfassen (`SPT-001`–`SPT-004`).
4. Spotlänge und Konditionen erfassen; Gesamtspots sind die Summe der Zeiträume.
5. Preis still neu berechnen; Hinweise nur bei fehlenden oder widersprüchlichen Angaben.

Nach Anlage ist die Kalkulationsart sichtbar gesperrt. Für einen Wechsel wird
eine neue Position benötigt (`CAL-002`).

Mehrere Sender- und Kombipositionen in einer Kalkulation sind der Normalfall
(`CAL-001`). Jede Position bleibt einzeln anwählbar und editierbar.

### Mit Budget planen

1. Zielbudget N/N, Sender/Kombis, erlaubte Preiszeiträume, Spotlänge,
   Rabatte der Werbeelemente, Auftragsrabatte, AE-Checkbox und Verteilungslogik vorgeben.
2. Das System erzeugt einen neuen Vorschlag (gleich verteilen oder Spotanzahl
   maximieren). Kein bestehendes Senderverhältnis (`BUD-005`).
3. Rest oder Überschreitung ausweisen (`BUD-007`).
4. Explizite Übernahme oder Verwerfen (`BUD-008`).
5. Keine Reichweiten- oder KI-Formulierungen (`BUD-009`).

### Rechenerklärung

Jede Position bietet eine aufklappbare Erklärung mit:

- Preislistenname und Version,
- ausgewählten Stunden/Datumsmengen,
- Länge und Index,
- Aufschlägen,
- Rabattfolge und AE,
- internen Zwischenwerten und Rundung,
- Sonderfreigabegrund.

## Durchschnitt und Planer

Zeitfenster werden als wiederholbare Zeilen erfasst. Überschneidungen werden direkt
markiert und können mit einem Klick zu einer eindeutigen Stundenmenge zusammengeführt
werden; das erzeugt keine gruppierte Zeitschiene (`SPT-016`). Der Planer bietet
Wochen- und Monatsnavigation, Tastatureingabe, Kopieren über Zellen sowie sichtbare
Tagesgruppenpreise. Große Zeiträume werden virtuell bzw. seitenweise geladen.

Trailer, Allongen und weitere SWF aus der Trailerkalkulation dürfen abweichend
gruppierte Zeitschienen und Standardlängen als Vorbelegung nutzen (`SWF-008`).

## Standardangebote

Flow (verbindliches Interaktionsmuster, nicht implementiert):

1. Liste nach Status, Sender/Kombi, Version und Aktualität filtern.
2. Entwurf bearbeiten; veröffentlichen setzt Autor und Zeitpunkt.
3. Vertrieb öffnet eine veröffentlichte Vorlage und wählt Übernehmen.
4. Es entsteht eine Kundenkalkulation; Kunde/Agentur werden dort ergänzt.
5. Vertrieb passt Positionen, Längen, Mengen und den Budget-Assistenten an.
6. Dispoauftrag nur aus dieser Kundenkalkulation, nicht aus der Vorlagenansicht.

## Dispoauftrag

### Kopfbereich

- Nummer, Status, Priorität und gewünschtes Bearbeitungsdatum,
- Kunde/Kampagne und Mediaberater,
- nächster notwendiger Schritt,
- gesperrter Snapshot-Hinweis mit Ursprungskalkulation.

### Register oder Abschnitte

1. Übersicht und Kopfdaten
2. Positionen
3. Investition und Abrechnung
4. Uploads
5. Kommentare und Rückfragen
6. Freigaben
7. vollständige Historie

Statusaktionen stehen prominent, aber nur rollen- und zustandsabhängig zur
Verfügung. Pflichtbegründungen werden im Aktionsdialog abgefragt.

## Pflichtfelder und Validierung

- Pflichtfelder erhalten nicht nur ein Sternchen, sondern einen verständlichen Fehlertext.
- Vor Freigabe/Statuswechsel erscheint eine zusammengefasste Fehlerliste mit Sprunglinks.
- Unsichtbare Felder erzeugen standardmäßig keinen Fehler.
- Serverfehler werden dem konkreten Feld zugeordnet, wenn möglich.
- Gesperrte Felder zeigen den Grund und den notwendigen nächsten Schritt.

## Freigabeoberfläche

Der Freigeber sieht vor der Entscheidung:

- Zusammenfassung der kaufmännischen Werte,
- ausgelöste Sonderfreigabegründe,
- Kundenbestätigung oder Ausnahme,
- Unterschiede zur letzten eingereichten Version,
- Ersteller und bisherige Freigaben.

Freigeben und Ablehnen sind getrennte Aktionen. Ablehnen benötigt immer eine
Begründung. Eine eigene Freigabe wird nicht nur ausgeblendet, sondern serverseitig
blockiert.

## Administration

Adminmodule nutzen ein einheitliches Muster:

- Liste mit Filter und Aktivstatus,
- Detailseite mit Version und Gültigkeit,
- Entwurf bearbeiten,
- Vorschau/Differenz prüfen,
- bewusst aktivieren,
- Historie einsehen.

Bei Preisimporten folgt der Ablauf `Upload → Spaltenprüfung → Validierung → Vorschau
→ Aktivierung`. Fehlerhafte Zeilen werden mit Zeile, Spalte und verständlichem Grund
ausgegeben; es gibt keine Teilaktivierung.

## Kontext-Hilfe

Eine einklappbare Hilfeseitenleiste kann Hinweise aus Kombinationstabelle und
dynamischen Regeln anzeigen. Sie ist keine eigene fachliche Entscheidungsinstanz.
Ein späterer KI-Assistent darf nur erklären und navigieren, aber keine Preise,
Freigaben oder Status autonom verändern.

## Responsive Verhalten

Primäres Ziel sind aktuelle Desktop-/Laptop-Browser. Listen und Formulare müssen
auf Tablets sinnvoll nutzbar bleiben. Mobile Nutzung darf auf Übersicht,
Freigabe, Kommentare und Statusaktionen optimiert werden; der komplexe Kalender
muss nicht auf kleinen Displays dieselbe Dichte wie Desktop erreichen.

## Barrierearmut

- vollständige Tastaturbedienung,
- sichtbarer Fokus,
- echte Labels und Gruppen,
- ausreichende Kontraste,
- Status nicht ausschließlich über Farbe vermitteln,
- Tabellenüberschriften und Fehlermeldungen semantisch korrekt ausgeben.

## Gate-Zuordnung

Freigegeben und umzusetzen: `UX-GATE-A` (Shell, Navigation, Komponenten) und
`UX-GATE-B` (Wizard, Mehrsender, Spot Classic).

Weiterhin zu gestalten, aber **nicht** umzusetzen, solange `UX-GATE-C`/`UX-GATE-D`
blockiert sind:

- Trailer/SWF- und Influencer-/Social-Oberflächen
- Übernahme-Flow Standardangebot → Kundenkalkulation
- Dispoauftrag, Freigaben, Administration der Initialkataloge
- Kalenderdichte für große Zeiträume, dynamischer Feldeditor, PDF-Layouts

