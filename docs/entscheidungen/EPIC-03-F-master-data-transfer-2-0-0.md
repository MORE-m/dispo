# EPIC-03-F – Technischer Importvertrag 2.0.0

- **Status:** Technisch abgenommen mit bedingtem Dokumentations-GO. Nach den drei Präzisierungen unten freigegeben für Merge. **STOP** vor Implementierung, Export, Dry-Run, Apply und Aktivierung.
- **Datum:** 2026-11-23
- **PO-Umfang:** genehmigt laut Readiness vom 23.11.2026
- **Basis:** `docs/readiness/EPIC-03-F-full-master-data-readiness-2026-11-23.md`
- **Vorgänger:** EPIC-03-B Schema `1.0.0` bleibt historisch gültig für das ausgeführte Radio-Hamburg-Paket. Dieser Vertrag ersetzt ihn nicht stillschweigend.
- **Schema-Bezug:** Fachschlüssel entsprechen den Unique-Constraints von `dispo_mat_core` / Staging.

## 1. Ziel und Grenze

Ziel ist die vollständige Übernahme aller fachlichen Stammdaten, Katalogdaten, Regeln und Preislisten aus `dispo_mat_core` nach Staging.

Nicht Teil dieses Vertrags:

- Benutzer, Passwörter, Tokens, Sessions
- CRM-Konten, CRM-Versionen, CRM-Importe, CRM-Konflikte, CRM-Domain-Übertragung
- Kalkulationen, Dispoaufträge und alle abhängigen Bewegungsdaten
- Snapshots, Nummernkreise, Audits, Outbox, Jobs, Cache, Migrationshistorie
- unkontrollierte Datenbankkopie und ungeprüfte Übernahme numerischer Quell-IDs

Schutzziele auf Staging:

- Staging-Admin unverändert in Identität, Rolle, Aktiv-Flag und fachlichen Benutzerfeldern
- CRM-Testkunde unverändert in Identität und Referenzfeldern
- alle vorhandenen Staging-Kalkulationen und Dispoaufträge unverändert in Inhalt und Referenzen
- Preislisten-Pins sämtlicher Staging-Kalkulationen und Dispoaufträge unverändert
- bestehende aktive RH-Spotpreislisten und die Produktionsliste 2026 unverändert, wenn Fingerabdruck und Metadaten gleich sind

## 2. Versionierung und Paketstruktur

| Punkt | Festlegung |
|---|---|
| Format | `dispo-master-data-transfer` |
| Schema | `2.0.0` |
| EPIC | `EPIC-03-F` |
| Vorgänger | Schema `1.0.0` / EPIC-03-B bleibt lesbar und unverändert |
| Paket | ein Gesamtpaket, kein Inventar-Teilpaket als Default |
| Manifest | `manifest.json` mit Format, Schema, Git-SHA, Exportzeit, Counts, Ausschlussliste |
| Prüfsummen | `checksums.sha256` über alle Dateien außer der Prüfsummendatei selbst |
| Daten | nur JSON unter `data/` |

### Pflicht-Dateien

```text
manifest.json
checksums.sha256
data/organization.json
data/calculation_methods.json
data/advertising_categories.json
data/advertising_media.json
data/advertising_category_calculation_methods.json
data/advertising_medium_calculation_methods.json
data/inventories.json
data/inventory_medium_rules.json
data/price_lists.json
data/production_price_lists.json
data/oa_platforms.json
data/oa_platform_medium_rules.json
data/oa_targeting_definitions.json
data/oa_tkp_price_lists.json
data/oa_adserver_surcharges.json
data/field_definitions.json
data/field_definition_revisions.json
data/field_definition_revision_options.json
data/field_rules.json
data/field_sets.json
data/field_set_versions.json
data/field_set_version_fields.json
data/field_set_assignments.json
```

Leere Mengen sind gültig und müssen als leere Arrays im Paket stehen. Fehlende Pflicht-Dateien sind Konflikt.

### Manifest-Pflichtfelder

- `format`, `schema_version=2.0.0`, `epic=EPIC-03-F`
- `git_sha`, `exported_at`, `source_database`
- `includes_users=false`, `includes_crm=false`, `includes_calculations=false`, `includes_dispo_orders=false`
- `includes_online_audio=true`
- `includes_social_events_barter=true`
- `price_status_on_apply=draft`
- `excluded_tables` = vollständige Ausschlussliste aus Abschnitt 4
- `counts` je Datendatei
- `source_price_status_summary` je Preisfamilie: Anzahl active / archived / draft in der Quelle

