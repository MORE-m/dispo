# EPIC-03-F – Vollständige Tabellenklassifikation (Schema 2.0.0)

**Status:** Dokumentiert für Slice 6b / PO-MASTER-AUDIT Nachzug  
**Datum:** 2026-11-26  
**Basis:** Migrationsbestand (74 `Schema::create` + `migrations`) und Vertrag [`EPIC-03-F-master-data-transfer-2-0-0`](../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md)  
**Bezug:** PO-01 (CRM später eigenständig), PO-02 (keine Bewegungsdaten), Slice 6b Apply (nur Whitelist)

## Legende

| Transferklasse | Bedeutung |
|---|---|
| `FACHDATEN` | Inhalt von Slice 6b (Write-Whitelist 2.0.0) |
| `CRM_SPAETER` | Echte CRM-Stammdaten – **PO-01**, **nicht** Slice 6b |
| `BEWEGUNG` | Vorgänge/Snapshots/Planzeilen – nie Stammdatentransfer (PO-02) |
| `BENUTZER` | Users/Zugangsdaten – nicht in 6b |
| `BETRIEB` | Sessions, Cache, Jobs, Audit, Outbox, Importläufe |
| `SCHEMA` | Migrationshistorie – nur per Code-Deploy |

| Transferfähigkeit | Bedeutung |
|---|---|
| `JA_6B` | Übertragbar über Vertrag 2.0.0 Apply |
| `NEIN_6B_SPAETER` | Später eigener Mechanismus (CRM) |
| `NEIN` | Dauerhaft ausgeschlossen vom Stammdatentransfer |
| `GLEICH_NUR` | Bereits beidseitig vorhanden; nur bestätigen / conflict bei Drift |

Schutzmechanismen (kurz):

- `WL` = Write-Whitelist Guard  
- `SNAP` = Protected-State-Snapshot (Counts/Digests Users/CRM/Calcs/Dispo)  
- `PIN` = Preislisten-Pin-Guard  
- `EXCL` = excluded_tables im Manifest  
- `TX` = eine Transaktion + Rollback  
- `NONE` = kein Transferpfad (Schutz durch Nicht-Schreiben)

---

## A) Fachdaten – Slice 6b Whitelist

