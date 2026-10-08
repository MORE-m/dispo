# Bericht: Produktion/Sonstiges – BL-P5-02a Umsetzung + Review-Nachzug

Stand: 8. Oktober 2026
Arbeitsbasis: Feature `feat/bl-p5-02a-spot-production` auf `origin/main` @ `7583a3e…`
(PR #131 Draft; **kein** Deploy)

PO-BLP502-1 **akzeptiert** (A1/B1/C1/D1/E1/F/G1/H1a). Teilscope auf Feature-Branch.

## Artefakte

| Datei | Rolle |
|---|---|
| [`docs/entscheidungen/PO-BLP502-1-produktion-sonstiges.md`](../../entscheidungen/PO-BLP502-1-produktion-sonstiges.md) | PO **Akzeptiert** |
| [`docs/entscheidungen/PO-BLP502-1-aufloesungsvertrag.md`](../../entscheidungen/PO-BLP502-1-aufloesungsvertrag.md) | Pin-/Jahresauflösung inkl. AE-/Rabatt-Freeze |
| [`docs/readiness/BL-P5-02-produktion-2026-10-08.md`](../../readiness/BL-P5-02-produktion-2026-10-08.md) | Readiness READY / Teilscope |

## Scope

- Spotproduktion an Spot Classic × Average
- Admin-Produktionspreise inventar×Typ×Jahr (Draft/Active/Archive, Lock, Audit)
- Calc Zusatzzeilen: Menge×Einzelpreis; Flags pflegbar; Rabatt/AE je Zeilenflag
- Sales-Preisüberschreibung ausgeschlossen
- Dispo eigene S-Zeilen; Calc ohne zusätzliche S-Anzeige
- Inventarübergreifend konfigurierbar; synthetische Fixtures

## Review-Nachzug (PR #131)

Vier Befunde nachvollzogen, mit Regressionstests belegt und behoben:

### 1. Sonderfreigabe für Produktionsrabatte

**Ursache:** `CalculationEngine::calculate` / `SpecialApprovalAssessor::reasonsFromStoredPosition` prüften nur Trägerflags und Medienbeträge. Produktionsrabatte (Positions-/Auftrags-/effektiv) konnten die Grenze überschreiten, ohne Sonderfreigabe.

**Korrektur:** Eigenständige Prüfung bei rabattfähiger Produktion (Preview + Save + Dispo inkl. Teilübernahme); Deduplizierung über bestehende `SpecialApprovalAssessment::fromReasons`.

**Nachweise:** u. a. Träger nicht rabattfähig + Produktion rabattfähig + Auftragsrabatt 20 % / Limit 10 % → Sonderfreigabe; gestapelt 8 %+8 % → Effective; Teilübernahme nur betroffenem Träger; Produktion nicht rabattfähig → keine produktionsbedingte Freigabe.

### 2. Produktions-Positionsrabatte unabhängig vom Trägerflag

**Ursache:** `positionInputsFromPayload` nullte `positionDiscounts` bei `!is_discountable` des Trägers; `calculateProduction` erhielt keine Konditionen.

**Korrektur:** Validierte Rabatte ungefiltert an die Engine; Medien weiter nur bei Trägerflag; Produktion je Zeilenflag. Wizard: Positionsrabatt-UI auch bei Träger-nicht-rabattfähig, wenn Preview eine rabattfähige Produktionszeile meldet.

**Nachweise:** 300 −10 % → 270 bei Träger nicht rabattfähig; umgekehrte Flags; alle vier Flag-Kombinationen; gestapelte Rabatte; Preview→Save→Reload→Mengenänderung→Dispo inkl. Admin-Nachfolger-Pin.

### 3. Produktions-AE-Satz einfrieren

**Ursache:** Dispo `productionFromLine` nutzte `carrier->ae_percent` (0 %, wenn Träger nicht AE-fähig).

**Korrektur:** Neue Spalte `ae_percent` an `calculation_position_production_lines` (Migration `2026_10_08_140000_…`); Engine schreibt angewendeten Satz; Dispo übernimmt ihn. Sales kann den Satz nicht per Payload setzen.

**Nachweise:** Träger nicht AE-fähig, Produktion AE-fähig → 45,00 AE / 255,00 N/N / 15 % in Calc und Dispo; Flag-Matrix; AE aus; Rabatt+AE; Pin nach Admin-Nachfolger; Dispo-Snapshot stabil bei späterem Calc-Update.

### 4. From-Calc ohne stille Produktionsverluste

**Ursache:** `assertCompatibleOrFail` ließ Average mit `production_lines` zu; `sanitizePosition` entfernte sie still (nicht in TEMPLATE_SAFE-Keys).

**Korrektur:** Explizite Ablehnung in `assertCompatibleOrFail` vor Draft-Anlage (auch Menge 0; gemischte Quellen komplett).

**Nachweise:** Quelle mit Produktion / Menge 0 / gemischt → kein neuer Draft; ohne Produktion → Flow unverändert erfolgreich.

### Restbefund P1: gemischte Produktionsflags (erneute Review)

**Ursache:** Effektiver Produktionsrabatt nutzte `production_gross` aller Zeilen. Nicht rabattfähige Zeilen verdünnten den Prozentsatz (z. B. 46,08 / 600 = 7,68 % statt 15,36 % auf rabattfähigen 300).

**Korrektur:** Preview (`CalculationEngine`) und gespeicherter Assessor rechnen den effektiven Rabatt nur über rabattfähige Produktionszeilen (Brutto + Rabattbeträge). Positions-/Auftragsprüfung unverändert.

**Nachweise:** Admin-Nachfolger-Pfad (alte Zeile pinnt `is_discountable=true`, neue aus Nachfolger `false`); 300→276→253,92 (46,08 = 15,36 %); Sonderfreigabe zwingend; verdünntes 7,68 % bestimmt nicht; Teilübernahme nur betroffenem Träger; zusätzliche nicht rabattfähige Zeilen ändern Freigabe nicht; nur nicht rabattfähige Produktion → keine produktionsbedingte Freigabe.

### Restbefund P2: Produktions-Positionsrabatte im Dispo-Snapshot

**Ursache:** `productionFromLine` übernahm `carrier->position_discount_percent` und setzte `position_discounts_snapshot` leer.

**Korrektur:** Additive Migration `position_discount_percent` + `position_discounts_snapshot` an Calc-Produktionszeilen; Engine friert angewendete Staffel ein (leer/`0` wenn nicht rabattfähig); Dispo kopiert gespeicherte Werte. Sales-Payload manipuliert diese Felder nicht.

**Nachweise:** 300 −10 % → 270 mit Dispo 10 % + Staffel; gestapelt 10 %+5 % → 14,5 % / 43,50; Träger rabattfähig / Produktion nicht → 0; gemischte Pins; Snapshot stabil bei späteren Calc-/Admin-Änderungen.

## Abnahme

- Feature-Tests Admin + Calc/Dispo + Review-Regressionen (`SpotProductionBlP502aTest`)
- Browser Port **8057**, `--retries=0`: Vertical Slice; Inventarwechsel; Calendar-Sperre; **divergente Flags Preview→Save→Reload→Dispo**
- Fixtures: 2×150=300, 3×80=240, Summe Produktion 540 (+ Medien); Review-Belege 270 / 255 / 229,50 / 15,36 %

## Offen

`BL-P5-02`/`AT-11` teilweise; operative Preise; Sonstiges/Überschreibung; andere Träger; Produktion in Standardangeboten; **kein** Deploy.