Ein Importer für `2.0.0` darf ein `1.0.0`-Paket nicht still als Vollbestand lesen. Ein Importer für `1.0.0` darf ein `2.0.0`-Paket nicht akzeptieren. Bestehende `1.0.0`-Tests und -Verhalten bleiben unverändert grün.

## 3. Fachschlüsselmatrix

Numerische Quell-IDs werden nicht als Ziel-IDs übernommen. Paket-Referenzen sind fachlich. Staging-IDs entstehen neu oder bleiben für bereits vorhandene Zeilen erhalten.

Technische Felder sind nie Teil eines Fachvergleichs und nie Teil eines Fingerabdrucks:

- `id`
- `lock_version`
- `created_at`, `updated_at`
- `published_at`, `archived_at` als Zeitstempel; Status selbst wird in der Status-Phase behandelt
- generierte Spalten `active_inventory_id`, `active_year`

| Entität | DB-Unique | Fachschlüssel im Paket | Fachlicher Inhalt | Paket-Referenzen |
|---|---|---|---|---|
| organization | `id` PK; fachlich genau ein Mandant | Abgleich über `name` des einen Datensatzes | `name` | — |
| calculation_methods | `key` | `key` | `name`, `help_text`, `sort`, `is_active` | — |
| advertising_categories | `key` | `key` | siehe erlaubte Updates unten | `default_calculation_method_key` |
| advertising_media | `code` | `code` | `name`, `kind`, `component_profile`, `calculation_method_mode`, `default_length_seconds`, `is_discountable`, `is_ae_eligible`, `is_active`, `sort`, `category_key`, optional Default-Methode | `category_key`, optional `default_calculation_method_key` |
| advertising_category_calculation_methods | `(advertising_category_id, calculation_method_id)` | `category_key` + `method_key` | `engine_profile_key`, `is_active`, `sort` | — |
| advertising_medium_calculation_methods | `(advertising_medium_id, calculation_method_id)` | `medium_code` + `method_key` | `engine_profile_key`, `is_active`, `sort` | — |
| inventories | `(organization_id, code)` | `code` im einen Mandanten | `name`, `type`, `is_active`, `sort`, `logo_path` | Organisation = der eine Mandant |
| inventory_medium_rules | `(inventory_id, advertising_medium_id)` | `inventory_code` + `medium_code` | `is_active`, `booking_code`, `planning_responsibility_key`, `hint_text`, `sort`, `default_length_seconds`, `surcharge_percent`, `is_discountable`, `is_ae_eligible`, `component_calculation_strategy`, `swf_allonge_confirmed_at` | — |
| price_lists | `(inventory_id, year, revision_number)`; zusätzlich `(inventory_id, year, version)` | `inventory_code` + `year` + `revision_number` | Fingerabdruck + Metadaten unten | Items eingebettet |
| price_list_items | `(price_list_id, hour, day_group)` | gehören zur Liste | `hour`, `day_group`, `second_price` | — |
| production_price_lists | `(inventory_id, production_type, year, revision_number)` | `inventory_code` + `production_type` + `year` + `revision_number` | Fingerabdruck + Metadaten unten | — |
| oa_platforms | `code` | `code` | `name`, `is_active`, `sort`, `is_adserver_relevant` | — |
| oa_platform_medium_rules | `(oa_platform_id, advertising_medium_id)` | `platform_code` + `medium_code` | `is_active` | — |
| oa_targeting_definitions | `(code, revision_number)` | `code` + `revision_number` | `name`, `category`, `tkp_surcharge`, `minimum_tkp_surcharge`, `is_active`, `valid_from`, `valid_to` | — |
| oa_tkp_price_lists | `(year, revision_number)` | `year` + `revision_number` | Fingerabdruck + Metadaten unten | Items eingebettet |
| oa_tkp_price_list_items | `(oa_tkp_price_list_id, advertising_medium_id, inventory_id)` | gehören zur Liste | `medium_code`, `inventory_code`, `list_tkp`, `minimum_tkp` | — |
| oa_adserver_surcharges | `(code, revision_number)` | `code` + `revision_number`; leer erlaubt | `name`, `tkp_amount`, `is_active`, `scope_json`, `valid_from`, `valid_to` | — |
| field_definitions | `key` | `key` | `field_type`, `is_system`, `is_key_protected`, `scope`, `applies_to`, `is_active` | aktive Revision nur fachlich als `current_revision` |
| field_definition_revisions | `(field_definition_id, revision)` | `field_definition_key` + `revision` | `label`, `help_text`, `validation_json`, `group_key`, `sort_default`, `reportable` | — |
| field_definition_revision_options | `(field_definition_revision_id, key)` | `field_definition_key` + `revision` + Options-`key` | `label`, `sort`, `is_active` | — |
| field_sets | `key` | `key` | `name`, `is_system`, `applies_to`, `is_assignable` | aktive Version nur fachlich als `active_version` |
| field_set_versions | `(field_set_id, version)` | `field_set_key` + `version` | `status` | — |
| field_set_version_fields | `(field_set_version_id, field_definition_id)` | `field_set_key` + `version` + `field_definition_key` | `sort`, `required_override`, `visible_override`, referenzierte Feldrevision | `field_definition_key` + `revision`; `field_definition_revision_id` ist NOT NULL und wird bei Create sofort aufgelöst |
| field_rules | kein fachliches Unique außer `id` | keine Zeilen-Identität über Fingerabdruck | vollständige, deterministisch sortierte Regelmenge je `field_set_key` + `version` | — |
| field_set_assignments | `(field_set_id, applies_to_process, target_identity)` | `field_set_key` + `applies_to_process` + semantische Zielidentität | `target_layer`, `is_active`, `sort` | `category_key` / `medium_code` je Layer; `target_identity` wird auf Staging neu gebildet |

