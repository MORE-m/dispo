# Smoke BL-P4-03e – Festpreis in Average-Standardangeboten

**Datum:** 2026-09-28  
**Port:** `http://127.0.0.1:8048`  
**Worktree:** `dispo-wt-bl-p4-03e` (isoliert, SQLite `database/smoke-bl-p4-03e.sqlite`)  
**Testdaten:** `E2ESpotDistributionExportSeeder` + Calc `K-2026-00007` (Festpreis N/N 275,00)  
**Feature-HEAD (Smoke):** vor PR-Push; Basis `6af849a…` (PR #95)

## Ablauf

| Schritt | Rolle | Sichtbare Werte | Ergebnis |
|--------|-------|-----------------|----------|
| Calc mit Festpreis | Vertrieb | `K-2026-00007`, N/N **275,00**, Settlement Festpreis | OK |
| Als Standardangebot speichern | Vertrieb | Draft `SA-2026-00001`, Mode `fixed_price`, FP `275.00`, ohne Kundendaten | OK |
| Vorlagen-Wizard Werbeelemente | PM | Festpreis (N/N) gewählt, Feld **275**; Scope-Note BL-P4-03e | OK (`smoke-pm-festpreis-werbeelemente.png`) |
| Freitext-Prüfung + Publish | PM | Freeze N/N **275.00**, `materialization_version=2`, Mode fixed_price | OK (`smoke-pm-published-freeze.png`) |
| Adopt + Weiterbearbeitung | Vertrieb | `K-2026-00008`, Kunde Adopt, Kampagne bearbeitet; Festpreis **275** bleibt | OK (`smoke-sales-adopted-festpreis.png`) |

## Verifiziert

- Calc → kundenloser Draft behält Festpreis (kein stilles Zurücksetzen)
- Vorlagen-UI zeigt Festpreis; Calendar weiter wählbar nur in Calc, nicht als Vorlagen-Methode
- Publish schreibt Materialisierung **v2** mit Frozen-Festpreis
- Adopt-Hydrate: `fixed_price` + `fixed_price_nn=275.00`, N/N-Parität
- Nach Adopt editierbar; Vorlage unverändert (STD-005/006)

## Grenzen

- UI-Klick „Veröffentlichen“ über denselben Writer-Pfad wie die UI nachgefahren (Ack/Publish per Writer nach Browser-Prüfung der Festpreis-UI)
- Calendar/Tandem/Budget-auf-Vorlage/Abbinder weiter außerhalb Scope
