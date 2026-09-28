# Smoke BL-P4-03e – Festpreis in Average-Standardangeboten

**Datum:** 2026-09-28  
**Port:** `http://127.0.0.1:8048`  
**Worktree:** `dispo-wt-bl-p4-03e` (isoliert, SQLite `database/smoke-bl-p4-03e.sqlite`)  
**Testdaten:** `E2ESpotDistributionExportSeeder` + Calc `K-2026-00007` (Festpreis N/N **288,50**)  
**Feature-HEAD (Smoke):** `9b94134` (UI-Publish-Smoke unverändert gültig; PHPStan-Nachzug)

## Pfad A – Writer-Nachzug (früherer Durchlauf, Referenz)

| Schritt | Rolle | Sichtbare Werte | Ergebnis |
|--------|-------|-----------------|----------|
| Calc mit Festpreis | Vertrieb | `K-2026-00007`, N/N **275,00** (früherer Smoke-Stand) | OK |
| Als Standardangebot speichern | Vertrieb | Draft `SA-2026-00001`, Mode `fixed_price` | OK |
| Vorlagen-Wizard Werbeelemente | PM | Festpreis-UI geprüft | OK (`smoke-pm-festpreis-werbeelemente.png`) |
| Freitext-Prüfung + Publish | PM | **Ack/Publish per Writer** nach Browser-Prüfung der Festpreis-UI (kein UI-Klick „Veröffentlichen“) | OK (`smoke-pm-published-freeze.png`) |
| Adopt + Weiterbearbeitung | Vertrieb | Festpreis bleibt | OK (`smoke-sales-adopted-festpreis.png`) |

**Kennzeichnung:** Writer-Pfad – Publish nicht über den Button „Veröffentlichen“ ausgelöst.

## Pfad B – tatsächlicher PM-Publish-Klick (dieser Nachzug)

| Schritt | Rolle | Ausgang → Ergebnis | Beobachtung |
|--------|-------|--------------------|-------------|
| Calc mit Festpreis | Vertrieb `sales@example.com` | `K-2026-00007`, Kunde „Smoke UI Publish Kunde“, Kampagne „Smoke UI FP Kampagne“, Position RH 10×30s, Settlement **fixed_price**, `fixed_price_nn` **288.5**, N/N-Invest **288.5** | DB + Wizard |
| Als Standardangebot speichern | Vertrieb | Toast: „Vorschlag SA-2026-00001 als Standardangebot-Entwurf angelegt…“ | UI-Klick (`smoke-sales-ui-proposal-created.png`) |
| Vorlagen-Wizard Werbeelemente | PM `pm@example.com` | Festpreis (N/N) gewählt, Feld **288,5**; Scope-Note BL-P4-03e | UI (`smoke-pm-ui-festpreis-werbeelemente.png`) |
| Freitext-Prüfung bestätigt | PM | Banner „Freitext-Prüfung erforderlich“ → nach Klick weg; DB `proposal_review.acknowledged_at=2026-09-28T10:03:19+00:00` | UI-Klick |
| **Veröffentlichen** | PM | UI-Button „Veröffentlichen“ geklickt; Detail: „Version veröffentlicht.“; Freeze **1 Position(en), N/N 288.50**; Status `published`, `published_at=2026-09-28 10:03:30`; Frozen `materialization_version=2`, Mode `fixed_price`, FP `288.50` | **echter UI-Publish** (`smoke-pm-ui-published-freeze.png`) |
| Übernehmen | Vertrieb | Kunde „Smoke UI Adopt Kunde“, Kampagne „Smoke UI Adopt Kampagne edit“ → `K-2026-00008` | UI-Klick |
| Adoptierte Calc Werbeelemente | Vertrieb | Festpreis (N/N) gewählt, Feld **288,5**; DB Mode `fixed_price`, `fixed_price_nn=288.5`, `nn_invest=288.5`, `origin_standard_offer_version_id=1`; Toast „Standardangebot als Kundenkalkulation übernommen.“ | UI (`smoke-sales-ui-adopted-festpreis.png`) |

## Verifiziert (Pfad B)

- Calc → kundenloser Draft behält Festpreis (kein stilles Zurücksetzen)
- Vorlagen-UI zeigt Festpreis; Calendar weiterhin wählbar nur in Calc
- **Publish über UI-Button** schreibt Materialisierung **v2** mit Frozen-Festpreis `288.50`
- Adopt-Hydrate: `fixed_price` + `fixed_price_nn=288.5`, editierbar; Vorlage unverändert

## Grenzen

- Pfad A bleibt als früherer Writer-Nachzug dokumentiert und wurde **nicht** als UI-Publish umgedeutet
- Calendar/Tandem/Budget-auf-Vorlage/Abbinder weiter außerhalb Scope
