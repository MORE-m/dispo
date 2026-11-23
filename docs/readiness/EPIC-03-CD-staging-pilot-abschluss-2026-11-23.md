# EPIC-03-C/D – Staging-Pilot Radio Hamburg – Abschluss

**Datum:** 2026-11-23
**Release:** `main` und Staging `fdf1d8f2601e99c37d09132a7889f77d9d3ffe72`
**Abnahme:** **PASS bis einschließlich Dispo-Entwurf**
**Nicht abgenommen:** Statusübergänge nach dem Entwurfsstatus
**Nächster Rollout:** **STOP**

Die lokale Ausführungsnotiz vom 22.11. bleibt unversioniert liegen und wurde nicht überschrieben. Dieser Abschluss enthält die fachlichen Ergebnisse, keine Sicherungen, Pfade oder Zugangsdaten.

## EPIC-03-C – Import und Aktivierung

Einmaliger Import `inv_radio_hamburg`:

| Ergebnis | Anzahl |
|---|---|
| Creates | 61 |
| Unchanged | 9 |
| freigegebenes Update | 1 |
| Konflikte | 0 |

Das eine Update setzt den Standard der Kategorie `special_advertising_formats` von leer auf `average`.

Idempotenz-Recheck danach: 0 Creates, 0 Updates, 71 Unchanged, 0 Konflikte.

Bestand nach der Aktivierung:

- 27 Werbemittel und 27 Regeln.
- Zwei aktive Spotpreislisten 2026 und 2027, jeweils Revision 1, jeweils 72 Items.
- Aktiver Produktionspreis 2026: Spotproduktion 600,00 €, nicht rabattierbar, nicht AE-fähig.
- Produktionspreis 2027 fehlt. Das ist eine bekannte Einschränkung und kein Importfehler.

## EPIC-03-D – Browser-Smoke

Testkunde und Kampagnen sind als Test gekennzeichnet. Es gab keine Einreichung und keine Freigabe. Die drei Vorgänge bleiben bestehen; es gibt keine automatische Bereinigung.

| Vorgang | Nummer | Ergebnis |
|---|---|---|
| Kalkulation Preisjahr 2026 | `K-2026-00001` | N/N 17.400,00 € |
| Kalkulation Preisjahr 2027 | `K-2026-00002` | N/N 18.600,00 € |
| Dispo-Entwurf aus der 2026er Kalkulation | `DA-2026-00001-01` | N/N 17.400,00 €, Status Entwurf |

Beides nach Reload unverändert:

- 2026 pinnt Spotpreisliste 1, Revision 1, aktiv, Jahr 2026. Stundenpreis 56,0000 € ergibt 16.800,00 € Medien-N/N; Produktion 600,00 € aus Produktionsliste 1.
- 2027 pinnt Spotpreisliste 2, Revision 1, aktiv, Jahr 2027. Stundenpreis 62,0000 € ergibt 18.600,00 €. Die 2026er Liste wurde nicht verwendet.
- Der Fail-Closed-Test für eine Produktionszeile 2027 blockiert das Speichern und nennt den fehlenden Produktionspreis. Die Zeile wurde vor dem Speichern entfernt.
- Stammdaten und Preislisten zeigten danach keine unerwartete Änderung: weiterhin genau die beiden aktiven RH-Spotlisten und genau die eine Produktionsliste 2026.

`K-2026-00002` ist die zweite Dokumentnummer im Kalenderjahr 2026, gerechnet mit Preisjahr 2027.

## Bewertung

Der Pilot ist für den Weg Import, Aktivierung, Kalkulation und Dispo-Entwurf bestanden. Einreichung, Freigabe, Disponieren und spätere Dispo-Status sind nicht Teil dieser Abnahme.

Die Readiness der übrigen sieben Inventare steht in [`EPIC-03-E-next-spot-inventories-readiness-2026-11-23.md`](EPIC-03-E-next-spot-inventories-readiness-2026-11-23.md). Daraus folgt kein GO.
