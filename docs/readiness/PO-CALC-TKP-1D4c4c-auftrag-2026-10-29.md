# PO-CALC-TKP-1D4c-4c – OA-Preisverwaltung Admin-Backend

**Status:** Feature-Branch / Draft-PR – **STOP vor Merge ohne PO-Abnahme.**  
Keine Admin-UI, kein produktiver Preisimport, kein Deploy, keine Registry-Freigabe.

Base: `6761f47e6519d5ebf385f7e5cf033eff96884ae5` (`origin/main`, PR #198)  
Branch: `feat/po-calc-tkp-1d4c4c-admin-backend`  
Worktree: `dispo-wt-po-calc-tkp-1d4c4c`

## Umgesetzt

| Baustein | Status |
|---|---|
| JSON-Admin-API Preislisten (CRUD Draft, Validate, Activate, Archive, Historie) | ja |
| Targeting-Admin über `OaTargetingDefinitionImporter` + neue Revision | ja |
| Plattform-Admin über `OaPlatformImporter` (kein Auto-Seed Spotify/YT/Deezer) | ja |
| `DraftImportGuard` + `ActivationService` + YearLock (singleton, reentrant) | ja |
| Matrix-Validator (zulässige Paare + Vollständigkeit 41) | ja |
| AuditLogger (`oa_tkp_price_list.*`, `oa_targeting_definition.*`, `oa_platform.*`) | ja |
| Gate `access-administration` (Admin + Management, Spot-Konvention) | ja |
| Feature-/Unit-Tests | ja |
| Admin-UI / Fachbefüllung / Registry | **OUT → D4c-4d / D4d** |

## API-Vertrag (D4c-4d)

Alle Routen unter `can:access-administration`, JSON, Prefix `administration/online-audio/`.

### Preislisten

| Methode | Route | Name |
|---|---|---|
| GET | `/administration/online-audio/preislisten` | `administration.oa-tkp.price-lists.index` |
| POST | `/administration/online-audio/preislisten` | `…store` |
| GET | `/administration/online-audio/preislisten/{id}` | `…show` |
| PUT | `/administration/online-audio/preislisten/{id}` | `…update` |
| POST | `…/{id}/pruefen` | `…validate` |
| POST | `…/{id}/aktivieren` | `…activate` (`lock_version`, optional `dry_run`) |
| POST | `…/{id}/archivieren` | `…archive` |
| GET | `…/{id}/historie` | `…history` |
| DELETE | `…/{id}` | 405 |

Fehler: **422** mit `message`, `errors[]`, ggf. `field_errors[{line,field,message}]`; Optimistic Lock **409**.

### Targetings / Plattformen

- Targetings: index/store/show/historie + `POST …/targetings/{code}/revisionen`
- Plattformen: index/store/show/update/aktivieren/deaktivieren/historie; DELETE 405
- Geo: `category=technisch` (keine neue Enum-Kategorie)

## Revisionslebenszyklus

1. Draft anlegen (nächste `revision_number` / Jahr)  
2. Draft bearbeiten (nur `status=draft`; Active/Archived fail-closed)  
3. Prüfen (`require_complete=true` für Activate)  
4. Aktivieren → `OaTkpPriceListActivationService` mit `archivePrevious=true` + YearLock  
5. Optional: aktive Revision ohne Nachfolger archivieren  
6. Kein Hard-Delete

Draft-Schreiben läuft ausschließlich über `OaTkpPriceListDraftImportGuard` (nicht am ActivationService vorbei).

## Validierung Preismatrix

- Importer-Regeln: `list_tkp`/`minimum_tkp` > 0, max. 4 NK, `min ≤ list`, keine Duplikate, nur OA-Kernmedien  
- Admin zusätzlich: Paare ∈ aktive InventoryMediumRules **oder** Fallback D4c-1-CSV (41 Paare)  
- Activate Admin: **Vollständigkeit Pflicht** (CLI Activate bleibt ohne Matrix-Vollständigkeit – siehe offene Punkte)

## Rechte

- Gate: `access-administration` → Rollen **Admin** und **Management** (wie Spot-Preislisten)  
- **Offen:** Auftragstext „Nur Admins“ vs. Spot-Konvention inkl. Management  
- Aktivierung derzeit dieselbe Gate-Stufe (keine separate Activate-Capability)

## Targeting revisionssicher

- Identity `(code, revision_number)`; Schreibpfad = Importer  
- Preisänderung: bevorzugt `POST …/revisionen` (neue Revision, optional `deactivate_previous`)  
- In-place Upsert derselben Revision = CLI-Importvertrag (Snapshots in Calcs bleiben gepinnt)

## Fachliche Preisfelder (Abnahme, nicht befüllt)

| Werbeform | Liste | Min |
|---|---:|---:|
| Pre-/In-Stream (+ Influencer) | 56/44 | 14/8 |
| Midroll | 80 | 20 |
| DMP / Geo Aufschläge | 25/15 | 5/3 |

Preisjahr **2027** priorisiert; keine Auto-Befüllung 2026/41 Inventare.

## Offene Produktentscheidungen

1. Admin-Rechte nur `Admin` oder weiterhin Admin+Management?  
2. CLI-Activate ebenfalls auf 41er-Vollständigkeit vereinheitlichen?  
3. Geo-Kategorie dauerhaft `technisch` oder Enum erweitern?  
4. Inventar-Befüllung gleicher Medium-Grundpreis auf alle Stream-Inventare?  
5. Separate Activate-Capability?

## OUT

- Admin-UI (D4c-4d)  
- Produktiver Import / Activate auf Staging/Prod  
- Registry `oa_tkp`/`tkp` → bleibt `planned`  
- Engine-/Spot-/SWF-Änderungen (Regression erwartet grün)