### 3.1 Feldset-Zuordnungen und `target_identity`

Paket speichert nie Quell-`target_identity`.

Semantik:

- `target_layer=global` → Staging-`target_identity=g`
- `target_layer=advertising_category` → nach Auflösung der Kategorie-Staging-ID `c:{staging_category_id}`
- `target_layer=advertising_medium` → nach Auflösung der Medien-Staging-ID `m:{staging_medium_id}`

Fachlicher Vergleich vor dem Schreiben nutzt `field_set_key` + `applies_to_process` + `target_layer` + aufgelöste Zielschlüssel (`category_key` / `medium_code` / global). Die DB-Unique-Spalte `target_identity` wird erst nach dem Remapping gesetzt.

### 3.2 Feldregeln

`field_rules` hat kein fachliches Unique. Es gibt deshalb keinen künstlichen Schlüssel aus Sort und Fingerabdruck.

Vergleichseinheit ist die vollständige Regelmenge je Feldset-Version:

1. Regeln der Version deterministisch sortieren nach `sort`, danach kanonisierter `condition_json`, danach kanonisierter `action_json`.
2. Mengen von Paket und Ziel vergleichen.
3. Identische Menge → `unchanged`.
4. Zielmenge leer und Paketmenge nicht leer → `create` der vollständigen Menge.
5. Zielmenge vorhanden und abweichend → `conflict`. Keine Teilanlage, kein Löschen, keine Dubletten.

### 3.3 Fingerabdrücke und Metadaten

Fingerabdrücke sind deterministisch und kanonisch JSON-kodiert, Felder sortiert, Dezimalwerte als normierte Strings.

**Spotpreisliste**

- Fingerabdruck: sortierte Items `(day_group, hour, second_price)`
- Metadatenvergleich zusätzlich: `name`, `version`, `valid_from`
- gleicher Schlüssel, anderer Fingerabdruck oder andere Metadaten → `conflict`
- keine stille neue Revision

**Produktionspreisliste**

- Fingerabdruck: `unit_price`, `is_discountable`, `is_ae_eligible`
- Metadatenvergleich zusätzlich: `name`, `version`
- Abweichung → `conflict`

**OA-TKP-Liste**

- Fingerabdruck: sortierte Items `(inventory_code, medium_code, list_tkp, minimum_tkp)`
- Metadatenvergleich zusätzlich: `name`, `version`, `valid_from`
- Abweichung → `conflict`

## 4. Tabelle-zu-Tabelle-Importmatrix

