# PO-SWF-PROMO-1 – Promo/Moderation × Average

Stand: 19. Oktober 2026 · Feature-Branch · Draft-PR · **kein** Merge · **kein** Deploy

## Ziel

Katalogmedium `promo_moderation` als operativ buchbare Sonderwerbeform **Promo/Moderation**:

- eigenes Engine-Profil `swf_promo` / Kind `swf_promo` (nicht Trailer/Allonge)
- nur **Average** (Calendar / Settlement / Produktion / Komponenten OUT)
- Referenzlänge **20 s**, Initialaufschlag **+50 %** (konfigurierbar, keine Hardcode-Formel in der Engine)
- vier Matrix-Inventare: Radio Hamburg, ROCK ANTENNE Hamburg, OLDIE ANTENNE Hamburg, CARAVAN.fm
- bestehender SWF-Average-Rechenkern ohne Spotlängenindex
- Calc-/Dispo-Snapshot; fail-closed gegen ungültige Länge/Aufschlag
- **kein** Confirm-Feld (`swf_promo_confirmed_at`)

## Architektur

`promo_moderation` → Kind/Profil `swf_promo` → InventoryMediumRule (Länge/Aufschlag)
→ SWF-Average-Kern → Calculation Snapshot → Dispo Snapshot

## Migration

`2026_10_19_100000_po_swf_promo_1_catalog.php`

- `up`: Kind, Override Average/`swf_promo`, bestehende Regeln → 20 / 50
- `down`: fail-closed bei Promo-Positionen; Länge/Aufschlag bleiben

## Tests

- Feature: Katalog, Acceptance, Migration
- Unit: Formel
- MySQL: `SwfPromoMysqlTest` + `MysqlTestDatabaseGuard`
- E2E isoliert: Port **8070**, `playwright.po-swf-promo-1.config.ts`

## Bewusst nicht

Contest/Pay-Off, Event-Tipp, weitere SWF, Calendar/Festpreis/Produktion Promo, Deploy, Merge.
`BL-P5-01` bleibt teilweise.
