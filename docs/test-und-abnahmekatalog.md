# Test- und Abnahmekatalog

## Strategie

Die fachliche Mindestabnahme besteht aus den Szenarien `AT-01` bis `AT-22`.
Zusätzlich benötigt jede Umsetzung:

- Unit-Tests für Formeln, Regelauswertung und Statusentscheidungen,
- Integrationstests für Datenbank, Snapshots, Importe und Jobs,
- Feature-/HTTP-Tests für Berechtigungen und vollständige Nutzerabläufe,
- Exporttests für PDF, Excel und CSV,
- Regressionstests für jeden behobenen fachlichen Fehler.

Tests verwenden feste Preis- und Zeitdaten. Aktuelles Datum, Zeitzone und
Zufallswerte werden kontrolliert, damit Ergebnisse reproduzierbar bleiben.

## Mindestabnahme

| ID | Betroffene Anforderungen | Szenario | Erwartung |
|---|---|---|---|
| AT-01 | SPT-001–SPT-004, SPT-009, PRI-006 | Mo–Fr, zwei Zeitfenster, 10 Spots, 20 s | Gleichgewichteter eindeutiger Stundenmittelwert; Index 105; korrekte Aufschläge und Rundung |
| AT-02 | SPT-005–SPT-008 | Planer über Mo–Fr, Samstag und Sonntag | Datum bestimmt Tagesgruppe; jede Zelle nutzt richtigen Stundenpreis |
| AT-03 | SPT-009, SPT-010 | Single-Spots mit 46 s und 100 s | 46 s erzeugt nur Hinweis; beide berechenbar mit Index 95 |
| AT-04 | SPT-012–SPT-014 | Hauptspot plus Allonge | Komponenten sichtbar; je Regel einzeln oder über Gesamtlänge gerechnet |
| AT-05 | SWF-001–SWF-005 | Trailer 20 s, +30 %, Zeitfenster | Sekundenpreis × 20 × 1,30; kein Spotlängenindex |
| AT-06 | COM-001–COM-004 | 10 % Positions- und 10 % Auftragsrabatt | Effektiver Rabatt 19 %; Grenze wird gegen 19 % geprüft |
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

## Pflichtklassen für Negativtests

- Zugriff ohne Anmeldung,
- Rolle ohne Berechtigung,
- Ersteller = Freigeber,
- veraltete Versionsnummer bei konkurrierender Bearbeitung,
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

- alle 22 Mindestfälle erfolgreich sind,
- alle MUSS-Anforderungen tracebar abgedeckt sind,
- keine offenen Fehler der Schwere kritisch/hoch bestehen,
- Preislisten- und Kombinationsinitialdaten abgenommen wurden,
- PDF-/Excel-/CSV-Exporte anhand freigegebener Muster geprüft sind,
- Wiederherstellung und Berechtigungsmatrix getestet wurden.

