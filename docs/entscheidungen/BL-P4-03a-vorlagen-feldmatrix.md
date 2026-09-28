# BL-P4-03a/03c – Feld- und Funktionsmatrix (Vorlagen-Editor)

Stand: 27. September 2026 · `main` nach PR #93 (`a850d52…`, 03b abgeschlossen) ·
Hydrate-Folgeslice **BL-P4-03d** offen. PO-BLP403A-1 / PO-BLP403C-1 / PO-BLP403B-1 /
UX-GATE-D

**03a (PR #91):** Spot Classic **Average** mit mehrfach Positionen und den unten
Gruppe‑1-Feldern. **03c (PR #92):** zusätzlich optionale **Hauptspot+Allonge**-
Komponenten (Semantik BL-P4-02c / SPT-014). **03b (PR #93):** aus zugänglicher
Kalkulation kundenlosen Draft erzeugen (nur Average + optionale Komponenten;
sonst Ablehnung ohne stille Reduktion). **03d (offen):** gemeinsamer versionierter
Persistenzvertrag Freeze↔Hydrate. Keine vollständige Standardangebotsfunktion
über alle Kalkulationsmethoden.

## Gruppen

| Gruppe | Bedeutung |
|--------|-----------|
| **1** | In der kundenlosen Vorlage erfassen/bearbeiten |
| **2** | Erst bei Übernahme in die Kundenkalkulation ergänzen |
| **3** | Berechnet/eingefroren – Anzeige, nicht frei tippen |
| **4** | Methodenspezifisch – weitere Arbeit / Folgeslice |

## Matrix

| Feld / Funktion | Gruppe | Beleg Code / Vertrag | Hinweis |
|-----------------|--------|----------------------|---------|
| Vorlagen-Titel (`standard_offers` / Versionstitel) | 1 | `StandardOfferWriter`, STD-001/008 | UI im Wizard-Kontext |
| `campaign`, `product_title`, `briefing` | 1 | `StandardOfferAverageContract::normalizeDraftPayload`, Wizard-Payload | Average-Scope |
| `planning_mode` | 1* | Calc-Wizard; Vertrag 03a/03c | *nur `manual`; Budget → Gruppe 4 |
| `order_discounts` / Kopfkonditionen | 1 | `CalculationPayloadRequest`, Writer-Freeze | |
| `ae_enabled` + Positions-`ae_percent` | 1 | Wizard + Freeze | |
| Inventar + Werbemittel (Spot Classic) | 1 | CatalogResolver / Wizard-Katalog | Mehrere Positionen; kein Tandem/Tridem-Medium |
| `spot_method = average` | 1 | `StandardOfferAverageContract` | Nur Average freigegeben |
| `length_seconds` | 1 | Calc-Payload; bei Komponenten Summe der Längen | 02c-Semantik |
| `total_spot_count` + `time_ranges` + `plan_rows` | 1 | `PriceTimeRanges`, Average-Vertrag | |
| `price_year` (Preisjahrwahl) | 1 | PO-PRI-YEAR-1 / Wizard | Live-Bindung beim Publish-Freeze |
| Positionskonditionen (`position_discounts`) | 1 | Calc-Payload | |
| Dynamische Header-/Positionsfelder (ohne Kundenbezug) | 1 | `dynamic_field_values`, VER-004 Freeze | |
| Mehrere Positionen anlegen/entfernen/bearbeiten | 1 | Wizard-Positionen | |
| Komponenten Hauptspot+Allonge + Strategie | 1 | 03c / SPT-014 / BL-P4-02c | optional; `[]`/absent = aus; `null` abgelehnt; Strategien nur laut Inventarregel |
| Preis-/Summenvorschau (NN, Media-Brutto, …) | 3 | `CalculationWriter::preview` via Std-Offer-Route | AUTH-007: nicht über Calc-Route |
| `customer_name` | 2 | STD-001, Adopt-UI | Freitext bis CRM |
| `agency_name` | 2 | STD-001, Adopt optional | |
| Mediaberater / Owner der Kundenkalkulation | 2 | Calc nach Adopt | Vorlage speichert keinen Owner-Kundenkontext |
| `nn_invest`, `media_gross`, Plan-Second-Prices | 3 | Publish `frozen_materialization`; VER-004 | Nach Publish unveränderlich |
| `price_list_id` / Version (Pin) | 3 | Freeze bei Publish; Client `prohibited` auf Create | |
| Konfigurationssnapshot / Fingerprints | 3 | `ConfigurationSnapshotFreezeService` | |
| Herkunft `origin_standard_offer_version_id` | 3 | STD-005 Nachvollziehbarkeit | Keine Sync |
| `campaign_period` (Header-Dyn-Feld) | 1* | Wizard-Payload unverändert zu 03a | *keine neue Datums-/Shift-Logik in 03c; Werte wie bisher mitspeicherbar; fachliche Klärung Folgeslice möglich |
| `period_open` / `position_flight_period` | 1* | Positions-Dyn-Felder unverändert zu 03a | *stabil; keine Calendar-/Kampagnenverschiebung in 03c |
| Calendar / `planner_entries` | 4 | Contract + Validierung lehnen ab | UI im Vorlagenmodus ausgeblendet |
| Tandem/Tridem (`component_profile`) | 4 | Contract + Validierung lehnen ab | Medium nicht wählbar |
| Festpreis (`pricing_settlement_mode` / `fixed_price_nn`) | 4 | Contract + Validierung lehnen ab | UI ausgeblendet |
| Budget-Planungsmodus | 4 | Contract + Validierung lehnen ab | UI ausgeblendet |
| Abbinder | 4 | zurückgestellt | Kein Scope |
| „Als Standardangebot speichern“ (aus Calc) | 1* | **BL-P4-03b** / PO-BLP403B-1 | *nur Average + optionale Komponenten; sonst Ablehnung; immer neuer `SA-`-Draft; keine Auto-Publish/Sync |

## Vertrags-IDs

- **STD-001** kundenlos · **STD-002** Versionen · **STD-003** Nav · **STD-004** Übernahme · **STD-005** Isolation · **STD-006** übernommene Kundenkalkulation anpassbar (Sender, Mengen, Budget-Assistent) · **STD-007** kein Dispo aus Vorlage · **STD-008** Audit/Autor · **STD-009** PM/Admin/GF verwalten Vorlagen inkl. Preis-/Produkt-Snapshots
- **AUTH-006** PM verwaltet Vorlagen · **AUTH-007** PM ohne Calc/Dispo/Adopt
- **PO-BLP403B-1** Vertrieb: nur Vorschlags-Draft aus zugänglicher Calc (kein STD-009)
- **VER-004** Snapshot-Freeze, keine Sync
- **SPT-014** Hauptspot/Allonge (Calc-Semantik 02c, Vorlagen 03c)
- **UX-GATE-D / PO-BLP403A-1** Teilfreigabe Oberfläche 03a
- **UX-GATE-D / PO-BLP403C-1** Teilfreigabe Komponentenbedienung im bestehenden Vorlageneditor
- **UX-GATE-D / PO-BLP403B-1** Teilfreigabe From-Calc-Vorschlag

## Geliefert in BL-P4-03b

- Aktion „Als Standardangebot speichern“ (Button/Route/Policy deckungsgleich; Propose = `view` ∩ schmales Propose-Recht, AUTH-002)
- Zentraler Sanitize (`StandardOfferFieldClassification` + `StandardOfferFromCalculationSanitizer`); Prüfstufe speichert **nur Feldnamen**, keine Quell-Freitextwerte
- Ausdrückliche Bestätigung `pruefung-bestaetigen` vor Publish; Save/Publish bestätigen nicht still
- `StandardOfferMaterializer` (materialization_version=1) für **Publish/Freeze**
- Adopt nutzt Frozen-Stand; **gemeinsamer Hydrate-Pfad** = Folgeslice **BL-P4-03d**
- Immer neuer `SA-`-Draft; keine Auto-Publish; keine Sync zur Quelle
- Nur Average + optionale Komponenten; sonst Ablehnung ohne stille Reduktion
- `source_calculation_id` nur als technische Referenz an der Version (keine Kundendaten); Quell-Freitexte nicht in Audit/Frozen