| Tabelle | Paket | Aktion | Konflikt wenn |
|---|---|---|---|
| organizations | ja | create oder unchanged | Name abweichend oder mehr als ein Mandant |
| calculation_methods | ja | create wenn fehlend; sonst verify | Inhalt zu `key` abweichend |
| advertising_categories | ja | create wenn fehlend; sonst nur erlaubte Updates | verbotene Felder oder Namenskonflikt |
| advertising_media | ja | create / unchanged | gleicher `code`, anderer Inhalt |
| advertising_category_calculation_methods | ja | create / unchanged | gleicher Kategorie-/Methodenschlüssel, anderer Inhalt inkl. `engine_profile_key` |
| advertising_medium_calculation_methods | ja | create / unchanged | gleicher Medien-/Methodenschlüssel, anderer Inhalt inkl. `engine_profile_key` |
| inventories | ja | create / unchanged | gleicher `code`, anderer Inhalt |
| inventory_medium_rules | ja | create / unchanged | gleicher Schlüssel, anderer Inhalt |
| price_lists + items | ja | siehe Abschnitt 5 | Fingerabdruck oder Metadaten abweichend |
| production_price_lists | ja | siehe Abschnitt 5 | Fingerabdruck oder Metadaten abweichend |
| oa_platforms | ja | create / unchanged | gleicher `code`, anderer Inhalt |
| oa_platform_medium_rules | ja | create / unchanged | gleicher Schlüssel, anderer Inhalt |
| oa_targeting_definitions | ja | create / unchanged | gleicher `(code, revision_number)`, anderer Inhalt inkl. `minimum_tkp_surcharge` |
| oa_tkp_price_lists + items | ja | siehe Abschnitt 5 | Fingerabdruck oder Metadaten abweichend |
| oa_adserver_surcharges | ja, auch leer | create / unchanged | gleicher Schlüssel, anderer Inhalt |
| field_definitions | ja | create / unchanged; aktive Revision später auflösen | Inhalt abweichend |
| field_definition_revisions | ja | create / unchanged | gleicher `(key, revision)`, anderer Inhalt |
| field_definition_revision_options | ja | create / unchanged | gleicher Options-`key` unter derselben Revision, anderer Inhalt |
| field_sets | ja | create / unchanged; aktive Version später auflösen | Inhalt abweichend |
| field_set_versions | ja | create / unchanged | gleicher `(key, version)`, anderer Inhalt |
| field_set_version_fields | ja | create / unchanged; Revisions-FK bei Create sofort gesetzt | Inhalt oder Revisionsreferenz abweichend; NULL-FK verboten |
| field_rules | ja | Mengenvergleich je Version | abweichende vorhandene Menge |
| field_set_assignments | ja | create / unchanged nach Remapping | gleicher fachlicher Zielschlüssel, anderer Inhalt |
| users | nein | verboten | jede Inhalts- oder Referenzänderung |
| crm_* | nein | verboten | jede Inhalts- oder Referenzänderung |
| calculations und Kinder | nein | verboten | jede Inhalts- oder Referenzänderung |
| dispo_orders und Kinder | nein | verboten | jede Inhalts- oder Referenzänderung |
| configuration_snapshots und Snapshot-Kinder | nein | verboten | — |
| spot_classic_plan_rows | nein | verboten | — |
| budget_proposals | nein | verboten | — |
| standard_offers und Kinder | nein | verboten | — |
| price_list_imports | nein | verboten | — |
| audit_events | nein | verboten | — |
| notification_outbox | nein | verboten | — |
| sessions, password_reset_tokens | nein | verboten | — |
| cache, cache_locks, jobs, job_batches, failed_jobs | nein | verboten | — |
| migrations | nein | nur durch Code-Deploy | — |

Alle Inventare, Kategorien, Medien und Regeln der Quelle gehören in das Paket. Es gibt keinen stillen Filter auf acht Spot-Inventare, zwei Preisjahre oder Spots/SWF.

### Erlaubte Kategorie-Updates

Nur wenn `key` und `name` bereits gleich sind, darf angeglichen werden:

- `is_active`
- `sort`
- `default_calculation_method_id` über `default_calculation_method_key`

Alle übrigen Kategorieänderungen sind Konflikt, insbesondere Namensänderung.

## 5. Preisstatus und Pin-Schutz

### 5.1 Export

Exportiert werden alle Spot-, Produktions- und OA-TKP-Preislisten unabhängig von `active`, `archived` oder `draft`. Es gibt keine Jahresbeschränkung. Die aktuelle Quelle enthält nur 2026 und 2027.

Jedes Preisobjekt trägt `source_status`, `import_status=draft`, Fachschlüssel, Fingerabdruckfelder und Metadaten.

### 5.2 Apply-Phase Inhalt

Neue Preisobjekte entstehen nur als `draft`. Apply aktiviert und archiviert nicht.

Regeln je fachlichem Schlüssel:

