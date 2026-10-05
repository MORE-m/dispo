# BL-P4-03d – Frozen Persistenzvertrag (Freeze ↔ Hydrate)

Status: **Akzeptiert** (`main`, PR #95)  
Stand: 28. September 2026  
IDs: `VER-004`, `STD-004`, `STD-005`, `STD-006`, `SPT-014`

## Entscheidung

Einfrorene Spot-Classic-Average-Vorlagendaten (optional Hauptspot+Allonge) haben
einen **ausdrücklich versionierten Persistenzvertrag**. Die Adopt-Persistenz ist
aus `StandardOfferWriter::adopt()` **extrahiert und versioniert**.

| Seite | Klasse | Aufgabe |
|-------|--------|---------|
| Freeze | `StandardOfferMaterializer` | Schreibt `frozen_materialization` inkl. `materialization_version` |
| Hydrate | `FrozenCalculationPersistenceContract` | Validiert + materialisiert Adopt-Kalkulationen aus Frozen-Werten |

`StandardOfferWriter::adopt()` orchestriert nur Status/Lock, Übernahmekontext
(Kunde, optionale Agentur, Advisor, Kampagne) und Audit.

**Freeze- und Hydrate-Feldabbildungen sind zwei gepflegte Seiten desselben
Vertrags** – nicht eine automatisch synchrone Abbildung. Änderungen an der
Freeze-Struktur erfordern bewusst die passende Hydrate-Validierung/Persistenz
(und umgekehrt).

## Architekturgrenze

- **Erlaubt:** Klon der eingefrorenen Config-Snapshots; Übernahme der Frozen-
  Preise/Summen/Komponenten/Zeitbereiche/Planzeilen/Rabatte/AE/Dyn-Feld-Payload.
- **Verboten auf Adopt:** `CalculationWriter::create()`, Live-Preisauflösung,
  Neuberechnung aus Katalog/Preisliste, Verbindung zur Quellkalkulation;
  stille Fallbacks auf `average`/`normal` bei widersprüchlichen Kennzeichen;
  stille null-Umwandlungen fehlender Pflichtfelder.
- **Nach Adopt:** normale Bearbeitung über `CalculationWriter::update()` (STD-006).

## Versionierung

- Unterstützt: **Version 1–4** (`SUPPORTED_VERSIONS = [1, 2, 3, 4]`).
- Fehlendes `materialization_version` = Legacy **implizit 1** (03a/03c-Stände).
- Aktuelle Freeze-Schreibversion: **4** (ab BL-P4-03g; Calendar×normal + Average-Varianten).
- Unbekannte künftige Versionen → verständlicher Fehler, **keine** teilweise
  angelegte Kalkulation (Assert vor Persistenz + Transaktion).
- Unvollständige/ungültige Frozen-Struktur (Methoden-/Abrechnungskennzeichen,
  Strategie/Profil, Kindzeilen, Calendar-`planner_entries`) → Fail-closed.

## Average – Prüfungen vor Persistenz

- `spot_method` falls gesetzt: nur `average`
- **Settlement v1:** `pricing_settlement_mode` falls gesetzt nur `normal`; kein
  `fixed_price_nn`
- **Settlement v2 (03e):** `pricing_settlement_mode` Pflicht (`normal`|`fixed_price`);
  fehlend/leer → unvollständig fail-closed; bei Festpreis Pflicht-
  `fixed_price_nn` im strikten Dezimalformat (`^\d+(\.\d{1,2})?$`, string|int);
  Exponentialnotation/nicht skalare Werte fail-closed vor `bccomp`
- `component_profile` muss leer/null sein
- Komponenten vorhanden → gültige `component_calculation_strategy` Pflicht
- Komponenten-/Zeitbereich-/Planzeilen-/Rabattzeilen: erforderliche Felder
  vorhanden und typgültig (kein Default-Auffüllen bei Widerspruch)
- **Kindlisten-Präsenz** (Freeze schreibt alle Keys; Hydrate unterscheidet):
  - `time_ranges` / `plan_rows`: Schlüssel muss als Array vorliegen; fehlend
    oder `null` → Fail-closed (keine scheinbar erfolgreiche Übernahme mit
    verlorenen Zeilen). `[]` ist eine gültige leere Liste.
  - `components` / `position_discounts` / `order_discounts`: fehlender Schlüssel
    aus Legacy-Gründen = leer; `null` ungültig; `[]` = gültige leere Liste
  (Draft-Semantik 03c: Komponenten absent/`[]` = aus, `null` abgelehnt)

## v4 (BL-P4-03g / PO-BLP403G-1)

Calendar-Hydrate prüft vor Persistenz zusätzlich: nicht-leere `planner_entries` bei
positivem `total_spot_count`, Spot-Summe = `total_spot_count`, keine Duplikat-Zellen
(Datum+Stunde), `day_group` passend zum Datum, gültige positive `second_price`/
`line_gross`. Widersprüche → `ValidationException`, keine Teilanlage.

- Optional `spot_method=calendar` mit Pflicht-Key `planner_entries` (konkrete ISO-Daten,
  eingefrorene Sekundenpreise/Summen); Settlement nur `normal`; keine Komponenten/Profil.
- Average-Positionen in v4 wie v3; `planner_entries` absent/`[]` (nicht-leer fail-closed).
- Hydrate persistiert Planner-Entries ohne Live-Preisauflösung (B1+C1).

## Verbleibende Pflege bei weiteren Methoden

Bei Calendar×Festpreis/Tandem, Budget-Vorlagen, Abbinder (oder anderer Methodik):

1. neue `materialization_version` **oder** explizite Contract-Erweiterung,
2. Freeze-Seite (`StandardOfferMaterializer` bzw. Nachfolger) erweitern,
3. Hydrate-Asserts + Persistenzspiegel in `FrozenCalculationPersistenceContract`
   erweitern,
4. Parity- und Fail-closed-Tests ergänzen.

Neue Methoden werden **nicht** automatisch übernommen.

## Entfernter Doppelcode

Die zuvor in `StandardOfferWriter::adopt()` duplizierten Persistenzlisten
(Positionen, Komponenten, Zeitbereiche, Planzeilen, Rabatte, Dyn-Feld-Sync)
liegen im Hydrate-Vertrag. Freeze bleibt im Materializer – beide Seiten werden
weiterhin getrennt gepflegt.

## Nicht-Ziele

- Keine neue Fachoberfläche / keine neue UX-GATE-D-Teilfreigabe
- Keine Calendar-/Festpreis-/Tandem-Vorlagen
- Keine Änderung der 03a/03b/03c-Fachsemantik für gültige Frozen-Stände
