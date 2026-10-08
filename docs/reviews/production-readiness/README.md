# Bericht: Produktion/Sonstiges – BL-P5-02a Umsetzung

Stand: 8. Oktober 2026
Arbeitsbasis: Feature `feat/bl-p5-02a-spot-production` auf `origin/main` @ `7583a3e…`
(PR #130; **kein** Deploy)

PO-BLP502-1 **akzeptiert** (A1/B1/C1/D1/E1/F/G1/H1a). Teilscope auf Feature-Branch.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP502-1-produktion-sonstiges.md`](../../entscheidungen/PO-BLP502-1-produktion-sonstiges.md) | PO **Akzeptiert** |
| [`docs/entscheidungen/PO-BLP502-1-aufloesungsvertrag.md`](../../entscheidungen/PO-BLP502-1-aufloesungsvertrag.md) | Pin-/Jahresauflösung |
| [`docs/readiness/BL-P5-02-produktion-2026-10-08.md`](../../readiness/BL-P5-02-produktion-2026-10-08.md) | Readiness READY / Teilscope |

## Scope

- Spotproduktion an Spot Classic × Average
- Admin-Produktionspreise inventar×Typ×Jahr (Draft/Active/Archive, Lock, Audit)
- Calc Zusatzzeilen: Menge×Einzelpreis; initial kein Rabatt/AE; Flags pflegbar
- Sales-Preisüberschreibung ausgeschlossen
- Dispo eigene S-Zeilen; Calc ohne zusätzliche S-Anzeige
- Inventarübergreifend konfigurierbar; synthetische Fixtures

## Abnahme

- Feature-Tests Admin + Calc/Dispo (`SpotProductionBlP502aTest` u. a.)
- Browser Port **8057**, `--retries=0`: Admin → Wizard → Dispo; Inventarwechsel fail-closed; Calendar-Sperre
- Fixtures: 2×150=300, 3×80=240, Summe Produktion 540 (+ Medien)

## Offen

`BL-P5-02`/`AT-11` teilweise; operative Preise; Sonstiges/Überschreibung; andere Träger; **kein** Deploy.