1. Ziel hat gleichen Fingerabdruck und gleiche Metadaten → `unchanged`. Staging-Status bleibt.
2. Ziel hat gleichen Schlüssel und abweichenden Fingerabdruck oder abweichende Metadaten → `conflict`, unabhängig vom Status.
3. Schlüssel fehlt → `create` als `draft`.

Es wird keine stille neue Revision erzeugt.

### 5.3 Pin-Schutz

Vor Plan, nach Dry-Run-Planung und nach Apply:

1. Alle Staging-Kalkulationen und Dispoaufträge samt Positionen und Produktionszeilen lesen.
2. Alle referenzierten Spotpreislisten-IDs, Produktionspreislisten-IDs und Versionen erfassen.
3. Dieselben IDs, Versionen, Status und Fingerabdrücke müssen danach unverändert sein.

Das schließt die drei Radio-Hamburg-Testvorgänge ein und gilt ebenso für jeden späteren Staging-Vorgang.

### 5.4 Status-Phase

Eigener PO-Schritt nach Apply.

Die Status-Phase arbeitet ausschließlich mit demselben unveränderten Paket, das bereits Manifest- und SHA256-validiert wurde. Vor jedem Statuswechsel erneut prüfen:

- Paket-SHA256 unverändert
- Fingerabdruck und Metadaten der betroffenen Listen unverändert
- fachliche Identität unverändert
- kein Statuskonflikt zum geplanten Übergang
- keine gepinnte Liste betroffen

Erlaubt nur:

- `draft` → `active`, wenn `source_status=active` und Fingerabdruck sowie Metadaten unverändert sind
- `draft` → `archived`, wenn `source_status=archived` und Fingerabdruck sowie Metadaten unverändert sind

Nicht erlaubt:

- Statuswechsel an bereits `active` oder `archived` Listen
- Statuswechsel an jeder gepinnten Liste
- Aktivierung oder Archivierung als Seiteneffekt des Inhalts-Apply
- Arbeiten gegen ein anderes oder nachträglich verändertes Paket

### 5.5 Erwartete Quelle laut Lesung vom 23.11.2026

Spot: 16 aktive Listen; 1 archivierte Liste Radio Hamburg 2027 Revision 2.

Produktion, alle `spot_production`, nicht rabattierbar, nicht AE-fähig:

| Schlüssel | Quelle |
|---|---|
| Kombi 2026 r1 | 600 € active |
| Kombi 2026 r2 | 600 € archived |
| Kombi 2027 r1 | 600 € active |
| Kombi+ 2026 r1 | 600 € active |
| Radio Hamburg 2026 r1 | 600 € active |
| ROCK / OLDIE / CARAVAN / ffn / Bollerwagen 2026 r1 | je 400 € active |

OA-TKP: 2026 r1 und 2027 r1, je 41 Items, active.

Fehlende Produktionsjahre 2027 außer Kombi werden nicht erfunden.

## 6. Fremdschlüssel und zweite Auflösungsphase

Keine numerische Quell-ID wird geschrieben. Fremdschlüssel-Constraints bleiben aktiv. Es gibt keine vorläufigen NULL-Werte für Pflicht-FKs und kein Deaktivieren von Constraints.

### 6.1 Sofort bei Create in Phase A

`field_set_version_fields.field_definition_revision_id` ist NOT NULL.

Die Revisionsreferenz wird bereits bei der Erstellung über `field_definition_key` + `revision` auf die Staging-ID der zuvor angelegten oder vorhandenen Feldrevision aufgelöst und mitgeschrieben. Eine Create-Zeile ohne gültige Revisions-ID ist verboten.

Dieselbe Regel gilt für alle anderen Pflicht-FKs von Kindzeilen, sobald die Elternzeile in Phase A bereits existiert.

### 6.2 Phase B nur Integrität und nachlaufende Zeiger

Phase B setzt keine verspäteten Pflicht-FKs nach. Sie prüft und setzt nur Zeiger, die im Schema nullable sind oder erst nach vollständiger Kindmenge gelten:

1. `field_definitions.current_revision_id` über `field_definition_key` + `revision`
2. `field_sets.active_version_id` über `field_set_key` + `version`
3. abschließende Integritätsprüfung, dass jede `field_set_version_fields.field_definition_revision_id` zum fachlichen Schlüssel passt
4. `field_set_assignments.target_identity` sowie `advertising_category_id` / `advertising_medium_id` über Layer und fachliche Zielschlüssel
5. Fail-closed-Prüfung aller übrigen Referenzen

Fail-closed, wenn:

