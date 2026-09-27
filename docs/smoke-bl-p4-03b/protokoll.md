# Smoke BL-P4-03b – Kalkulation als Standardangebot speichern

**Datum:** 2026-09-27  
**Port:** `http://127.0.0.1:8046`  
**Worktree:** `dispo-wt-bl-p4-03b` (isoliert, SQLite `database/smoke-bl-p4-03b.sqlite`)  
**Testdaten:** `E2ESpotDistributionExportSeeder` (APP_ENV=testing, E2E_SERVER=1)  
**Endstand:** Vertrieb erzeugt Vorschlags-Draft; PM publish nach Freitext-Prüfung; Adopt isoliert.

## Vertrieb (`sales@example.com` / `password`)

| Schritt | Ergebnis | Sichtbare Werte | Screenshot |
|--------|----------|-----------------|------------|
| Calc Calendar öffnen | OK | Button „Als Standardangebot speichern“ sichtbar (Ablehnung serverseitig, Pest) | `smoke-sales-button-calendar.png` |
| Calc Average `K-2026-00004` | OK | Kunde „SPT008 Average GmbH“; Kampagne/Briefing gesetzt | — |
| Als Standardangebot speichern | OK | Redirect zurück auf Calc; Flash-Hinweis; `SA-2026-00001` Draft | — |
| Draft in Liste/Detail | OK | Vertrieb sieht Draft **nicht** (nur Published) | — |

## Produktmanagement (`pm@example.com` / `password`)

| Schritt | Ergebnis | Sichtbare Werte | Nachweis |
|--------|----------|------------------|----------|
| Quellcalc öffnen | **403** (AUTH-007) | Pest + Policy | Pest `test_pm_reviews…` |
| Draft prüfen | OK | `proposal_review.review_required=true`; Freitext campaign/briefing; Draft ohne `customer_name` | SQLite / Tinker |
| Publish ohne Ack | Fehler | `proposal_review` Validation | Pest |
| Publish mit Ack | OK | Status published; `materialization_version=1`; `nn_invest=600.00`; Freitext geleert | Tinker Smoke |

## Übernahme / Isolation

| Prüfpunkt | Ergebnis |
|-----------|----------|
| Adopt `Smoke Kunde 03b GmbH` → `K-2026-00007` | OK |
| Quelle `K-2026-00004` unverändert (Kunde/Kampagne) | OK |
| Keine Sync Quellcalc ↔ Vorlage | OK |

## Verifizierte Grenzen

| Prüfpunkt | Ergebnis |
|-----------|----------|
| Calendar/Festpreis/Tandem/Budget → Ablehnung mit Positionsangabe | Pest |
| Vertrieb ohne Edit/Publish | Pest |
| Disposition/PM ohne Propose | Pest |
| Altversionen ohne `materialization_version` adoptierbar | Pest |

## DB-Nachweis (Smoke-SQLite)

- `SA-2026-00001` published, Materialization v1, NN 600.00  
- `SA-2026-00002` zusätzlicher Review-Draft aus `K-2026-00006`  
- Adoptierte Kalkulation: `K-2026-00007`, Origin Version 1
