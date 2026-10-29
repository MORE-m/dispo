# PO-CALC-TKP-1D4c-4c – OA-Preisverwaltung Admin-Backend

**Status:** Feature-Branch / Draft-PR #199 – **STOP vor Merge ohne PO-Abnahme.**  
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
| **Gemeinsame** Activate-Vollständigkeit (Admin + CLI) im `ActivationService` | ja (Review-Nachzug) |
| Matrix-SoT = aktive `InventoryMediumRules` (kein CSV-Fallback) | ja (Review-Nachzug) |
| AuditLogger (`oa_tkp_price_list.*`, `oa_targeting_definition.*`, `oa_platform.*`) | ja |
| Gate `manage-oa-tkp-master-data` (**Admin-only**, auch Lesen) | ja (Review-Nachzug) |
| Feature-/Unit-Tests inkl. Management-403 | ja |
| Admin-UI / Fachbefüllung / Registry | **OUT → D4c-4d / D4d** |

## Rechtevertrag (verbindlich)

| Rolle | OA Lesen | OA Schreiben / Validate / Activate / Archive |
|---|---|---|
| **Admin** | ja | ja |
| **Management** | **nein** (restriktive Variante) | **nein** |
| Sales / Dispo / ProductManagement | nein | nein |
| Gast | nein | nein |

- Gate: `manage-oa-tkp-master-data` → `User::canManageOaTkpMasterData()` → nur `Role::Admin`
- Middleware zusätzlich auf der OA-Routengruppe; Controller `authorize` identisch
- Spot-/Produktionspreis-Admin unverändert über `access-administration` (Admin+Management)
- Keine separate Activate-Capability in v1

## API-Vertrag (D4c-4d)

Routen unter `can:access-administration` **und** `can:manage-oa-tkp-master-data`, JSON,
Prefix `administration/online-audio/`.

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

Fehler: **422** mit `message`, `errors[]`, ggf. `field_errors[{line,field,message}]`; Optimistic Lock **409**; Rechte **403**.

### Targetings / Plattformen

- Targetings: index/store/show/historie + `POST …/targetings/{code}/revisionen`
- Plattformen: index/store/show/update/aktivieren/deaktivieren/historie; DELETE 405
- Geo: `category=technisch` (keine neue Enum-Kategorie)

## Revisionslebenszyklus

1. Draft anlegen (nächste `revision_number` / Jahr)  
2. Draft bearbeiten (nur `status=draft`; Active/Archived fail-closed)  
3. Prüfen (Admin-Endpoint; gleiche Paar-SoT wie Activate)  
4. Aktivieren → **nur** `OaTkpPriceListActivationService` (+ YearLock; Admin mit `archivePrevious=true`)  
5. Optional: aktive Revision ohne Nachfolger archivieren  
6. Kein Hard-Delete

## Aktivierungsvalidierung (Admin = CLI)

Gemeinsam in `OaTkpPriceListActivationService::assertActivatable`:

1. Es müssen **aktive** OA-`InventoryMediumRule`-Paare existieren (Kernmedien).  
   Fehlen sie → **fail-closed** (kein CSV-/Dateifallback).
2. Jedes Item: zulässiges Kernmedium, aktive Zuordnung, keine Duplikate,
   `list_tkp`/`minimum_tkp` > 0, max. 4 NK, `min ≤ list`.
3. Jedes erforderliche Paar muss in der Revision vorkommen (Vollständigkeit).
4. Fehler → TX-Rollback; YearLock freigegeben; Historie/Active unverändert.

Herkunft der „41er“-Matrix: operative Stammdaten (`inventory_medium_rules` ×
`OaTkpCoreMedia`). Typisch 41 Paare nach Katalog/Matrix-Import – Anzahl folgt
den aktiven Rules, nicht einer Readiness-CSV.

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

Preisjahr **2027** priorisiert; keine Auto-Befüllung 2026/Inventare.

## Offene Produktentscheidungen (verbleibend)

1. Geo-Kategorie dauerhaft `technisch` oder Enum erweitern?  
2. Inventar-Befüllung gleicher Medium-Grundpreis auf alle Stream-Inventare?  
3. Management später lesend freigeben? (aktuell nein)

Erledigt durch Review-Nachzug: Admin-only; CLI/Admin Activate-Konsistenz; kein CSV-Fallback.

## OUT

- Admin-UI (D4c-4d)  
- Produktiver Import / Activate auf Staging/Prod  
- Registry `oa_tkp`/`tkp` → bleibt `planned`  
- Engine-/Spot-/SWF-Änderungen (Spot-Rechte unverändert)
