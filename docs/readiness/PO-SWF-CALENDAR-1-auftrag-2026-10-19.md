# PO-SWF-CALENDAR-1 – Auftrag / Abnahme

Stand: 19. Oktober 2026 · Feature-Branch `feat/po-swf-calendar-1`

## Ziel

Calendar für die bereits operativen SWF-Profile freischalten:

| Profil | Medium | Average | Calendar | Settlement | Index |
|---|---|---|---|---|---|
| `swf_trailer` | Trailer | OP | **OP** | OUT | 100 |
| `swf_allonge` | Allonge | OP | **OP** | OUT | 100 |
| `swf_promo` | Promo/Moderation | OP | **OP** | OUT | 100 |
| `swf_contest` | Contest/Pay-Off | OP | **OP** | OUT | 100 |

SWF-001 Calendar-Teil für diese vier Profile erfüllt. Keine weiteren Medien.

## Architektur

- Generische Capability `usesLengthWithoutIndex` (Alias `usesAverageLengthWithoutIndex`)
- Registry: `calendar = released v1` je SWF-Profil
- Bestehender Calendar-Rechenkern (Preiszellen × Länge × Anzahl × surcharge), Index fest 100
- Kein Medium-Hardcoding; kein neuer Engine-Typ
- Settlement / Produktion / Komponenten unverändert OUT
- Allonge Confirm bleibt Pflicht (auch für Calendar)

## Tests

- Unit: `SwfCalendarCalculationTest` (Kern)
- Feature: `SwfCalendarAcceptanceTest`, `SwfCalendarContractTest`
- MySQL: `SwfCalendarMysqlTest` (Job `mysql`)
- E2E: eine Familien-Suite Promo Calendar (`playwright.po-swf-calendar-1`)

## Bewusst nicht

- Settlement für SWF (`PO-SWF-SETTLEMENT-1`)
- Method-Key `fixed_price`
- Event-Tipp / CityLife / weitere SWF-Medien
- TKP / Free Position / Social
- Deploy / Merge
