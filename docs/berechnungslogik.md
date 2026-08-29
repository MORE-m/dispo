# Berechnungslogik

## Zweck und Geltung

Dieses Dokument ist die technische Lesefassung der kaufmännischen Anforderungen.
Verbindlich sind insbesondere `GEN-002`, `PRI-005`, `PRI-006`, `SPT-*`, `SWF-*`,
`OA-*`, `COM-*`, `CAL-001`, `CAL-005` und `BUD-*` aus dem [Anforderungskatalog](anforderungskatalog.md).

Alle Formeln werden autoritativ auf dem Server ausgeführt. Eingaben, Zwischenwerte,
verwendete Versionen und Rundung müssen für Support, Audit und PDF-Ausgabe
nachvollziehbar gespeichert oder reproduzierbar sein.

## Zahlentypen und Rundung

- Geld, TKP, Faktoren und Prozentwerte als Dezimaltypen speichern.
- Intern mit mindestens vier Dezimalstellen rechnen.
- Keine binären `float`-/`double`-Typen für kaufmännische Ergebnisse.
- Erst Positions- und Auftragssummen kaufmännisch auf zwei Nachkommastellen runden.
- Eine Anzeige-Rundung darf nicht als Eingang der nächsten Rechenstufe verwendet werden.
- Prozentwerte fachlich als Prozent anzeigen, intern konsistent als Dezimalfaktor oder Prozentzahl führen.

## Tagesgruppen

Importierte Basispreise existieren je Uhrstunde für `Mo–Fr`, `Sa` und `So`.

```text
P(Mo–Sa, h) = (5 × P(Mo–Fr, h) + P(Sa, h)) ÷ 6
P(Mo–So, h) = (5 × P(Mo–Fr, h) + P(Sa, h) + P(So, h)) ÷ 7
```

`Mo–Sa` und `Mo–So` sind Ableitungen und keine unabhängig pflegbaren Preise.

## Spotlängenindex

| Tatsächliche Gesamtlänge | Index |
|---:|---:|
| 1–15 Sekunden | 110 |
| 16–24 Sekunden | 105 |
| 25–34 Sekunden | 100 |
| ab 35 Sekunden | 95 |

Der Index gilt initial für alle Sender und Spot-Werbemittel. Auch ab 100 Sekunden
bleibt Index 95 gültig (`SPT-009`).

**Snapshot-Verhalten (UX-GATE-B):**

- Neue Positionen: Index aus `SpotLengthIndex::forSeconds()` zur aktuellen Länge.
- Unveränderte bestehende Position: gespeicherter `length_index` der Position
  (kein Browser-Input).
- Geänderte Spotlänge: Index neu bestimmen und persistieren.

## Preis- und Regel-Snapshot (`PRI-004`, `VER-002`)

- Neue Positionen und echte Inventar-/Werbemittelwechsel: nur aktive, zulässige
  Kombinationen und aktive Preislisten.
- Unveränderte bestehende Position: gespeicherte Preisliste (auch archiviert),
  Stundenpreise, Aufschlag, Rabatt-/AE-Fähigkeit und Regelreferenz aus dem
  Positionssnapshot – auch wenn Stammdaten inzwischen deaktiviert sind.
- Fehlende oder gelöschte Referenzen führen zu kontrollierter Ablehnung, kein
  stiller Ersatz durch aktuelle Stammdaten.
- **Gate-B-Grenze:** Anzeigenamen von Sender/Werbemittel werden bei vorhandenem
  Datensatz aus der Referenz geladen; dedizierte Namens-Snapshotfelder auf
  Positionsebene folgen in späteren Gates (nicht UX-GATE-C/D vorwegnehmen).

## Durchschnittskalkulation Spot

### Eingaben

- Inventar und Werbemittel,
- Preislistenversion,
- Tagesgruppe,
- mindestens ein Zeitfenster,
- Gesamtanzahl Spots,
- tatsächliche Länge oder Komponenten; die Länge ist je Sender-/Kombinationsposition frei editierbar (`SPT-015`),
- konfigurierter Werbemittelaufschlag.

Ein Zeitfenster `10–23 Uhr` umfasst die Preisstunden 10 bis einschließlich 22.
Mehrere Zeitfenster werden für den Durchschnitt zu einer eindeutigen Stundenmenge
vereinigt. Überlappende Stunden dürfen nur einmal zählen (`SPT-003`). Das ist keine
gruppierte Zeitschiene: klassische Spotplanung bleibt stundenweise (`SPT-016`).

