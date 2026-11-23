# EPIC-03-E – Readiness der sieben verbleibenden Spot-Inventare

**Datum:** 2026-11-23
**Art:** reine Leseanalyse, kein Export, kein Import
**Empfehlung:** nächstes Inventar `inv_rock_antenne_hamburg`
**Rollout:** **STOP**. Ein späteres Inventar durchläuft Quellprüfung, Export, Integritätsprüfung, Staging-Dry-Run und erst danach die PO-Entscheidung. Apply und Aktivierung gehören nicht zu dieser Reihenfolge.

## Datenstand

Die Preis- und Produktionsfakten stammen aus der Live-Lesung vom 20.11.2026. Der Regel- und Medienvergleich stammt aus dem Katalogstand vom 15.11.2026 und passt für Radio Hamburg zum Export vom 22.11. (weiterhin 27 Regeln). Am 23.11. konnte die lokale Quelldatenbank nicht erneut gelesen werden, weil MariaDB nicht läuft.

Deshalb sind die Zahlen Schätzungen, kein aktueller Quellstand und kein Apply-Soll.

Nicht im Spot-Scope und ohne Spotlisten: OA-Kombi, Podcast-Kombi und die vier Event-Kombis.

## Vergleich

Staging enthält nach dem RH-Piloten Organisation, fünf Methoden, zwei Kategorien, 27 Werbemittel, 27 RH-Regeln, zwei aktive RH-Spotlisten und eine aktive RH-Produktion 2026. Geteilte Schlüssel mit gleichem Inhalt werden beim nächsten Import `unchanged`. Abweichender Inhalt auf demselben Schlüssel wird ein Konflikt. Eine bereits aktive Preisliste mit gleichem Inhalt bleibt unverändert; abweichender Inhalt derselben Revision erzeugt eine neue Draft-Revision.

| Inventar | Typ | Regeln | Neue Medien ggü. RH | Spot 2026/2027 | Produktion 2026 | Bekannte Abweichung |
|---|---|---|---|---|---|---|
| `inv_rock_antenne_hamburg` | Sender | 27 | 2 | 72 / 72 | 400 € laut PO-Datenlieferung | zwei neue Medien |
| `inv_80er_90er_oldie_antenne_hamburg` | Sender | 27 | 1 | 72 / 72 | 400 € laut PO-Datenlieferung | ein neues Medium |
| `inv_caravan_fm` | Sender | 25 | 0 | 72 / 72 | 400 € laut PO-Datenlieferung | Buchungskennzeichen `UC` |
| `inv_ffn_hamburg_plus` | Sender | 5 | 0 | 42 / 42 | 400 € laut PO-Datenlieferung | kein SWF, Teilraster |
| `inv_radio_bollerwagen_dab_plus_hamburg` | Sender | 5 | 0 | 57 / 57 | 400 € laut PO-Datenlieferung | kein SWF, Teilraster |
| `inv_more_hamburg_kombi` | Kombi | 7 | 0 | 72 / 72 | 600 €, aktiv, gelesen | Typ Kombi |
| `inv_more_hamburg_kombi_plus` | Kombi | 6 | 0 | 42 / 42 | 600 €, aktiv, gelesen | Typ Kombi, Teilraster |

Alle sieben haben `spot_classic` aktiv und je eine aktive Spotliste 2026 und 2027. 72 Items sind kein Vertragszwang; der Export verlangt eine nicht leere aktive Liste je Jahr. Fehlende Stunden scheitern später in der Kalkulation fail-closed.

Die 400-€-Werte stehen in der PO-Datenlieferung vom Oktober. Die Live-Lesung vom 20.11. hat die Zeilen für diese fünf Sender im erhaltenen Auszug nicht einzeln. Die 600-€-Zeilen für beide Kombis sind aus dieser Lesung bestätigt. Keine Produktionsliste 2027 ist im Auszug sichtbar. Das entspricht der bekannten RH-Einschränkung, ist aber vor dem Apply erneut zu bestätigen.

Die Lesung vom 20.11. zählte zehn aktive Produktionspreislisten. Acht Spot-Inventare erklären acht davon. Die zwei weiteren Zeilen sind im Auszug nicht benannt und vor dem Apply zu klären.

Neue Medien:

- ROCK: `preseller`, `spot_showsponsoring_single`
- OLDIE: `event_tip`

