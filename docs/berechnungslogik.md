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

Basispreise existieren je Uhrstunde und Basis-Tagesgruppe (`Mo–Fr`, `Sa`, `So`).
Laut **PO-PRI-HOURS-1** dürfen die buchbaren Stunden je Tagesgruppe
unabhängig sein. Fehlt die Basiszeile für `(Stunde, Tagesgruppe)`, ist diese
Kombination **nicht buchbar** – nicht Preis 0 und nicht still aus einer anderen
Gruppe übernehmen.

```text
P(Mo–Sa, h) = (5 × P(Mo–Fr, h) + P(Sa, h)) ÷ 6
P(Mo–So, h) = (5 × P(Mo–Fr, h) + P(Sa, h) + P(So, h)) ÷ 7
```

Abhängigkeiten der Ableitung:

- `Mo–Fr` / `Sa` / `So`: nur die jeweilige Basisgruppe
- `Mo–Sa`: benötigt `Mo–Fr` und `Sa` derselben Stunde
- `Mo–So`: benötigt `Mo–Fr`, `Sa` und `So` derselben Stunde

Fehlt eine erforderliche Basisgruppe, ist die Ziel-Tagesgruppe nicht buchbar
(fail-closed). `Mo–Sa` und `Mo–So` sind Ableitungen und keine unabhängig
pflegbaren Preise.

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

## Preis- und Regel-Snapshot (`PRI-004`, `VER-002`, `PO-PRI-YEAR-1`)

- Neue Positionen und echte Inventar-/Werbemittelwechsel: aktive Preisliste des
  **gewählten Preisjahres** (Default: aktuelles Kalenderjahr `Europe/Berlin`).
  Das Folgejahr ist nur wählbar, wenn für das Inventar eine Active-Liste existiert.
  Fehlt die Liste des gewählten Jahres, gibt es keinen stillen Fallback und keinen
  Preis 0.
- Bewusster Jahrwechsel an bestehender Position: Rebind erst beim Speichern auf die
  dann erwartete Active-Liste (`expected_price_list_id`); Active-Drift → HTTP 409.
- Unveränderte bestehende Position (kein Jahrwechsel): gespeicherte Preisliste
  (auch archiviert), Stundenpreise, Aufschlag, Rabatt-/AE-Fähigkeit und Regelreferenz
  aus dem Positionssnapshot – auch wenn eine neuere Active-Revision desselben Jahres
  existiert. Zusätzliche Stunden derselben Position lesen aus derselben gepinnten Version.
- Reiner Methodenwechsel bei unverändertem Inventar und Preisjahr (`BL-P4-02a`):
  neuer Methoden-Freeze, aber **kein** Live-Rebind der Preisliste; Pin und Version
  bleiben. Live-Bind nur bei neuer Position, Inventarwechsel oder explizitem Jahrwechsel.
- Fehlende oder gelöschte Referenzen führen zu kontrollierter Ablehnung, kein
  stiller Ersatz durch aktuelle Stammdaten.
- Budgetplanung verwendet denselben Jahresvertrag.
- **Gate-B-Grenze:** Anzeigenamen von Sender/Werbemittel werden bei vorhandenem
  Datensatz aus der Referenz geladen; dedizierte Namens-Snapshotfelder auf
  Positionsebene folgen in späteren Gates (nicht UX-GATE-C/D vorwegnehmen).

## Durchschnittskalkulation Spot

### Eingaben

- Inventar und Werbemittel,
- Preislistenversion,
- mindestens ein Preiszeitraum (Beginn, exklusives Ende, Tagesgruppe, Spotanzahl),
- tatsächliche Länge oder Komponenten; die Länge ist je Sender-/Kombinationsposition frei editierbar (`SPT-015`),
- konfigurierter Werbemittelaufschlag.

Das Ende eines Zeitraums ist **exklusiv**. `08:00–18:00` speichert
`start_hour = 8` und `end_hour_exclusive = 18` und umfasst die Preisstunden
8 bis einschließlich 17, angezeigt als `08:00 bis 17:59 Uhr`. Stunde 18 gehört
nicht zum Zeitraum.

Die Spotanzahl gilt für den gesamten Zeitraum, nicht je Stunde.
`Gesamtspotzahl = Summe der Spots aller Preiszeiträume` einer Position.
Es gibt keine zweite, unabhängig editierbare Spotanzahl.

Mehrere Zeiträume derselben Tagesgruppe dürfen sich nicht überschneiden.
Direkt angrenzende Zeiträume (`08:00–12:00` und `12:00–18:00`) sind erlaubt.
Gleiche Uhrzeiten in unterschiedlichen Tagesgruppen sind erlaubt.

Jeder Zeitraum wird **separat** kalkuliert und anschließend addiert.
Ein ungewichteter Durchschnitt über alle Zeiträume, danach multipliziert mit
der Gesamtspotzahl, ist unzulässig.

