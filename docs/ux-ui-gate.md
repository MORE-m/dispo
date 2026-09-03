# UX/UI-Gates (gestuft)

- **Stand:** 3. September 2026
- **Product-Owner-Entscheidung:** UX-GATE-A und UX-GATE-B freigegeben;
  UX-GATE-C blockiert; UX-GATE-D teilweise freigegeben (Entwurf + Vier-Augen-Freigabe)
- **Technische Abnahme:** UX-GATE-A/B abgenommen (HEAD `976aae5`,
  Actions [33252415668](https://github.com/MORE-m/dispo/actions/runs/33252415668))

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
| `UX-GATE-D` | Dispoauftrag, Freigaben, Standardangebots-Fachoberflächen, Administration, abschließende Fachoberflächen | **teilweise freigegeben** (Entwurf + Vier-Augen-Freigabe) · übrige Teile blockiert |

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
- zugehörige Policies, Persistenz, Auditierung und Tests

**Weiterhin blockiert** (keine Umsetzung ohne erneute PO-Freigabe):

- operative Bearbeitung durch die Disposition
- Material, Uploads, Kommentare, Rückfragen
- vollständiger Statusworkflow ab `In Bearbeitung`
- Rückkehr abgelehnter Aufträge in den Entwurf / Wiedereinreichung
- Standardangebots-Fachoberflächen
- Administration der Initialkataloge
- Auswertungen und abschließende Fachoberflächen

Der Status `Entwurf` sowie die Freigabe-Kette bis Disposition/Ablehnung sind
technisch und fachlich umgesetzt. Weitere Statuswerte bleiben definiert, aber
noch nicht erreichbar.

## Erlaubt / nicht erlaubt

| Gate-Status | Erlaubt |
|---|---|
| A und B freigegeben | App-Shell, gemeinsame Komponenten, Kalkulations-Wizard, Spot Classic, serverseitige Berechnung |
| C und D blockiert | nur Sperr-/Leerzustände in der Navigation, keine Schein-Fachseiten |
| D teilweise freigegeben | Dispoauftrag-Entwurf + Vier-Augen-Freigabe; operative Disposition und Rest von GATE-D weiterhin gesperrt |

Produktivdeployment und erfundene produktive Preis- oder Stammdaten bleiben
unabhängig von den Gates unzulässig.
