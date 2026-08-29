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

1. **Dashboard**
2. **Kalkulationen**
3. **Dispoaufträge**
4. **Kunden & Agenturen**
5. **Auswertungen**
6. **Administration** – nur berechtigte Rollen

Benachrichtigungen und persönliches Profil liegen global in der Kopfzeile.

## Dashboard

Das Dashboard zeigt rollenbezogene Arbeitsvorräte statt allgemeiner Dekoration:

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

### Seitenaufbau

- **Kopfbereich:** Kunde, Agentur, Mediaberater, Kampagne, Produkt/Titel.
- **Positionsnavigation:** kompakte Karten oder linke Liste aller Positionen.
- **Arbeitsbereich:** Eingaben der ausgewählten Position.
- **Rechte Seitenleiste:** Preiszusammenfassung, Pflichtfehler und Rechenerklärung.
- **Fuß-/Aktionsleiste:** speichern, kopieren, Position hinzufügen, Dispoauftrag erstellen.

### Position anlegen

Empfohlener geführter Ablauf:

1. Inventar auswählen.
2. Nur erlaubte Werbemittel anzeigen.
3. Kalkulationsart auswählen.
4. Fachspezifische Eingaben erfassen.
5. Preis, Regeln und Hinweise in Echtzeit als Vorschau zeigen.

Nach Anlage ist die Kalkulationsart sichtbar gesperrt. Die Oberfläche erklärt,
dass für einen Wechsel eine neue Position erstellt werden muss (`CAL-002`).

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
markiert und können mit einem Klick zusammengeführt werden. Der Planer bietet
Wochen- und Monatsnavigation, Tastatureingabe, Kopieren über Zellen sowie sichtbare
Tagesgruppenpreise. Große Zeiträume werden virtuell bzw. seitenweise geladen.

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

## Vor Umsetzung noch zu gestalten

- finales Navigations- und Seitenraster,
- Wireframes für Kalkulationsposition und Dispoauftrag,
- Kalender-/Planerinteraktion,
- dynamischer Feldeditor und Regelbuilder,
- PDF-Layouts.

