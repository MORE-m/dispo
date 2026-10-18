# Cursor-Auftrag: PO-SPOT-SINGLE-1 – Single-Spot

IDs: `PO-SPOT-SINGLE-1`, `spot_single`, `SPT-010`, `SPT-011`, `MAT-003`, `PO-MAT-CORE-CATALOG-1`

## Ziel

`spot_single` als operativ buchbares Spotmedium über den bestehenden `spot_classic`-Pfad
mit Fachaufschlag **+50 %**. Keine neue Engine.

## Fachlich

- Aufschlag: **+50 %** für alle aktuell zulässigen Single-Inventarregeln
- Erlaubt: Radio Hamburg, ROCK ANTENNE Hamburg, OLDIE ANTENNE Hamburg, CARAVAN.fm, MORE Hamburg-Kombi
- MAT-003 ausgeschlossen (keine neuen Regeln): MORE Hamburg-Kombi+, RADIO BOLLERWAGEN DAB+ Hamburg, ffn Hamburg Plus
- Average + Calendar; Spotlängenindex; Rabatt/AE/Festpreis-Settlement unverändert
- SPT-010: Hinweis bei >45 s, keine harte Sperre (nur Medium-Code `spot_single`)
- **Produktion = OUT** für Single (Code-Gate `mediumCode === spot_classic`; schließt auch Erst/Letzt-Leak)

## Technisch

- `kind = spot_classic`, bestehendes EngineProfile
- Daten-vor-kind / fail-closed / Migration wie PO-SPOT-SURCHARGE-1
- Matrix-Create: Initialwert 50; positive Admin-Abweichungen bei Reimport nicht überschreiben
- Allonge-Komponente: erbt denselben Komponentenvertrag wie Classic (kein Sonderverhalten)

## Out

Promo, Contest, Event-Tipp, Abbinder, Showsponsoring-Single, Produktionsfreigabe Single, Deploy, Merge