| Tabelle | Zweck | Transferklasse | Transferfähigkeit | Abhängigkeiten | Ausschlussgrund | Schutz | Arbeitspaket |
|---|---|---|---|---|---|---|---|
| `organizations` | Mandant | `FACHDATEN` | `GLEICH_NUR` | — | — | WL, TX | Slice 6b |
| `calculation_methods` | Berechnungsmethoden | `FACHDATEN` | `GLEICH_NUR` | — | — | WL, TX | Slice 6b |
| `advertising_categories` | Medien-Oberkategorien | `FACHDATEN` | `GLEICH_NUR` | Methoden (Default) | — | WL, TX | Slice 6b |
| `advertising_media` | Werbemittelkatalog | `FACHDATEN` | `JA_6B` | Kategorie | — | WL, TX | Slice 6b |
| `advertising_category_calculation_methods` | Methoden je Kategorie | `FACHDATEN` | `JA_6B` | Kategorie, Methode | — | WL, TX | Slice 6b |
| `advertising_medium_calculation_methods` | Methoden je Medium | `FACHDATEN` | `JA_6B` | Medium, Methode | — | WL, TX | Slice 6b |
| `inventories` | Inventare/Sender/Kombis | `FACHDATEN` | `JA_6B` | Organisation | — | WL, TX | Slice 6b |
| `inventory_medium_rules` | Buchbarkeitsregeln | `FACHDATEN` | `JA_6B` | Inventar, Medium | — | WL, TX | Slice 6b |
| `price_lists` | Spotpreislisten (Kopf) | `FACHDATEN` | `JA_6B` | Inventar | Status nur Draft in 6b | WL, PIN, TX | Slice 6b; Status = Slice 7 |
| `price_list_items` | Spotpreis-Stundenzeilen | `FACHDATEN` | `JA_6B` | `price_lists` | eingebettet | WL, PIN, TX | Slice 6b |
| `production_price_lists` | Produktionspreise | `FACHDATEN` | `JA_6B` | Inventar | fehlende Jahre nicht erfinden | WL, TX | Slice 6b; Pflege = Phase 3 |
| `oa_platforms` | OA-Plattformen | `FACHDATEN` | `JA_6B` | — | — | WL, TX | Slice 6b |
| `oa_platform_medium_rules` | Plattform×Medium | `FACHDATEN` | `JA_6B` | Plattform, Medium | — | WL, TX | Slice 6b |
| `oa_targeting_definitions` | Targeting-Aufschläge | `FACHDATEN` | `JA_6B` | — | — | WL, TX | Slice 6b |
| `oa_tkp_price_lists` | OA-TKP-Listen | `FACHDATEN` | `JA_6B` | Items eingebettet | Draft in 6b | WL, TX | Slice 6b; Status = Slice 7 |
| `oa_tkp_price_list_items` | OA-TKP-Positionen | `FACHDATEN` | `JA_6B` | Liste, Medium, Inventar | — | WL, TX | Slice 6b |
| `oa_adserver_surcharges` | Adserver-Aufschläge | `FACHDATEN` | `JA_6B` | — | leere Menge ok | WL, TX | Slice 6b |
| `field_definitions` | Dyn. Felder | `FACHDATEN` | `GLEICH_NUR` | aktive Revision | — | WL, TX | Slice 6b |
| `field_definition_revisions` | Feldrevisionen | `FACHDATEN` | `GLEICH_NUR` | Felddefinition | — | WL, TX | Slice 6b |
| `field_definition_revision_options` | Feldoptionen | `FACHDATEN` | `JA_6B` | Revision | — | WL, TX | Slice 6b |
| `field_sets` | Feldsets | `FACHDATEN` | `GLEICH_NUR` | aktive Version | — | WL, TX | Slice 6b |
| `field_set_versions` | Feldset-Versionen | `FACHDATEN` | `GLEICH_NUR` | Feldset | — | WL, TX | Slice 6b |
| `field_set_version_fields` | Felder je Version | `FACHDATEN` | `GLEICH_NUR` | Version, Feldrevision | — | WL, TX | Slice 6b |
| `field_rules` | Feldregeln | `FACHDATEN` | `GLEICH_NUR` | Feldset-Version | Mengenvergleich | WL, TX | Slice 6b |
| `field_set_assignments` | Zuordnung zu Medien | `FACHDATEN` | `JA_6B` | Feldset; Remapping Zielidentität | keine Quell-IDs | WL, TX | Slice 6b |

---

## B) CRM – später (PO-01), nicht Slice 6b

| Tabelle | Zweck | Transferklasse | Transferfähigkeit | Abhängigkeiten | Ausschlussgrund | Schutz | Arbeitspaket |
|---|---|---|---|---|---|---|---|
| `crm_accounts` | Kunden/Agenturen (~3460 lokal) | `CRM_SPAETER` | `NEIN_6B_SPAETER` | Versionen, Domains | PO-01: eigener Transfer; 6b-Vertrag `includes_crm=false` | SNAP, EXCL, WL | CRM-Transfer (Phase 1, separat) |
| `crm_account_versions` | CRM-Stände | `CRM_SPAETER` | `NEIN_6B_SPAETER` | Accounts, Imports | wie Accounts | SNAP, EXCL, WL | CRM-Transfer |
| `crm_imports` | Importläufe | `CRM_SPAETER` / `BETRIEB` | `NEIN` | Users | Betriebs-/Benutzerbezug | EXCL, WL | CRM-Transfer Readiness (ggf. neu erzeugen) |
| `crm_conflicts` | offene Konflikte | `CRM_SPAETER` / `BETRIEB` | `NEIN` | Accounts | Betriebszustand | EXCL, WL | CRM-Transfer Readiness |
| `crm_shared_email_domains` | Domain-Abgleich | `CRM_SPAETER` | `NEIN_6B_SPAETER` | — | Staging bereits gleich; kein stilles Rewrite in 6b | EXCL, WL | CRM-Transfer |

