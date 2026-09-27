# Smoke BL-P4-03c – Average-Vorlagen + Hauptspot/Allonge

**Datum:** 2026-09-27  
**Port:** `http://127.0.0.1:8045`  
**Worktree:** `dispo-wt-bl-p4-03c` (isoliert, SQLite `database/smoke-bl-p4-03c.sqlite`)  
**Testdaten:** `E2ESpotDistributionExportSeeder` (APP_ENV=testing, E2E_SERVER=1 beim Seed)  
**Endstand:** Komponenten im Vorlagenmodus wählbar; Draft/Publish/Adopt mit Hauptspot+Allonge OK.

## PM (`pm@example.com` / `password`)

| Schritt | Ergebnis | Sichtbare Werte | Screenshot |
|--------|----------|-----------------|------------|
| Liste | OK | Standardangebote, Scope-Hinweis Average | `smoke-pm-list.png` |
| Neu / Grunddaten | OK | Scope-Note BL-P4-03c; nur „Selbst planen“ (Budget ausgeblendet) | `smoke-pm-grunddaten.png` |
| Werbeelemente + Komponenten | OK | „Spot-Komponenten aktivieren“; Hauptspot 20 + Allonge 10; Strategie Gemeinsame Gesamtlänge; 10 Spots; N/N 600 | `smoke-pm-components.png` |
| Speichern Entwurf | OK | `SA-2026-00001`, Draft, Roundtrip Komponenten 20/10 | — |
| Veröffentlichen | OK | Freeze 1 Position, N/N **600.00**, 2 Komponenten in Materialisierung | `smoke-pm-published-detail.png` |

## Vertrieb (`sales@example.com` / `password`)

| Schritt | Ergebnis | Sichtbare Werte | Screenshot |
|--------|----------|-----------------|------------|
| Detail published + Übernehmen | OK | Freeze N/N 600; Kunde „Smoke Kunde 03c GmbH“ → Kalkulation #7 | — |
| Kalkulation Werbeelemente | OK | Komponenten übernommen (Hauptspot 20 / Allonge 10, shared_total_length); Calendar/Festpreis wieder verfügbar (Calc, nicht Vorlage) | `smoke-sales-adopted-components.png` |

## Verifizierte Grenzen

| Prüfpunkt | Ergebnis |
|-----------|----------|
| Budgetplanung im Vorlagenmodus | ausgeblendet |
| Festpreis im Vorlagenmodus | nur Normal, nicht wählbar |
| Kalenderplaner im Vorlagenmodus | nicht angeboten (nur Durchschnitt) |
| Tandem-Medium im Vorlagenmodus | nicht im Select (nur Spot Classic) |
| Abbinder / Button „aus Kalkulation“ | nicht vorhanden |

## DB-Nachweis (Smoke-SQLite)

- Published Version: `nn_invest=600.00`, `components` count = 2  
- Adoptierte Kalkulation: `K-2026-00007`, Strategie `shared_total_length`, 2 Komponentenzeilen
