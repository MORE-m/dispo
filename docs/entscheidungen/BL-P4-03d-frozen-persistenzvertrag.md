# BL-P4-03d – Frozen Persistenzvertrag (Freeze ↔ Hydrate)

Status: **Akzeptiert** (technischer Folgeslice nach BL-P4-03b)  
Stand: 27. September 2026  
IDs: `VER-004`, `STD-004`, `STD-005`, `STD-006`, `SPT-014`

## Entscheidung

Einfrorene Spot-Classic-Average-Vorlagendaten (optional Hauptspot+Allonge) haben
einen **ausdrücklich versionierten Persistenzvertrag**:

| Seite | Klasse | Aufgabe |
|-------|--------|---------|
| Freeze | `StandardOfferMaterializer` | Schreibt `frozen_materialization` inkl. `materialization_version` |
| Hydrate | `FrozenCalculationPersistenceContract` | Materialisiert Adopt-Kalkulationen ausschließlich aus Frozen-Werten |

`StandardOfferWriter::adopt()` orchestriert nur noch Status/Lock, Übernahmekontext
(Kunde, optionale Agentur, Advisor, Kampagne) und Audit. Die Feldpersistenz liegt
im Vertrag.

## Architekturgrenze

- **Erlaubt:** Klon der eingefrorenen Config-Snapshots; Übernahme der Frozen-
  Preise/Summen/Komponenten/Zeitbereiche/Planzeilen/Rabatte/AE/Dyn-Feld-Payload.
- **Verboten auf Adopt:** `CalculationWriter::create()`, Live-Preisauflösung,
  Neuberechnung aus Katalog/Preisliste, Verbindung zur Quellkalkulation.
- **Nach Adopt:** normale Bearbeitung über `CalculationWriter::update()` (STD-006).

## Versionierung

- Aktuell unterstützt: **Version 1** (`SUPPORTED_VERSIONS = [1]`).
- Fehlendes `materialization_version` = Legacy **implizit 1** (03a/03c-Stände).
- Unbekannte künftige Versionen → verständlicher Fehler, **keine** teilweise
  angelegte Kalkulation (Assert vor Persistenz + Transaktion).
- Unvollständige/ungültige Frozen-Struktur → ebenfalls Fail-closed.

## Feldabbildung

Die Average-v1-Feldabbildung (Kopf, Positionen, Komponenten, Strategien,
Zeitbereiche, Planzeilen, Rabatte, AE, Snapshots, Herkunft) wird im Docblock von
`FrozenCalculationPersistenceContract` zentral beschrieben und dort gepflegt.

**Keine** automatische Unterstützung neuer Kalkulationsmethoden allein durch
diesen Refactor: Calendar/Festpreis/Tandem/Abbinder brauchen eigene Version oder
explizite Contract-Erweiterung.

## Entfernter Doppelcode

Die zuvor in `StandardOfferWriter::adopt()` duplizierten Persistenzlisten
(Positionen, Komponenten, Zeitbereiche, Planzeilen, Rabatte, Dyn-Feld-Sync) sind
in den Vertrag verschoben. Freeze bleibt im Materializer; die Struktur ist der
gemeinsame Vertrag, nicht `CalculationWriter::fillAndPersist` (Live-Pfad).

## Nicht-Ziele

- Keine neue Fachoberfläche / keine neue UX-GATE-D-Teilfreigabe
- Keine Calendar-/Festpreis-/Tandem-Vorlagen
- Keine Änderung der 03a/03b/03c-Fachsemantik