---

## C) Benutzer / Zugang

| Tabelle | Zweck | Transferklasse | Transferfähigkeit | Abhängigkeiten | Ausschlussgrund | Schutz | Arbeitspaket |
|---|---|---|---|---|---|---|---|
| `users` | Benutzer, Rollen, Hashes | `BENUTZER` | `NEIN` | — | keine Hash-/Identitätskopie; Staging-Admin bleibt | SNAP, EXCL, WL | Phase 6 Benutzerkonten (manuell/Admin) |
| `password_reset_tokens` | Reset-Tokens | `BENUTZER` / `BETRIEB` | `NEIN` | Users | Zugangsgeheimnisse | EXCL, WL | Betrieb |
| `sessions` | Sitzungen | `BENUTZER` / `BETRIEB` | `NEIN` | Users | technische Sessions | EXCL, WL | Betrieb |

---

## D) Bewegungsdaten Kalkulation (PO-02)

| Tabelle | Zweck | Transferklasse | Transferfähigkeit | Abhängigkeiten | Ausschlussgrund | Schutz | Arbeitspaket |
|---|---|---|---|---|---|---|---|
| `calculations` | Kundenkalkulationen | `BEWEGUNG` | `NEIN` | Users, CRM, Inventare | PO-02 | SNAP, EXCL, WL | — |
| `calculation_field_values` | Kopf-Dynfelder | `BEWEGUNG` | `NEIN` | Calc | PO-02 | EXCL, WL | — |
| `calculation_order_discounts` | Auftragsrabatte | `BEWEGUNG` | `NEIN` | Calc | PO-02 | EXCL, WL | — |
| `calculation_positions` | Positionen | `BEWEGUNG` | `NEIN` | Calc, Inventar, Medium, Preisliste | PO-02; Pins | SNAP, PIN, EXCL, WL | — |
| `calculation_position_components` | Komponenten | `BEWEGUNG` | `NEIN` | Position | PO-02 | EXCL, WL | — |
| `calculation_position_discounts` | Positionsrabatte | `BEWEGUNG` | `NEIN` | Position | PO-02 | EXCL, WL | — |
| `calculation_position_field_values` | Positionsfelder | `BEWEGUNG` | `NEIN` | Position | PO-02 | EXCL, WL | — |
| `calculation_position_oa_platform_allocations` | OA-Verteilung | `BEWEGUNG` | `NEIN` | Position, Plattform | PO-02 | EXCL, WL | — |
| `calculation_position_oa_targeting_selections` | OA-Targeting | `BEWEGUNG` | `NEIN` | Position | PO-02 | EXCL, WL | — |
| `calculation_position_planner_entries` | Planzeilen | `BEWEGUNG` | `NEIN` | Position | PO-02 | EXCL, WL | — |
| `calculation_position_production_lines` | Produktionszeilen | `BEWEGUNG` | `NEIN` | Position, Produktionspreise | PO-02 | EXCL, WL | — |
| `calculation_position_time_ranges` | Zeitfenster | `BEWEGUNG` | `NEIN` | Position | PO-02 | EXCL, WL | — |
| `calculation_number_sequences` | Nummernkreis Calc | `BEWEGUNG` / `BETRIEB` | `NEIN` | — | Staging-Testnummern schützen | SNAP, EXCL, WL | — |
| `spot_classic_plan_rows` | Spot-Planzeilen | `BEWEGUNG` | `NEIN` | Calc-Position | PO-02 | EXCL, WL | — |
| `budget_proposals` | Budgetvorschläge | `BEWEGUNG` | `NEIN` | Calc, Users | PO-02 | EXCL, WL | — |

