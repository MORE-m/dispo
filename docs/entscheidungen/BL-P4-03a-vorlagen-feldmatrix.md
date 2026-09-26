# BL-P4-03a – Feld- und Funktionsmatrix (Vorlagen-Editor)

Stand: 26. September 2026 · Feature-HEAD PR #91 · PO-BLP403A-1 / UX-GATE-D Teilfreigabe

**Erster nutzbarer Umfang in PR #91:** Spot Classic **Average** mit mehrfach Positionen und den unten Gruppe‑1-Feldern. Keine vollständige Standardangebotsfunktion über alle Kalkulationsmethoden.

## Gruppen

| Gruppe | Bedeutung |
|--------|-----------|
| **1** | In der kundenlosen Vorlage erfassen/bearbeiten |
| **2** | Erst bei Übernahme in die Kundenkalkulation ergänzen |
| **3** | Berechnet/eingefroren – Anzeige, nicht frei tippen |
| **4** | Methodenspezifisch – weitere Arbeit / Folgeslice |

## Matrix

| Feld / Funktion | Gruppe | Beleg Code / Vertrag | Hinweis PR #91 |
|-----------------|--------|----------------------|----------------|
| Vorlagen-Titel (`standard_offers` / Versionstitel) | 1 | `StandardOfferWriter`, STD-001/008 | UI im Wizard-Kontext |
| `campaign`, `product_title`, `briefing` | 1 | `StandardOfferAverageContract::normalizeDraftPayload`, Wizard-Payload | Average-Scope |
| `planning_mode` | 1* | Calc-Wizard; Vertrag 03a | *nur `manual`; Budget → Gruppe 4 |
| `order_discounts` / Kopfkonditionen | 1 | `CalculationPayloadRequest`, Writer-Freeze | |
| `ae_enabled` + Positions-`ae_percent` | 1 | Wizard + Freeze | |
| Inventar + Werbemittel (Spot Classic) | 1 | CatalogResolver / Wizard-Katalog | Mehrere Positionen |
| `spot_method = average` | 1 | `StandardOfferAverageContract` | Nur Average freigegeben |
| `length_seconds` | 1 | Calc-Payload | |
| `total_spot_count` + `time_ranges` + `plan_rows` | 1 | `PriceTimeRanges`, Average-Vertrag | |
| `price_year` (Preisjahrwahl) | 1 | PO-PRI-YEAR-1 / Wizard | Live-Bindung beim Publish-Freeze |
| Positionskonditionen (`position_discounts`) | 1 | Calc-Payload | |
| Dynamische Header-/Positionsfelder (ohne Kundenbezug) | 1 | `dynamic_field_values`, VER-004 Freeze | |
| Mehrere Positionen anlegen/entfernen/bearbeiten | 1 | Wizard-Positionen | |
| Preis-/Summenvorschau (NN, Media-Brutto, …) | 3 | `CalculationWriter::preview` via Std-Offer-Route | AUTH-007: nicht über Calc-Route |
| `customer_name` | 2 | STD-001, Adopt-UI | Freitext bis CRM |
| `agency_name` | 2 | STD-001, Adopt optional | |
| Mediaberater / Owner der Kundenkalkulation | 2 | Calc nach Adopt | Vorlage speichert keinen Owner-Kundenkontext |
| `nn_invest`, `media_gross`, Plan-Second-Prices | 3 | Publish `frozen_materialization`; VER-004 | Nach Publish unveränderlich |
| `price_list_id` / Version (Pin) | 3 | Freeze bei Publish; Client `prohibited` auf Create | |
| Konfigurationssnapshot / Fingerprints | 3 | `ConfigurationSnapshotFreezeService` | |
| Herkunft `origin_standard_offer_version_id` | 3 | STD-005 Nachvollziehbarkeit | Keine Sync |
| Calendar / `planner_entries` | 4 | Contract lehnt ab | Folgeslice |
| Komponenten | 4 | Contract lehnt ab | Folgeslice |
| Tandem/Tridem (`component_profile`) | 4 | Contract lehnt ab | Folgeslice |
| Festpreis (`pricing_settlement_mode` / `fixed_price_nn`) | 4 | Contract lehnt ab | Folgeslice |
| Budget-Planungsmodus | 4 | Wizard Budget-Pfad | Nicht in 03a |
| Abbinder | 4 | zurückgestellt | Kein Scope |
| „Aus Kundenkalkulation Standardangebot erzeugen“ | 4 | eigener Folgeslice | **Kein Button** in PR #91 |

## Vertrags-IDs

- **STD-001** kundenlos · **STD-002** Versionen · **STD-003** Nav · **STD-004** Übernahme · **STD-005** Isolation · **STD-006** Sichtbarkeit Vertrieb nur published · **STD-007** kein Dispo aus Vorlage · **STD-008** Audit/Autor · **STD-009** Nummern
- **AUTH-006** PM verwaltet Vorlagen · **AUTH-007** PM ohne Calc/Dispo/Adopt
- **VER-004** Snapshot-Freeze, keine Sync
- **UX-GATE-D / PO-BLP403A-1** Teilfreigabe Oberfläche 03a

## Folgeslice (spezifiziert, nicht gebaut)

**BL-P4-03b (Arbeitstitel) – Aus Kalkulation Vorlage erzeugen**

- Rechte: analog AUTH-006 (PM/Admin/GF); kein automatisches Calc-Recht für PM
- Entfernt `customer_name` / `agency_name` und kundenbezogene Dyn-Felder
- Übernimmt geeignete Positionen laut dann freigegebenem Methodenvertrag
- Neuer Vorlagen-Snapshot (Publish-Freeze), eigene `SA-`-Nummer
- UI-Button erst nach eigener UX-GATE-D-Teilfreigabe
