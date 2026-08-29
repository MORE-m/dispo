# UX/UI-Gates (gestuft)

- **Stand:** 29. August 2026
- **Product-Owner-Entscheidung:** UX-GATE-A und UX-GATE-B freigegeben;
  UX-GATE-C und UX-GATE-D blockiert

Das frühere Einzelgate `BL-GATE-UXUI` ist durch vier Teil-Gates ersetzt.
[`ui-ux-konzept.md`](ui-ux-konzept.md) bleibt die fachliche Navigations- und
Interaktionsbasis. Die Teil-Gates steuern, welche Oberflächen umgesetzt werden
dürfen.

## Übersicht

| Gate | Umfang | Status |
|---|---|---|
| `UX-GATE-A` | Designsystem, App-Shell, linke Navigation, Seitenlayout, gemeinsame UI-Komponenten | **fachlich freigegeben** · technische Abnahme nach grüner CI |
| `UX-GATE-B` | Kalkulations-Wizard, Mehrsenderplanung, Spot Classic (Durchschnitt) | **fachlich freigegeben** · technische Abnahme nach grüner CI |
| `UX-GATE-C` | Trailer/SWF, Influencer, Social Media und weitere Werbeelemente | blockiert |
| `UX-GATE-D` | Dispoauftrag, Freigaben, Standardangebots-Fachoberflächen, Administration, abschließende Fachoberflächen | blockiert |

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

**Selbst planen:** Sender/Kombis, Werbeelemente, Preisstunden, Spotlängen,
Spotanzahlen und Konditionen legt der Benutzer fest. Ein optionales Zielbudget
N/N dient nur als Vergleich mit dem aktuellen N/N-Invest.

**Mit Budget planen:** Zuerst Zielbudget N/N, Sender/Kombis, erlaubte Preisstunden,
Spotlänge je Spot-Classic-Werbeelement, Positionsrabatt, AE, zusätzlicher
Auftragsrabatt und Verteilungslogik. Danach erzeugt das System einen **neuen**
Vorschlag. Es gibt kein „bestehendes Senderverhältnis“, weil keine manuelle
Vorplanung vorausgesetzt wird.

V1-Verteilungslogiken:

- Budget je ausgewähltem Sender gleich verteilen
- Spotanzahl innerhalb der gewählten Preisstunden maximieren

Der Vorschlag wird erst nach ausdrücklicher Übernahme Teil der Kalkulation und
bleibt anschließend vollständig editierbar. Keine KI-, Reichweiten- oder
Leistungsoptimierung (`BUD-008`, `BUD-009`).

### Spot Classic und Konditionen

Verbindliche Interaktions- und Fachartefakte:

| Artefakt | Fachbezug |
|---|---|
| Sichtbares, frei editierbares Längenfeld in Sekunden | `SPT-015` |
| Mehrere Sender/Kombis und unterschiedliche Werbeelemente | `CAL-001` |
| Einzelne Preisstunden, keine gruppierten Zeitschienen | `SPT-016` |
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

## UX-GATE-D – Abschlussprozesse (blockiert)

Nicht umsetzen, bis der Product Owner freigibt:

- Dispo-Fachoberflächen
- abschließende Freigabeoberflächen
- Standardangebots-Fachoberflächen (Navigation darf vorbereitet sein)
- Administration der Initialkataloge
- übrige abschließende Fachoberflächen

## Erlaubt / nicht erlaubt

| Gate-Status | Erlaubt |
|---|---|
| A und B freigegeben | App-Shell, gemeinsame Komponenten, Kalkulations-Wizard, Spot Classic, serverseitige Berechnung |
| C und D blockiert | nur Sperr-/Leerzustände in der Navigation, keine Schein-Fachseiten |

Produktivdeployment und erfundene produktive Preis- oder Stammdaten bleiben
unabhängig von den Gates unzulässig.