---

## E) Bewegungsdaten Dispo (PO-02)

| Tabelle | Zweck | Transferklasse | Transferfähigkeit | Abhängigkeiten | Ausschlussgrund | Schutz | Arbeitspaket |
|---|---|---|---|---|---|---|---|
| `dispo_orders` | Dispoaufträge | `BEWEGUNG` | `NEIN` | Users, CRM, Snapshots | PO-02 | SNAP, EXCL, WL | UAT-Bereinigung Phase 6 (eigenes GO) |
| `dispo_order_positions` | Dispo-Positionen | `BEWEGUNG` | `NEIN` | Order, Pins | PO-02 | PIN, EXCL, WL | — |
| `dispo_order_position_field_values` | Positionsfelder | `BEWEGUNG` | `NEIN` | Position | PO-02 | EXCL, WL | — |
| `dispo_order_field_values` | Kopffelder | `BEWEGUNG` | `NEIN` | Order | PO-02 | EXCL, WL | — |
| `dispo_order_comments` | Kommentare | `BEWEGUNG` | `NEIN` | Order | PO-02 | EXCL, WL | — |
| `dispo_order_uploads` | Uploads | `BEWEGUNG` | `NEIN` | Order | PO-02 + Dateien | EXCL, WL | — |
| `dispo_order_status_events` | Statushistorie | `BEWEGUNG` | `NEIN` | Order | PO-02 | EXCL, WL | — |
| `dispo_order_approval_requests` | Freigaben | `BEWEGUNG` | `NEIN` | Order, Users | PO-02 | EXCL, WL | — |
| `dispo_order_number_sequences` | Nummernkreis Dispo | `BEWEGUNG` / `BETRIEB` | `NEIN` | — | Staging schützen | SNAP, EXCL, WL | — |

---

## F) Snapshots / eingefrorene Konfiguration

| Tabelle | Zweck | Transferklasse | Transferfähigkeit | Abhängigkeiten | Ausschlussgrund | Schutz | Arbeitspaket |
|---|---|---|---|---|---|---|---|
| `configuration_snapshots` | Freeze-Stände | `BEWEGUNG` | `NEIN` | Vorgänge | Vorgangsbezug | EXCL, WL | — |
| `configuration_snapshot_sources` | Snapshot-Herkunft | `BEWEGUNG` | `NEIN` | Snapshot | — | EXCL, WL | — |
| `configuration_snapshot_source_fields` | Snapshot-Felder | `BEWEGUNG` | `NEIN` | Source | — | EXCL, WL | — |
| `configuration_snapshot_source_rules` | Snapshot-Regeln | `BEWEGUNG` | `NEIN` | Source | — | EXCL, WL | — |
| `snapshot_field_definitions` | eingefrorene Felder | `BEWEGUNG` | `NEIN` | Snapshots | — | EXCL, WL | — |
| `snapshot_field_rules` | eingefrorene Regeln | `BEWEGUNG` | `NEIN` | Snapshots | — | EXCL, WL | — |

---

## G) Standardangebote / Preislisten-Importläufe

| Tabelle | Zweck | Transferklasse | Transferfähigkeit | Abhängigkeiten | Ausschlussgrund | Schutz | Arbeitspaket |
|---|---|---|---|---|---|---|---|
| `standard_offers` | Standardangebote | `BEWEGUNG` | `NEIN` | Users, Snapshots | keine Stammdaten; PO-STD-V2 | EXCL, WL | ggf. später V2 |
| `standard_offer_versions` | Angebotsversionen | `BEWEGUNG` | `NEIN` | Offer, Calc/Snapshot | — | EXCL, WL | — |
| `standard_offer_number_sequences` | Nummernkreis | `BETRIEB` | `NEIN` | — | Betriebszähler | EXCL, WL | — |
| `price_list_imports` | lokale XLSX-Importläufe | `BETRIEB` | `NEIN` | Users, Preislisten | Laufhistorie | EXCL, WL | — |

