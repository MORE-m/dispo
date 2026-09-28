# BL-P4-03a/03c – Feld- und Funktionsmatrix (Vorlagen-Editor)

Stand: 28. September 2026 · **BL-P4-03f** Feature-PR (PO-BLP403F-1). **BL-P4-03e**
auf `main` (PR #97, `4eddc94…`; Status PR #98). **BL-P4-03d** auf `main` (PR #95).
PO-BLP403A-1 / PO-BLP403C-1 / PO-BLP403B-1 / PO-BLP403F-1 / UX-GATE-D

**03a (PR #91):** Spot Classic **Average** mit mehrfach Positionen und den unten
Gruppe‑1-Feldern. **03c (PR #92):** zusätzlich optionale **Hauptspot+Allonge**-
Komponenten (Semantik BL-P4-02c / SPT-014). **03b (PR #93):** aus zugänglicher
Kalkulation kundenlosen Draft erzeugen (nur Average + optionale Komponenten;
sonst Ablehnung ohne stille Reduktion). **03d (PR #95):** gemeinsamer versionierter
Persistenzvertrag Freeze↔Hydrate
(`docs/entscheidungen/BL-P4-03d-frozen-persistenzvertrag.md`); Freeze und Hydrate
sind zwei gepflegte Seiten ohne Auto-Support neuer Methoden. **03e (PR #97):**
N/N-Festpreis (02d) in Average-Vorlagen; Materialisierung v2; Legacy v1 lesbar.
**03f:** Tandem/Tridem (02e) in Average-Vorlagen; Materialisierung v3; gemischt
mit Average/Allonge; Calendar/Budget/Abbinder weiter Folgeslices. Keine vollständige
Standardangebotsfunktion über alle Kalkulationsmethoden.

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
| Tandem/Tridem (`component_profile`) | 1 | **BL-P4-03f** / PO-BLP403F-1 / 02e-Semantik | Medium wählbar; Reminder-Rollen; `shared_total_length`; Freeze v3 |
| Festpreis (`pricing_settlement_mode` / `fixed_price_nn`) | 1 | **BL-P4-03e** / 02d-Semantik (+ 03f mit Profil) | UI wählbar; serverseitig validiert; Freeze v2+; kein stilles Zurücksetzen |
| Budget-Planungsmodus | 4 | Contract + Validierung lehnen ab | UI ausgeblendet |
| Abbinder | 4 | zurückgestellt | Kein Scope |
| „Als Standardangebot speichern“ (aus Calc) | 1* | **BL-P4-03b** (+ **03e**/**03f**) | *Average + optionale Komponenten/Tandem/Tridem + Festpreis; sonst Ablehnung; immer neuer `SA-`-Draft; keine Auto-Publish/Sync |

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
- Adopt-Hydrate: Folgeslice **BL-P4-03d** (`FrozenCalculationPersistenceContract`)
- Immer neuer `SA-`-Draft; keine Auto-Publish; keine Sync zur Quelle
- Nur Average + optionale Komponenten; sonst Ablehnung ohne stille Reduktion
- `source_calculation_id` nur als technische Referenz an der Version (keine Kundendaten); Quell-Freitexte nicht in Audit/Frozen

## Geliefert in BL-P4-03d

- `FrozenCalculationPersistenceContract`: versionierter Persistenzvertrag Average v1
- Adopt-Persistenzlisten aus `StandardOfferWriter` in den Vertrag verschoben
- Fail-closed für unbekannte `materialization_version`, widersprüchliche
  Methoden-/Abrechnungskennzeichen, Strategie/Profil und unvollständige Kindzeilen
- Legacy ohne Versionsfeld weiterhin übernehmbar; Semantik 03a/03c/03b unverändert
- Freeze- und Hydrate-Feldabbildungen bleiben **zwei gepflegte Seiten**; neue
  Methoden erfordern Version/Contract + Freeze + Hydrate + Tests (ADR 03d)
- ADR: `docs/entscheidungen/BL-P4-03d-frozen-persistenzvertrag.md`
- **Merge `main`:** PR #95 (`6af849a…`); Post-Merge-CI `36398695877` SUCCESS;
  Status-Nachzug PR #96

## Geliefert in BL-P4-03e

- N/N-Festpreis in Average-Vorlagen inkl. Komponenten (Calc-Engine 02d, keine Vorlagenformel)
- Draft-Contract, Sanitize, UI, HTTP-Validierung, Freeze v2, Hydrate v2
- Legacy Average-v1 inkl. fehlendem Versionsfeld weiter übernehmbar
- Kein stilles Zurücksetzen auf `normal`; keine Live-Neuberechnung nach Publish
- Calendar/Tandem/Budget/Abbinder weiter abgewiesen
- Freeze und Hydrate bleiben zwei gepflegte Seiten; keine Auto-Übernahme neuer Methoden
- ADR: `docs/entscheidungen/BL-P4-03e-standardangebot-festpreis.md`
- **Merge `main`:** PR #97 (`4eddc94…`); Post-Merge-CI `36435981330` SUCCESS

## Geliefert in BL-P4-03f

- Tandem/Tridem in Average-Vorlagen (Calc-Engine 02e, keine Vorlagenformel)
- Draft-Contract, Sanitize, UI, HTTP-Validierung, Freeze v3, Hydrate v3
- normal + Festpreis; gemischte Vorlagen mit Average/Allonge
- Legacy v1/v2 inkl. fehlendem Versionsfeld weiter übernehmbar
- Calendar/Budget/Abbinder weiter abgewiesen; Average+Calendar-Quelle ganz abgewiesen
- ADR: `docs/entscheidungen/BL-P4-03f-standardangebot-tandem-tridem.md`
- Status: Feature-PR (kein Merge)