Der Katalog vom 15.11. hat 201 Regeln, die Lesung vom 20.11. 202. Radio Hamburg blieb am 22.11. bei 27. Die eine Differenz liegt nicht am RH-Paket und ist im Dry-Run sichtbar.

Methoden und Kategorien sind nach dem RH-Import auf Staging bereits auf dem Soll. Ein zweites Update der SWF-Kategorie ist nicht zu erwarten. Medium-Methoden für Allonge, Promo und Contest lagen im Katalog, tauchten in den 61 RH-Creates aber nicht auf. Der nächste Dry-Run muss zeigen, ob sie `unchanged` oder `create` sind.

## Erwartete Bewegung

Das sind Schätzungen aus dem Katalog, kein Dry-Run.

| Inventar | Voraussichtlich neu | Unverändert erwartet | Konfliktrisiko |
|---|---|---|---|
| ROCK | 1 Inventar, 2 Medien, 27 Regeln, 2 Spot-Drafts, 1 Produktions-Draft | Organisation, Methoden, Kategorien, 25 Medien | niedrig |
| OLDIE | 1 Inventar, 1 Medium, 27 Regeln, 2 Spot-Drafts, 1 Produktions-Draft | Katalog plus die zwei ROCK-Medien, falls ROCK vorher liegt | niedrig |
| CARAVAN | 1 Inventar, 25 Regeln, 2 Spot-Drafts, 1 Produktions-Draft | alle Medien | niedrig; Buchungskennzeichen anders als RH |
| ffn | 1 Inventar, 5 Regeln, 2 Spot-Drafts, 1 Produktions-Draft | alle fünf Medien | niedrig; Teilraster |
| Bollerwagen | 1 Inventar, 5 Regeln, 2 Spot-Drafts, 1 Produktions-Draft | alle fünf Medien | niedrig; Teilraster |
| Kombi | 1 Inventar, 7 Regeln, 2 Spot-Drafts, 1 Produktions-Draft | alle Medien | niedrig; anderer Inventartyp |
| Kombi+ | 1 Inventar, 6 Regeln, 2 Spot-Drafts, 1 Produktions-Draft | alle Medien | niedrig; Teilraster und Kombi |

## Reihenfolge der Inventare

1. `inv_rock_antenne_hamburg`
2. `inv_80er_90er_oldie_antenne_hamburg`
3. `inv_caravan_fm`
4. `inv_ffn_hamburg_plus`
5. `inv_radio_bollerwagen_dab_plus_hamburg`
6. `inv_more_hamburg_kombi`
7. `inv_more_hamburg_kombi_plus`

ROCK zuerst, weil es denselben bereits abgenommenen Weg wiederholt: Sender, Buchungskennzeichen `Spots (L)`, volles 72er Raster, SWF-Anteil. Gleichzeitig ist es der erste echte Überschneidungstest mit 25 bereits importierten Medien. CARAVAN hätte keine neuen Medien, weicht aber beim Buchungskennzeichen ab. Die Kombis kommen zuletzt, weil sie ein anderer Inventartyp sind und Kombi+ kein volles Raster hat.

## Reihenfolge je Inventar

1. Quellprüfung, ausschließlich lesend.
2. Export.
3. Integritätsprüfung.
4. Staging-Dry-Run.
5. PO-Entscheidung.

Apply, Idempotenz, Aktivierung und weitere Schreibvorgänge folgen nicht aus dieser Reihenfolge. Sie bleiben **STOP**, bis eine spätere Entscheidung sie einzeln freigibt. Kein Sammelpaket.

## Offene Punkte

- Frische Lesung und Dry-Run, sobald die Quelldatenbank läuft.
- Identität der zwei zusätzlichen Produktionspreislisten.
- Bestätigung, dass die fünf 400-€-Preise und das Fehlen von Produktion 2027 noch aktuell sind.
- Ob Teilraster 42 und 57 fachlich so übernommen werden.
- Ob Kombis im nächsten Rollout überhaupt dabei sind.
- Die eine Regel-Differenz 201 gegen 202.
- Aktivierung bleibt ein eigener Schritt nach dem Draft-Import.
- Die drei RH-Testvorgänge bleiben gekennzeichnet bestehen.

## GO / STOP

**STOP** für Export, Import, Aktivierung und jeden weiteren Staging-Schreibvorgang.