- eine Referenz fehlt
- eine Referenz mehrdeutig ist
- die aufgelöste Zielzeile nicht zum erwarteten Fachschlüssel passt
- `current_revision` oder `active_version` im Paket auf eine nicht vorhandene Kindzeile zeigt
- ein Pflicht-FK NULL wäre oder nur durch Constraint-Deaktivierung schreibbar wäre

## 7. Schreib-Whitelist und Schutz ausgeschlossener Bestände

### 7.1 Geschlossene Schreib-Whitelist

Nur diese Fachtabellen dürfen vom Inhalts-Apply geschrieben werden, und nur über den Importpfad:

1. `organizations`
2. `calculation_methods`
3. `advertising_categories`
4. `advertising_media`
5. `advertising_category_calculation_methods`
6. `advertising_medium_calculation_methods`
7. `inventories`
8. `inventory_medium_rules`
9. `price_lists`
10. `price_list_items`
11. `production_price_lists`
12. `oa_platforms`
13. `oa_platform_medium_rules`
14. `oa_targeting_definitions`
15. `oa_tkp_price_lists`
16. `oa_tkp_price_list_items`
17. `oa_adserver_surcharges`
18. `field_definitions`
19. `field_definition_revisions`
20. `field_definition_revision_options`
21. `field_sets`
22. `field_set_versions`
23. `field_set_version_fields`
24. `field_rules`
25. `field_set_assignments`

Die Status-Phase darf innerhalb derselben Whitelist ausschließlich Statusfelder bereits importierter Preislisten ändern, nie Inhalte, IDs oder Pins.

Jeder Schreibversuch außerhalb dieser Whitelist ist STOP.

### 7.2 Ausgeschlossene Geschäftstabellen

Nicht veränderbar, einschließlich Kinder und Referenzen:

- `users`
- `crm_accounts`, `crm_account_versions`, `crm_imports`, `crm_conflicts`, `crm_shared_email_domains`
- `calculations` und alle Calc-Kinder
- `dispo_orders` und alle Dispo-Kinder
- `configuration_snapshots` und Snapshot-Kinder
- `snapshot_field_definitions`, `snapshot_field_rules`
- `spot_classic_plan_rows`
- `budget_proposals`
- `standard_offers`, `standard_offer_versions`, `standard_offer_number_sequences`
- `calculation_number_sequences`, `dispo_order_number_sequences`
- `price_list_imports`
- Preislisten-Pins und Preislisten-Referenzen bestehender Staging-Vorgänge

### 7.3 Technische Tabellen

Ebenfalls vom Import-Schreibpfad ausgeschlossen:

- `sessions`, `password_reset_tokens`
- `audit_events`, `notification_outbox`
- `cache`, `cache_locks`
- `jobs`, `job_batches`, `failed_jobs`
- `migrations`

### 7.4 Kanonische Schutz-Snapshots

Zähler allein reichen nicht.

Vor und nach dem Plan, nach dem Apply und erneut vor der Status-Phase werden kanonische Schutz-Snapshots verglichen:

- `users`: alle fachlichen Spalten außer technischen Zeitstempeln, sortiert nach `id`
- `crm_accounts` und `crm_account_versions`: Identität und Referenzfelder, ohne Secrets
- `calculations` und direkte Kinder: IDs, fachliche Felder, Snapshot- und Preislisten-Referenzen
- `dispo_orders` und direkte Kinder: IDs, fachliche Felder, Snapshot- und Preislisten-Referenzen
- Preislisten-Pins sämtlicher Staging-Vorgänge

Jede Änderung an Inhalt oder Referenz trotz gleicher Anzahl ist Konflikt und bricht ab.

## 8. Konflikt- und Änderungsregeln

- gleicher Fachschlüssel, gleicher fachlicher Inhalt → `unchanged`
- Fachschlüssel fehlt → `create`
- gleicher Fachschlüssel, anderer fachlicher Inhalt → `conflict`
- Paket enthält ausgeschlossene Entität → `conflict`
- Zielzeile fehlt im Paket → kein Löschen, kein Deaktivieren
- Engine-Profil ist Inhalt, nicht Schlüssel; abweichendes Profil bei gleichem Kategorie-/Methoden- oder Medien-/Methodenpaar → `conflict`
- mehrere Targeting-Revisionen desselben `code` sind über `revision_number` erlaubt und müssen einzeln übereinstimmen

Idempotenz:

