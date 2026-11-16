# Preisanlieferung XLSX v1

**Contract-Version:** `1` (`PriceListDeliveryWorkbookContract::VERSION`)  
**Referenzdatei:** `Template_Preisanlieferung.xlsx` (strukturell; Beispielwerte sind keine Produktivpreise)  
**Format-Label:** `preisanlieferung_xlsx_v1`

## Zweck

Bevorzugtes Anlieferungsformat für klassische Spot-Stundenpreislisten.
Gemeinsamer Vertrag für Template-Download und Import-Parser.

## Aufbau

- Ein Arbeitsblatt je Inventar; Blattname = kanonischer Inventarname
- Nur `.xlsx`
- Zeile 1: Überschriften
- Zeilen 2–25: Stunden `00:00` … `23:00` (Stundenstart)
- Spalten:

| Spalte | Inhalt |
|---|---|
| A | Uhrzeit |
| B | Mo - Fr / €/1 Sek |
| C | Samstag / €/1 Sek |
| D | Sonntag / €/1 Sek |

Überschriften B–D enthalten einen Zeilenumbruch zwischen Tagesgruppe und `€/1 Sek`.
Keine 30-Sekunden-Spalten. Keine Jahresangabe in der Datei.

## Inventarkreis

Aktive Inventare mit Codes aus `CLASSIC_HOUR_INVENTORY_CODES` (acht klassische
Stundenpreislisten). Online Audio, Podcast und Events-Kombis gehören nicht dazu.

## Fachregeln

- Preislistenjahr ausschließlich im Importdialog
- Leere Preiszellen ≠ 0 (PO-PRI-HOURS-1, Sparse erlaubt)
- Mapping auf Basisgruppen `mo_fr` / `sa` / `so`
- Mo–Sa / Mo–So werden abgeleitet, nicht importiert
- Import erzeugt nur Drafts, keine Auto-Aktivierung
- Unbekannte Blattnamen → Fehler, keine Inventarneuanlage
- Aliasauflösung (`radio ffn`, `BOLLERWAGEN`) bleibt erhalten
- MORE-Spotkalkulation-Adapter und kanonische Flachdatei bleiben kompatibel
- Spotlängen / SWF nutzen weiterhin die bestehende Kalkulationsengine (`SpotLengthIndex`)

## Soll-Abdeckung

Pro Inventar maximal 72 Basiszellen (24 × 3). Die Vorschau zeigt Ist/Soll/Fehlend.