```text
Stunden = eindeutige Vereinigung aller gewählten Stunden
Ø-Sekundenpreis = Σ Sekundenpreis(Stunde) ÷ Anzahl(Stunden)
Längenfaktor = Spotlängenindex ÷ 100
Aufschlagsfaktor = 1 + Aufschlag

Spotpreis = Ø-Sekundenpreis × tatsächliche Gesamtlänge × Längenfaktor × Aufschlagsfaktor
Positionsbrutto = Spotpreis × Spotanzahl
```

Der Mittelwert ist immer gleichgewichtet. Eine gewünschte Verteilungsgewichtung
wird ausschließlich über den Planer abgebildet (`SPT-004`).

Die tatsächliche Spotlänge ist je Position sichtbar und geht direkt in
`Spotpreis` und `Positionsbrutto` ein. Standardlängen sind nur Vorbelegungen.

Eine Kalkulation summiert live alle Positionsbrutto- und Nettoergebnisse
(`CAL-005`). Beispiel: 10 Spot-Classic-Positionen Radio Hamburg und 5 Spot-Classic-
Positionen ROCK ANTENNE Hamburg mit jeweils eigenen Längen, Mengen, Preisstunden
und Rabatten in derselben Kalkulation (`CAL-001`).

## Kalenderplaner Spot

Für jede belegte Zelle aus Datum und Stunde:

```text
Zeilenbrutto = Anzahl × Sekundenpreis(Datum, Stunde)
               × tatsächliche Gesamtlänge
               × Längenfaktor
               × Aufschlagsfaktor

Positionsbrutto = Σ Zeilenbrutto
```

Der Wochentag wird aus dem echten Datum bestimmt. Mo–Fr, Samstag und Sonntag
verwenden ihre jeweiligen Basispreise. Leere Zellen entsprechen null; negative
oder nicht ganzzahlige Spotmengen sind ungültig.

## Komponenten und Gesamtlänge

Hauptspot, Allonge, Abbinder, Reminder und weitere Komponenten bleiben einzeln
sichtbar. Abhängig von der versionierten Kombination gilt eine von zwei Strategien:

1. **Gemeinsame Gesamtlänge:** Summe aller Komponenten bestimmt Index und Preis.
2. **Einzelberechnung:** Jede Komponente wird mit eigener Länge berechnet; Ergebnisse werden addiert.

Tandem/Reminder und Tridem verwenden verbindlich die gemeinsame Gesamtlänge
(`SPT-012`). Die Allonge-Strategie ist administrierbar (`SPT-014`).

## SWF und Trailer

SWF verwendet Stunden-/Sekundenpreise, tatsächliche Länge und Aufschlag, aber
keinen Spotlängenindex.

```text
Einzelpreis = Sekundenpreis × tatsächliche Länge × (1 + Aufschlag)
Positionsbrutto = Anzahl × Einzelpreis
```

Für Durchschnitt und Planer gelten dieselben Zeit- und Verteilungsprinzipien wie
bei Spots, soweit nicht `SWF-008` eingreift: Trailer, Allongen und weitere SWF aus
der Trailerkalkulation dürfen konfigurierte Standardlängen und gruppierte
Zeitschienen verwenden. Premium-, Tages-, Abend- und Wochenend-Trailer werden aus
Tagesgruppe und Uhrzeit abgeleitet und nicht als eigene Werbemittel gespeichert.

## Online Audio und Podcast

```text
Finaler TKP = Basis-TKP + Σ Targeting-Aufschläge
Invest = Impressions ÷ 1.000 × finaler TKP
```

Regeln:

- Die Mindestpreisprüfung verwendet ausschließlich den Basis-TKP (`OA-001`).
- Unter Mindest-TKP darf gespeichert, aber ohne Begründung und Sonderfreigabe nicht übergeben werden.
- Bei Festpreis: `effektiver TKP = Festpreis ÷ Impressions × 1.000`.
- Bei null Impressions ist der effektive TKP nicht berechenbar; Übergabe ist zu blockieren.
- Die Summe aller Plattform-/Pre-Stream-/In-Stream-Mengen muss der Gesamtmenge entsprechen.
- Targeting-Aufschläge sind additiv, nicht multiplikativ.
- Der zusätzliche Adserver-TKP für Spotify/Deezer/YouTube ist ein Verkaufsbestandteil.

## Social Media und Influencer

```text
Elementgesamt = Menge × Medien-Einzelpreis
Paket-Medienpreis = Σ Elementgesamt
Paket-Booster = Σ Boosterbudget
Positionsbrutto = Paket-Medienpreis + Paket-Booster
```

Influencer werden als getrennte Unterpositionen mit eigener Menge und eigenem Preis
gerechnet. Medienpreis und Boosterbudget bleiben getrennte Preisbestandteile.
Initial ist die gesamte Social-Media-Position nicht rabattierbar und nicht AE-fähig.

## Produktion und Sonstiges

