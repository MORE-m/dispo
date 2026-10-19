# PO-SWF-CONTEST-1 – Gewinnspiel/Pay-Off × Average

Stand: 19. Oktober 2026 · Feature-Branch `feat/po-swf-contest-1` · **kein** Deploy

## Ziel

Katalogmedium `contest_payoff` als operativ buchbare Sonderwerbeform **Gewinnspiel/Pay-Off**
(fachlich = Gewinnspieldurchgang laut SWF-004/005):

- eigenes Engine-Profil `swf_contest` / Kind `swf_contest` (nicht Trailer/Allonge/Promo)
- nur **Average** (Calendar / Settlement / Produktion / Komponenten OUT)
- Referenzlänge **25 s**, Initialaufschlag **+50 %** (konfigurierbar, keine Hardcode-Formel in der Engine)
- vier Matrix-Inventare: Radio Hamburg, ROCK ANTENNE Hamburg, OLDIE ANTENNE Hamburg, CARAVAN.fm
- bestehender SWF-Average-Rechenkern ohne Spotlängenindex
- Calc-/Dispo-Snapshot; fail-closed gegen ungültige Länge/Aufschlag
- **kein** Confirm-Feld (`swf_contest_confirmed_at`)

## Architektur

`contest_payoff` → Kind/Profil `swf_contest` → InventoryMediumRule (Länge/Aufschlag)
→ SWF-Average-Kern → Calculation Snapshot → Dispo Snapshot

## Migration

`2026_10_19_160000_po_swf_contest_1_catalog.php`

- `up`: Kind, Override Average/`swf_contest`, bestehende Regeln → 25 / 50
- `down`: fail-closed bei Contest-Positionen; Länge/Aufschlag bleiben

## Tests

- Feature: Katalog, Acceptance, Migration
- Unit: Formel
- MySQL: `SwfContestMysqlTest` + `MysqlTestDatabaseGuard`
- E2E isoliert: Port **8071**, `playwright.po-swf-contest-1.config.ts`

## Bewusst nicht

Event-Tipp, weitere SWF, Calendar/Festpreis/Produktion Contest, Confirm, Deploy, Merge.
`BL-P5-01` bleibt teilweise.
