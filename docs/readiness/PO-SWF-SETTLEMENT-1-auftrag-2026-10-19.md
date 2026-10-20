# PO-SWF-SETTLEMENT-1 – Festpreis-Settlement für operative SWF

Stand: 20. Oktober 2026 · Feature-Branch `feat/po-swf-settlement-1`

## Fachstatus (nach diesem Slice)

| Profil | Avg Normal | Avg Fixed | Cal Normal | Cal Fixed |
|---|---|---|---|---|
| Trailer | OP | OP | OP | OP |
| Allonge | OP | OP | OP | OP |
| Promo | OP | OP | OP | OP |
| Contest | OP | OP | OP | OP |

`pricing_settlement_mode = fixed_price` mit `fixed_price_nn` (Commercial Layer nach Mediabrutto).

## Bewusst weiter offen

- CalculationMethod-Key `fixed_price` (planned, ohne Engine)
- TKP
- Free Position
- weitere SWF (`event_tip`, Opener, Bumper, …)
- Produktion / Komponenten für SWF

## Architektur

- Spot-Settlement-Kern (`finalizePosition` / `deriveFixedPriceAe`) wiederverwendet
- Capability: `EngineProfileRegistry::supportsFixedPriceSettlement` (= operative SWF-Längenprofile)
- Keine neue CalculationEngine, keine Medium-Code-Branches
- Allonge-Confirm bleibt unabhängig vom Settlement verpflichtend

## Tests

- Unit: `SwfSettlementCalculationTest` (+ Profil-Unit-Updates)
- Contract / Acceptance / MySQL: `SwfSettlement*`
- Isolierte Familien-E2E: `playwright.po-swf-settlement-1` (Promo Calendar + Fixed, Port 8076)