```text
Zeilengesamt = Menge × Einzelpreis
```

Die Menge ist optional und hat Standardwert 0. Reguläre Einzelpreise kommen aus
der inventar- und typbezogenen Produktionspreisliste. `Sonstiges` kann frei
bepreist werden. Zusatzzeilen sind initial nicht rabattierbar und nicht AE-fähig.

## Rabattreihenfolge

Seien `r_pos` und `r_auftrag` Dezimalwerte zwischen 0 und 1:

```text
Nettofaktor = (1 - r_pos) × (1 - r_auftrag)
Effektiver Rabatt = 1 - Nettofaktor
Rabattierter Betrag = rabattfähiges Brutto × Nettofaktor
```

Beispiel: 10 Prozent Position und 10 Prozent Auftrag:

```text
Nettofaktor = 0,90 × 0,90 = 0,81
Effektiver Rabatt = 1 - 0,81 = 0,19 = 19 Prozent
```

Die persönliche Rabattgrenze wird je Position gegen den effektiven Rabatt geprüft.
Nicht rabattierbare Preiszeilen werden unverändert ergänzt.

## AE

Der Standardwert beträgt 15 Prozent. Die Wertauflösung lautet:

```text
Positionswert > Kalkulationswert > Agenturstandard
```

```text
AE-Betrag = rabattierter AE-fähiger Betrag × AE-Satz
N/N nach AE = rabattierter Betrag - AE-Betrag
```

Nicht AE-fähige Preiszeilen werden nicht in die AE-Basis einbezogen.

## Festpreis

Der Festpreis ist der vereinbarte rabattierte N/N-Endpreis der Medienleistung,
nicht ein neuer Listenpreis.

```text
Effektiver Nettofaktor = Festpreis ÷ zugehöriges Mediabrutto
Effektiver Rabatt = 1 - effektiver Nettofaktor
```

Nicht rabattierbare Zusatzzeilen bleiben separat. Bei Mediabrutto null ist die
Rückrechnung nicht zulässig und erfordert kaufmännische Prüfung.

## Payfaktor

```text
Payfaktor = N/N-Invest ÷ Mediabrutto × 100
```

Auszuweisen sind:

- Payfaktor ohne Online Audio,
- Payfaktor Online Audio,
- Gesamt-Payfaktor.

Bei Mediabrutto null wird kein künstlicher Wert ausgegeben, sondern
`nicht berechenbar` (`COM-011`).

## Budgetplanung

Der Vorschlag ist nicht autoritativ. Autoritative Speicherung bleibt die
Kalkulation nach expliziter Übernahme (`BUD-008`).

Zwei Planungswege:

- **Selbst planen:** optionales Zielbudget N/N nur als Vergleich mit dem
  aktuellen N/N-Invest.
- **Mit Budget planen:** Zielbudget N/N, Sender/Kombis, Preisstunden, Längen
  und Konditionen zuerst; danach ein **neuer** Vorschlag. Kein bestehendes
  Senderverhältnis (`BUD-005`).

Eingaben für den Vorschlag:

- optionales numerisches Zielbudget N/N in EUR (`BUD-001`, `BUD-002`),
- ausgewählte Sender/Kombis, Preisstunden, Spotlängen, Positionsrabatte, AE und
  Auftragsrabatt (`BUD-003`),
- Verteilungslogik (`BUD-006`): Budget je Sender gleich verteilen oder
  Spotanzahl innerhalb der gewählten Preisstunden maximieren.

Regeln:

- Berechnung deterministisch und nachvollziehbar; Ergebnis immer editierbar (`BUD-004`).
- Spotmengen ganzzahlig; Rest unter Zielbudget oder Überschreitung durch
  Ganzzahligkeit transparent ausweisen (`BUD-007`).
- Keine Reichweiten-, Leistungs- oder KI-Optimierung (`BUD-009`).

Die Positionspreise folgen unverändert der verbindlichen Rechenreihenfolge unten.
Der Vorschlag ändert nur Mengen nach Übernahme, nicht die Formel selbst.

## Verbindliche Rechenreihenfolge

1. Grund-/Listenpreis und Menge bestimmen.
2. Länge, Index und Werbemittelaufschläge anwenden.
3. Rabattierbare und nicht rabattierbare Bestandteile trennen.
4. Positionsrabatt anwenden.
5. Auftragsrabatt auf den bereits rabattierten Betrag anwenden.
6. AE auf die rabattierte, AE-fähige Basis anwenden.
7. N/N-Invest, Zusatzzeilen und Payfaktoren bestimmen.
8. Position und Auftrag auf zwei Cent runden.

Jede Änderung dieser Reihenfolge ist eine Fachänderung und benötigt angepasste
Anforderungen sowie Regressionstests.