- wiederholte Exporte derselben Quelle erzeugen paketseitig denselben fachlichen Inhalt
- wiederholte Dry-Runs und Applies desselben unveränderten Pakets erzeugen keine zusätzlichen Creates und keine Updates

## 9. Importreihenfolge

### Phase A – Anlage und Inhaltsabgleich

1. Organisation
2. Berechnungsmethoden
3. Kategorien
4. Medien
5. Inventare
6. Kategorie-Methoden
7. Medium-Methoden
8. Inventar-Medien-Regeln
9. Felddefinitionen ohne finale `current_revision_id`
10. Felddefinitions-Revisionen
11. Felddefinitions-Options
12. Feldsets ohne finale `active_version_id`
13. Feldset-Versionen
14. Feldset-Version-Felder mit sofort aufgelöster `field_definition_revision_id`
15. Feldregeln als Mengen je Version
16. OA-Plattformen
17. OA-Plattform-Medien-Regeln
18. OA-Targeting-Definitionen
19. Spotpreis-Drafts inkl. Items
20. Produktionspreis-Drafts
21. OA-TKP-Drafts inkl. Items
22. OA-Adserver-Aufschläge

### Phase B – nachlaufende Zeiger und Integrität

23. `current_revision_id` setzen
24. `active_version_id` setzen
25. Integrität der bereits gesetzten `field_set_version_fields.field_definition_revision_id` prüfen
26. Feldset-Zuordnungen anlegen oder prüfen, `target_identity` auf Staging neu bilden
27. Referenzintegrität fail-closed prüfen

### Phase C – Schutz und Status

28. Schutz-Snapshots, Whitelist-Prüfung und Pin-Prüfung
29. getrennte Status-Phase nur nach eigenem PO-GO und nur gegen dasselbe SHA256-validierte Paket

Phase A und B laufen in einer Transaktion. Phase C Status ist eine zweite Transaktion.

## 10. Dry-Run, Idempotenz und Rollback

Dry-Run:

- lädt Paket, prüft Manifest und SHA256
- plant Phasen A–C vollständig schreibfrei
- führt eine virtuelle Planung: noch nicht existierende Zielobjekte und ihre späteren Referenzen werden im Plan so aufgelöst, als würden sie in Phase A entstehen
- prüft dabei dieselben fachlichen Constraints und Pflicht-FKs wie der Apply, einschließlich NOT NULL für `field_set_version_fields.field_definition_revision_id`
- liefert je Schlüssel `create`, `unchanged`, `update` nur für erlaubte Kategorie-Metadaten oder `conflict`
- liefert Mengenstatus für Feldregeln
- liefert Pin-, Whitelist- und Schutz-Snapshot-Prüfung
- Pflicht-Testfall: leeres Ziel für OA und neue Inventare muss eine vollständige erfolgreiche Planung ohne Datenbank-Writes ergeben
- bricht bei jedem Konflikt ab

Rollback:

- vor jedem Apply frische Staging-Sicherung
- Apply nur gegen `--confirm-database` der aktiven Staging-DB
- Apply gegen `dispo_mat_core` und `dispo` bleibt verboten
- Fehler → Transaktionsabbruch
- kein Löschen der Testvorgänge als Rollback
- Wiederherstellung nur über die gesicherte Staging-Datenbank

## 11. Verpflichtende Tests vor dem ersten operativen Lauf

1. Alle Fachschlüssel aus Abschnitt 3 gegen Create, Unchanged und Konflikt.
2. Mehrere `oa_targeting_definitions`-Revisionen desselben `code`.
3. Unterschiedliche `engine_profile_key` bei gleichem Kategorie-/Methoden- und Medien-/Methodenpaar als Konflikt.
4. Geänderte Feldregeln: abweichende vorhandene Menge konfliktieren, keine doppelte Anlage.
5. Remapping bei auseinanderlaufenden Quell- und Staging-IDs für Inventare, Medien, Kategorien, Feldrevisionen und Feldset-Zuordnungen.
6. Korrektes Setzen von `field_definitions.current_revision_id` und `field_sets.active_version_id`.
7. `field_set_version_fields.field_definition_revision_id` wird bei Create sofort gesetzt; kein NULL, kein Constraint-Bypass.
8. Virtueller Dry-Run: leeres Ziel für OA und neue Inventare plant vollständig erfolgreich ohne Writes.
9. Erkennung von Inhalts- oder Referenzänderungen trotz identischer Tabellen-Zähler bei Users, CRM, Calcs und Dispo.
10. RH-Preislisten-Pins und historische Revisionen: gleicher Fingerabdruck bleibt, abweichender Inhalt konfliktieren, keine stille neue Revision.
11. Fail-Closed bei fehlender oder mehrdeutiger Referenz.
12. Unveränderte Kompatibilität von Vertrag und Tests für Schema `1.0.0`.
13. Idempotenz: zweiter Export/Import desselben Stands ohne zusätzliche Creates.
14. Status-Phase arbeitet nur gegen dasselbe SHA256-Paket und fasst gepinnte sowie bereits aktive/archivierte Listen nicht an.
15. Schreibversuch außerhalb der Whitelist wird abgewiesen.