```text
Stunden(Zeitraum) = start_hour … end_hour_exclusive − 1
Ø-Sekundenpreis(Zeitraum) = Σ Sekundenpreis(Stunde) ÷ Anzahl(Stunden des Zeitraums)
Längenfaktor = Spotlängenindex ÷ 100
Aufschlagsfaktor = 1 + Aufschlag

Zeitraumssumme = Ø-Sekundenpreis(Zeitraum)
                 × tatsächliche Gesamtlänge
                 × Längenfaktor
                 × Aufschlagsfaktor
                 × Spots(Zeitraum)

Brutto Werbeelement = Σ Zeitraumssummen
Gesamtspots = Σ Spots(Zeitraum)
```

Fehlende Preislistenwerte dürfen nicht ignoriert werden. Die Meldung nennt
Sender, Tagesgruppe und betroffene Stunden.

**Bestandskalkulationen:** Genau eine gespeicherte Preisstunde wird zu
`Stunde–Stunde+1` mit der bisherigen Gesamtspotzahl migriert; das Ergebnis
bleibt identisch. Mehrere Preisstunden mit nur einer gemeinsamen Gesamtspotzahl
werden nicht automatisch verteilt. Sie bleiben mit der bisherigen
Durchschnittslogik lesbar, bis die bisherigen Gesamtspots exakt auf Zeiträume
verteilt sind.

Die tatsächliche Spotlänge ist je Position sichtbar und geht direkt in
`Spotpreis` und `Positionsbrutto` ein. Standardlängen sind nur Vorbelegungen.

Eine Kalkulation summiert live alle Positionsbrutto- und Nettoergebnisse
(`CAL-005`). Beispiel: 10 Spot-Classic-Positionen Radio Hamburg und 5 Spot-Classic-
Positionen ROCK ANTENNE Hamburg mit jeweils eigenen Längen, Mengen, Preisstunden
und Rabatten in derselben Kalkulation (`CAL-001`).

## Kalenderplaner Spot

**Umsetzung:** Rechenweg und Formeln unverändert; fachlich umgesetzt in **`BL-P4-02b`**
(Feature-PR offen, Abnahme **AT-02**, `SPT-005`–`SPT-007` plus Anzeige-Teil **`SPT-008`**).
Durchschnitts-`time_ranges` und Kalender-`planner_entries` schließen sich gegenseitig aus.

**Jahresvertrag:** Eine Kalenderposition ist an genau ein Preisjahr gebunden (gepinnte
Preisliste). Jedes belegte Kalenderdatum muss in dieses Jahr fallen. Planung über
Jahresgrenzen hinweg erfordert **getrennte Positionen** (ggf. mit explizitem
`price_year` je Position). Kein Auto-Rebind der Preisliste anhand der Datumszeilen.

Komponenten (AT-04), Festpreis und Dispo-**Export** der Spot-Verteilung folgen in
späteren Teilblöcken.

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
oder nicht ganzzahlige Spotmengen sind ungültig. Abweichende Kalenderjahre in
derselben Position werden fail-closed abgewiesen (siehe Jahresvertrag oben).

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

Rabatte einer Ebene werden **nacheinander** angewendet, nicht addiert.
Positionsrabatte kommen vor Auftragsrabatten.

```text
nach Rabatt n = Betrag vor Rabatt n × (1 − r_n)
Effektiver Nachlass = 1 − Produkt aller (1 − r)
```

Beispiel 1.000,00 €, dann 10 % Mengenrabatt, dann 5 % Sonderrabatt:

```text
1.000,00 × 0,90 = 900,00
900,00 × 0,95 = 855,00
Effektiver Nachlass = 14,5 %
```

Ein anschließender Auftragsrabatt von 10 % ergibt 769,50 €.

Die persönliche Rabattgrenze wird je Position gegen den **kumulierten
effektiven Nachlass** aller Positions- und Auftragsrabatte geprüft. Mehrere
kleine Rabatte dürfen die Grenze nicht umgehen. AE zählt nicht zur Rabattgrenze.
Nicht rabattierbare Beträge bleiben von Rabatten ausgenommen.

## AE

AE ist eine Auftragskondition: Checkbox `15 % AE berücksichtigen`,
standardmäßig deaktiviert. Aktiviert = 15 %, deaktiviert = kein AE-Abzug.
Ein frei editierbarer AE-Prozentsatz gehört nicht zu diesem Slice.

```text
AE-Betrag = rabattierter AE-fähiger Betrag × 15 %
N/N nach AE = rabattierter Betrag − AE-Betrag
```

AE folgt nach allen Positions- und Auftragsrabatten. Nicht AE-fähige Beträge
bleiben unverändert; die Berechnungsbasis ist der AE-fähige Restbetrag.

