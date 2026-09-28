# Smoke BL-P4-03f – Tandem/Tridem in Average-Standardangeboten

**Datum:** 2026-09-28  
**Port:** `http://127.0.0.1:8049`  
**Worktree:** `dispo-wt-bl-p4-03f` (isoliert, SQLite `database/smoke-bl-p4-03f.sqlite`)  
**Testdaten:** `E2ETandemTridemSeeder` + PM `pm@example.com` + Calc `K-2026-00001` (Tandem, Festpreis N/N **777,00**)  
**Base:** `f6daf528a02772e4abd0a809c7d1559a56cd49ba` (`main`)  
**Feature-HEAD (Smoke):** Tip dieses Feature-Branches (Draft-PR-HEAD nach Push)

## Pfad A – Writer / Feature-Tests (technisch, getrennt)

| Schritt | Rolle / Ort | Sichtbare Werte | Ergebnis |
|--------|-------------|-----------------|----------|
| Vier Kombinationen Tandem/Tridem × normal/Festpreis | `StandardOfferBlP403fTest` | mat **v3**, Profil, Komponenten, Settlement | OK (PHPUnit) |
| Gemischte Vorlage + Calc→Draft ohne Kundendaten | Feature-Test | `has_customer=false`, Profil/FP erhalten | OK |
| Legacy v1/v2 adoptierbar; ungültiges v3 fail-closed | Feature-Test | v1/v2 adopt; invalid v3 abgewiesen | OK |
| Rechte / Negativfälle | Feature-Test | Calendar-Mix, `individual`, Rollen/Profil | OK |

**Kennzeichnung:** technische Writer-/Contract-Prüfung – **kein** UI-Publish-Klick.

## Pfad B – tatsächlicher UI-Pfad (dieser Smoke)

| Schritt | Rolle | Ausgang → Ergebnis | Beobachtung |
|--------|-------|--------------------|-------------|
| Calc mit Tandem-Festpreis | Vertrieb `sales@example.com` | `K-2026-00001`, Kunde „Smoke UI Tandem Kunde“, Kampagne „Smoke UI Tandem Kampagne“, Spot Tandem, Settlement **fixed_price**, `fixed_price_nn` **777.00**, Komponenten Hauptspot 20 + Reminder 10, N/N **777,00** | DB + Wizard |
| Als Standardangebot speichern | Vertrieb | Toast: „Vorschlag SA-2026-00001 als Standardangebot-Entwurf angelegt…“; Draft ohne `customer_name`, `campaign=null`, Profil `tandem`, Mode `fixed_price`, FP `777.00` | UI-Klick (`smoke-sales-ui-proposal-created.png`) |
| Vorlagen-Wizard Werbeelemente | PM `pm@example.com` | Scope-Note **BL-P4-03f / PO-BLP403F-1**; Werbemittel Spot Tandem; Festpreis **777**; Tandem/Reminder 20/10; Tandem-Einheiten 10 · abgeleitete Sendungen 20 | UI (`smoke-pm-ui-tandem-werbeelemente.png`) |
| Freitext-Prüfung bestätigt | PM | Banner „Freitext-Prüfung erforderlich“ → nach Klick weg; DB `proposal_review.acknowledged_at=2026-09-28T18:54:58+00:00`, `acknowledged_by=3` | UI-Klick |
| **Veröffentlichen** | PM | UI-Button „Veröffentlichen“ geklickt; Detail: Freeze **1 Position(en), N/N 777.00**; Status `published`, `published_at=2026-09-28 18:55:31`; Frozen `materialization_version=3`, Profil `tandem`, Mode `fixed_price`, FP `777.00` | **echter UI-Publish** (`smoke-pm-ui-published-freeze.png`) |
| Übernehmen | Vertrieb | Kunde „Smoke UI Adopt Kunde“, Kampagne „Smoke UI Adopt Kampagne edit“ → `K-2026-00002` | UI-Klick |
| Adoptierte Calc Werbeelemente + Weiterbearbeitung | Vertrieb | Spot Tandem, Festpreis **777** → Edit auf **888** und Speichern; DB Mode `fixed_price`, `fixed_price_nn=888.00`, `origin_standard_offer_version_id=1`; Vorlage unverändert FP **777.00** | UI (`smoke-sales-ui-adopted-tandem.png`) |

## Verifiziert (Pfad B)

- Calc → kundenloser Draft behält Tandem-Profil + Reminder + Festpreis (kein stilles Entfernen)
- Vorlagen-UI zeigt Tandem/Tridem-Medien und Reminder; Scope-Note PO-BLP403F-1
- **Publish über UI-Button** schreibt Materialisierung **v3** mit Profil/Komponenten/Settlement
- Adopt-Hydrate: `tandem` + `fixed_price` + `fixed_price_nn=777` → editierbar auf 888; Vorlage unverändert
- Keine Kopplung Adopt ↔ Vorlage nach Publish

## Grenzen

- Pfad A bleibt als Writer/Feature-Test-Nachweis getrennt dokumentiert
- Calendar-/Budget-Vorlagen und Abbinder weiter außerhalb Scope
- Browser-Smoke deckt die Kombination **Tandem × Festpreis** auf dem UI-Pfad ab; die übrigen drei Kombinationen sowie Misch-/Negativfälle laufen über Pfad A