---

## H) Betrieb / Infrastruktur

| Tabelle | Zweck | Transferklasse | Transferfähigkeit | Abhängigkeiten | Ausschlussgrund | Schutz | Arbeitspaket |
|---|---|---|---|---|---|---|---|
| `audit_events` | Audit | `BETRIEB` | `NEIN` | Users | Historie Umgebung | EXCL, WL | — |
| `notification_outbox` | Mail-Outbox | `BETRIEB` | `NEIN` | Users/Vorgänge | Versandzustand | EXCL, WL | — |
| `cache` | App-Cache | `BETRIEB` | `NEIN` | — | volatil | EXCL, WL | — |
| `cache_locks` | Cache-Locks | `BETRIEB` | `NEIN` | — | volatil | EXCL, WL | — |
| `jobs` | Queue | `BETRIEB` | `NEIN` | — | volatil | EXCL, WL | — |
| `job_batches` | Queue-Batches | `BETRIEB` | `NEIN` | — | volatil | EXCL, WL | — |
| `failed_jobs` | Failed Jobs | `BETRIEB` | `NEIN` | — | volatil | EXCL, WL | — |
| `migrations` | Schema-Historie | `SCHEMA` | `NEIN` | — | nur Code-Deploy | EXCL | Deploy/Phase 8 |

---

## Abdeckungsnachweis

- Alle 74 per Migration erzeugten Tabellen sind klassifiziert.
- Zusätzlich `migrations` (Laravel-Schemahistorie).
- Slice-6b-Write-Whitelist = Abschnitt A (25 Tabellen inkl. eingebetteter Items).
- CRM laut PO-01 = Abschnitt B, **explizit außerhalb Slice 6b**.
- Keine automatischen Deletes; fehlende Paketzeilen werden nicht gelöscht.
- Natürliche Schlüssel / keine Quell-Primärschlüssel: Vertrag 2.0.0 + Importer V2.

## Meta-Artefakte (Dateien, keine Tabellen) – Slice 6b-H / Slice 7

Die Verarbeitungsvertrag-Erweiterung 2.1.0 führt **keine neue DB-Tabelle** ein. Die Whitelist (25 Tabellen) und die Ausschlussliste bleiben unverändert. Neue Meta-Artefakte liegen ausschließlich als Dateien vor:

| Artefakt | Ort | Zweck | DB-Bezug |
|---|---|---|---|
| Backup-Proof (v2) | frei wählbares Proof-Verzeichnis außerhalb Webroot (`BACKUP_GATE.txt`, `DUMP_META.json`, Dump) | Integrität + Provenienz der Sicherung | keiner |
| Content-Apply-Receipt | `storage/app/master-data-transfer/receipts/content-apply-<id>.json` | signierter Nachweis eines erfolgreichen Inhalts-Apply | keiner |
| Status-Apply-Receipt | `storage/app/master-data-transfer/receipts/price-status-apply-<id>.json` | signierter Nachweis einer erfolgreichen Status-Phase | keiner |
| Import-/Status-Report | `storage/logs/master-data-import-report-*.json` | Report (wie bisher) | keiner |

Die Status-Phase darf innerhalb der Whitelist nur `status`, `published_at`, `archived_at`, `lock_version`, `updated_at` der Tabellen `price_lists`, `production_price_lists`, `oa_tkp_price_lists` per `UPDATE` ändern (Vertrag §16.8).

## Aktualisierung

Bei neuen Migrationen diese Datei und `MasterDataTransferContractV2::{WRITE_WHITELIST_TABLES,EXCLUDED_TABLES}` synchron halten.