Bestehende Kalkulationen mit explizitem AE-Wert größer 0 behalten ihre
bisherige Wirkung. Neue Kalkulationen starten mit deaktiviertem AE.

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

Der Budgetvorschlag ist **preis- und verteilungsbasiert, nicht reichweitenoptimiert**
(`BUD-009`). Er ist nicht autoritativ. Autoritative Speicherung bleibt die
Kalkulation nach expliziter Übernahme (`BUD-008`).

### Planungsweg „Mit Budget planen“

Vor der Übernahme: vier Schritte im Wizard (Grunddaten → Planungsrahmen →
Konditionen → Budgetvorschlag). Nach der Übernahme wechselt die Oberfläche in die
normale Bearbeitungsdarstellung (Grunddaten → Werbeelemente → Konditionen →
Zusammenfassung), ohne `planning_mode` zu ändern.

### Zustandsmodell (`budget_proposal_status`)

| Status    | Bedeutung |
| --------- | --------- |
| `draft`   | Planungsrahmen vorhanden, noch kein Vorschlag berechnet |
| `current` | Aktueller Vorschlag berechnet, nicht übernommen |
| `stale`   | Vorschlag vorhanden, Eingaben danach geändert |
| `applied` | Vorschlag in Kalkulationspositionen übernommen |
| `manual`  | Übernommene Planung manuell angepasst |

Der Status ist serverseitig persistent. Nach Übernahme werden Positionen und
Preiszeiträume gespeichert; beim erneuten Laden erscheint die Detailplanung,
nicht der Budget-Entwurf.

### Budget-Werbeelemente (`budget_elements[]`)

Je Element:

- `client_id` (stabile ID für Konditionen)
- `inventory_id` (genau ein Sender)
- `spot_length_seconds`
- `distribution_ranges[]` (Beginn, exklusives Ende, Tagesgruppe)
- `position_discounts[]`

Legacy-Payloads mit `budget_wish_inventory_ids`, `budget_spot_length_seconds` und
gemeinsamen `budget_distribution_ranges` werden beim Lesen normalisiert.

Eingaben für den Proposal-Request:

- `target_budget_nn`
- `budget_elements[]`
- `order_discounts[]`
- `ae_enabled`

Nicht Teil der Eingabe: `total_spot_count`, `spot_count`, bestehende `positions`.

### Strategie `equal_spot_count`

- Alle Budget-Werbeelemente erhalten **dieselbe** Gesamtspotanzahl `S`.
- Jedes Element besitzt eigene erlaubte Stunden-Buckets; Spots werden nur dort verteilt.
- Optimierung in vollständigen Spotpaketen: `+1 Spot je Budget-Werbeelement`.
- Binäre Suche auf maximales `S` mit `N/N(S) ≤ Zielbudget`.
- Keine Bevorzugung günstiger Sender oder Stunden.

### Verteilung `even_distribution` (Algorithmusversion `1.0.0`)

- Buckets: `Sender × Tagesgruppe × einzelne Uhrstunde`.
- Stabil sortiert nach Tagesgruppe und Uhrstunde.
- Gleichmäßige Verteilung der Spots je Sender über alle Buckets (Differenz ≤ 1).
- Weniger Spots als Buckets: zeitliche Streuung über den gesamten Zeitraum.

### Berechnung

Vollständige Serverkette über `CalculationEngine`: Stundenpreise → Spotlänge →
Zeitraumssummen → Positionsrabatte → Auftragssumme → Auftragsrabatte → AE 15 % → N/N.

### Übernahme

- Einstündige `calculation_position_time_ranges` je belegter Uhrstunde.
- Keine proportionale Skalierung im Pfad `equal_spot_count`.
- Status `applied` nach Übernahme; `manual` nach manuellen Änderungen.
- Verknüpfung über `appliedBudgetProposal` beim Laden; unübernommene Vorschläge über `latestBudgetProposal`.

Legacy-Strategien `equal_budget` und `maximize_spots` bleiben für Bestandsvorschläge lesbar.

## Verbindliche Rechenreihenfolge

1. Zeitraumssummen eines Werbeelements berechnen.
2. Zeitraumssummen zum Brutto des Werbeelements addieren.
3. Länge, Index und Werbemittelaufschläge sind Teil jeder Zeitraumssumme.
4. Rabattierbare und nicht rabattierbare Bestandteile trennen.
5. Rabatte des Werbeelements nacheinander anwenden.
6. Rabattierte Werbeelemente zur Auftragssumme addieren.
7. Auftragsrabatte nacheinander auf den verbleibenden Betrag anwenden.
8. AE auf den AE-fähigen verbleibenden Betrag anwenden.
9. N/N-Invest, Zusatzzeilen und Payfaktoren bestimmen.
10. Position und Auftrag auf zwei Cent runden.

Jede Änderung dieser Reihenfolge ist eine Fachänderung und benötigt angepasste
Anforderungen sowie Regressionstests.