Operative Abnahme nach Implementierungs-GO:

1. Export aus `dispo_mat_core`
2. SHA256 und Manifest
3. genau ein Staging-Dry-Run
4. Bericht gegen diese Matrix und die Quellcounts der Readiness
5. separates PO-GO für Apply
6. Apply
7. Idempotenz-Recheck
8. separates PO-GO für Status-Phase
9. Status-Phase
10. Nachweis: vollständiger Fachkatalog, OA-Stamm, geschützte Staging-Vorgänge und Admin unverändert

## 12. Implementierungsplan

Nur nach Merge-GO für den Code und getrenntem operativen GO:

1. Versionierte Vertragsklasse für `2.0.0`; `1.0.0` unangetastet lassen
2. Exporter um alle Fachdateien, Status, OA und Feldkatalog erweitern
3. Importer mit Phasen A–C, Schutz-Snapshots und Status-Phase
4. CLI per Manifest-Schema unterscheiden
5. Feature-Tests gemäß Abschnitt 11
6. Docs und Runbook aktualisieren
7. STOP bis operatives Export-GO

## 13. STOP-Kriterien

Sofort STOP, wenn eines zutrifft:

- fehlendes oder abweichendes Manifest / SHA256
- Schema nicht `2.0.0`
- Paket enthält Users, CRM, Calcs, Dispo oder Betriebsdaten
- Konflikt an Fachschlüssel, Fingerabdruck, Metadaten oder Regelmenge
- Änderung an Staging-Admin, CRM, Kalkulationen, Dispoaufträgen oder Pins
- fehlende oder mehrdeutige Referenzauflösung
- Apply ohne frische Sicherung
- Apply gegen Quelle oder Produktion
- Status-Phase ohne eigenes PO-GO
- Versuch, fehlende Produktionspreise 2027 zu erfinden
- stilles Löschen von Zielzeilen, die nicht im Paket sind

## 14. Verhältnis zu EPIC-03-B

| Punkt | 1.0.0 | 2.0.0 |
|---|---|---|
| Inventare | 8 Spot-Codes | alle Inventare der Quelle |
| Kategorien | Spots + SWF | alle sechs Kategorien |
| OA | ausgeschlossen | vollständig enthalten |
| Social/Events/Barter | ausgeschlossen | Katalog und Regeln enthalten |
| Preisjahre | nur 2026/2027 | alle vorhandenen Jahre |
| Preisstatus im Export | nur active | active, archived, draft |
| Apply-Status | nur draft | nur draft; Status separat |
| Abweichender Preisinhalt | teils neue Draft-Revision | stets Konflikt |
| RH-Pilot | ausgeführt und historisch | geschützt bei Gleichheit |

Die erfolgreiche Radio-Hamburg-Ausführung und der Vertrag 1.0.0 bleiben historische Referenz. Neue Läufe für den Gesamtbestand nutzen ausschließlich 2.0.0.

## 15. Offene Punkte nach dieser Nachbesserung

Fachlich offen, nicht blockierend für die technische Vertragsabnahme:

- ob Benutzer später einzeln auf Staging angelegt werden
- ob und wann CRM in einem eigenen Paket folgt
- ob Status-Phase im selben operativen Fenster wie Apply freigegeben wird
- fehlende Produktionspreise 2027 außer Kombi
- Social, Events und Gegengeschäft ohne eigene Preislisten

Technisch durch diesen Vertrag festgelegt und nicht mehr offen:

- Fachschlüssel gemäß Unique-Constraints
- Pflicht-FK `field_set_version_fields.field_definition_revision_id` bei Create
- virtuelle Dry-Run-Planung
- Schreib-Whitelist und Schutz-Snapshots
- Status-Phase nur gegen dasselbe SHA256-Paket
- abweichende Preislisteninhalte als Konflikt
