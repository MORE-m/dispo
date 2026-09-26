# Smoke BL-P4-03a – Vorlagen-Editor (Average)

**Datum:** 2026-09-26  
**Port:** `http://127.0.0.1:8044`  
**Worktree:** `dispo-wt-bl-p4-03a-smoke` (isoliert, SQLite `database/smoke-bl-p4-03a.sqlite`)  
**Testdaten:** `E2ESpotDistributionExportSeeder` (APP_ENV=testing, E2E_SERVER=1 beim Seed)  
**Kein Merge.**

## Befunde (behoben vor Abnahme-Bericht)

1. **Speichern blockiert – Budget-/Leerfelder:** Wizard sandte `target_budget_nn` / leere `planner_entries`; Validierung `prohibited` → Payload für Vorlagen bereinigt, leere Arrays erlaubt.
2. **Speichern blockiert – Preisliste fehlt:** `expected_price_list_id` fehlte in `validatedDraftRequest`-Rules und wurde stillschweigend verworfen → Rule ergänzt.
3. **Veröffentlichen / Übernehmen HTTP 403:** `Gate::policy` nur für `StandardOffer`, nicht für `StandardOfferVersion` → Publish/Adopt immer Forbidden. Fix: Policy auch für `StandardOfferVersion` registrieren + HTTP-Regressionstest.

## PM (`pm@example.com` / `password`)

| Schritt | Ergebnis | Sichtbare Werte | Screenshot |
|--------|----------|-----------------|------------|
| Liste / Neu | OK | Wizard-Template-Modus, Hinweis Average-only | `smoke-pm-list.png`, `smoke-pm-wizard-positions.png` |
| Anlegen 2 Positionen + Konditionen | OK nach Fix | Titel „Smoke Average Vorlage“, RH 10→12 Spots, RAH 5 Spots, Mengenrabatt 10 %, AE an | `smoke-pm-create-conditions.png` |
| Speichern Entwurf | OK | `SA-2026-00001`, Draft v1, 2 Positionen, `expected_price_list_id` 1/2 | `smoke-pm-draft-saved.png` |
| Erneutes Öffnen / Bearbeiten | OK | Beide Positionen + Konditionen wieder editierbar; Spots 12 gespeichert (`lock_version` 2) | — |
| Veröffentlichen | OK nach Policy-Fix | Freeze 2 Positionen, N/N **780.30**; UI-Publish war vorher 403 | — |

## Vertrieb (`sales@example.com` / `password`)

| Schritt | Ergebnis | Sichtbare Werte | Screenshot |
|--------|----------|-----------------|------------|
| Liste veröffentlicht | OK | SA-2026-00001 „Smoke Average Vorlage“ v1 | `smoke-sales-list.png` |
| Detail / Version | OK | Freeze: 2 Positionen, N/N 780.30; Übernahme-Formular | `smoke-sales-published-detail.png` |
| Übernehmen mit Kunde | OK nach Policy-Fix | Kunde „Smoke Kunde GmbH“, Agentur „Smoke Agentur“ → Kalkulation #7 | `smoke-sales-adopted-calc.png` |
| Kalkulation bearbeiten | OK | Spots RH 12→15, Speichern, Summen 900 € / 300 € | — |

## Verweigerte Zugriffe

| Rolle | Prüfpunkt | Ergebnis |
|-------|-----------|----------|
| Disposition | `GET /standardangebote` | **403** (Pest `test_roles_visibility` + `test_http_publish…`) |
| PM | `GET /kalkulationen` | **403** (AUTH-007, Pest) |
| PM | Adopt HTTP | **403** (Pest AT-29) |

## Verbleibende Grenzen (kein vollständiges Standardangebot)

- Server akzeptiert nur **Spot Classic Average** (Calendar / Komponenten / Festpreis / Tandem/Tridem / Abbinder: Folgeslices; UI zeigt Methoden noch an, Speichern wird abgewiesen).
- Kein Button „aus Kalkulation Standardangebot erstellen“ (eigener Folgeslice).
- Preisvorschau im Template manchmal „Berechnet …“ bis Preview durch ist; Materialisierung bei Publish ist maßgeblich.
- UI zeigt weiterhin Kalender-/Festpreis-Optionen (Hinweis im Banner; nicht freigegeben).
