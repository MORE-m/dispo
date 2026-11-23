# EPIC-03-F – Technischer Importvertrag 2.0.0

- **Status:** Draft zur technischen Abnahme. **STOP** vor Implementierung, Export, Dry-Run, Apply, Aktivierung und Merge ohne weiteres PO-GO.
- **Datum:** 2026-11-23
- **PO-Umfang:** genehmigt laut Readiness vom 23.11.2026
- **Basis:** `docs/readiness/EPIC-03-F-full-master-data-readiness-2026-11-23.md`
- **Vorgänger:** EPIC-03-B Schema `1.0.0` bleibt historisch gültig für das ausgeführte Radio-Hamburg-Paket. Dieser Vertrag ersetzt ihn nicht stillschweigend.

## 1. Ziel und Grenze

Ziel ist die vollständige Übernahme aller fachlichen Stammdaten, Katalogdaten, Regeln und Preislisten aus `dispo_mat_core` nach Staging.

Nicht Teil dieses Vertrags:

- Benutzer, Passwörter, Tokens, Sessions
- CRM-Konten, CRM-Versionen, CRM-Importe, CRM-Konflikte, CRM-Domain-Übertragung
- Kalkulationen, Dispoaufträge und alle abhängigen Bewegungsdaten
- Snapshots, Nummernkreise, Audits, Outbox, Jobs, Cache, Migrationshistorie
- unkontrollierte Datenbankkopie und ungeprüfte Übernahme numerischer Quell-IDs

Schutzziele auf Staging:

- Staging-Admin unverändert
- CRM-Testkunde unverändert
- die drei Radio-Hamburg-Testvorgänge unverändert
- bestehende aktive RH-Spotpreislisten und die Produktionsliste 2026 unverändert, wenn der Fingerabdruck gleich ist
- Preislisten-Pins der Testvorgänge bleiben gültig

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

Leere Mengen sind gültig und müssen als leere Arrays im Paket stehen. `oa_adserver_surcharges.json` und leere Feldkatalog-Kinder sind ausdrücklich Teil des Vertrags, damit fehlende Dateien nicht als stiller Scope-Verlust gelesen werden.

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

Ein Importer für `2.0.0` darf ein `1.0.0`-Paket nicht still als Vollbestand lesen. Ein Importer für `1.0.0` darf ein `2.0.0`-Paket nicht akzeptieren.

## 3. Identitäts- und Fremdschlüsselstrategie

Numerische Quell-IDs werden nicht als Ziel-IDs übernommen. Der Abgleich läuft ausschließlich über fachliche Schlüssel. Fremdschlüssel im Paket sind immer fachliche Referenzen, nie Quell-IDs.

| Entität | Fachschlüssel | Referenzen im Paket |
|---|---|---|
| organization | genau ein Datensatz; Abgleich über `name` | — |
| calculation_methods | `key` | — |
| advertising_categories | `key` | `default_calculation_method_key` |
| advertising_media | `code` | `category_key`, optional `default_calculation_method_key` |
| advertising_category_calculation_methods | `category_key` + `method_key` + `engine_profile_key` | — |
| advertising_medium_calculation_methods | `medium_code` + `method_key` + `engine_profile_key` | — |
| inventories | `code` | Organisation implizit der eine Mandant |
| inventory_medium_rules | `inventory_code` + `medium_code` | — |
| price_lists | `inventory_code` + `year` + `revision_number` | Items eingebettet |
| price_list_items | gehören zur Liste; Inhaltsteil des Fingerabdrucks | `hour`, `day_group`, `second_price` |
| production_price_lists | `inventory_code` + `production_type` + `year` + `revision_number` | — |
| oa_platforms | `code` | — |
| oa_platform_medium_rules | `platform_code` + `medium_code` | — |
| oa_targeting_definitions | `code` | — |
| oa_tkp_price_lists | `year` + `revision_number` | Items eingebettet |
| oa_tkp_price_list_items | gehören zur Liste | `inventory_code`, `medium_code`, Positionsfelder |
| oa_adserver_surcharges | `code` + `revision_number`; leer erlaubt | — |
| field_definitions | `key` | — |
| field_definition_revisions | `field_definition_key` + `revision_number` | — |
| field_definition_revision_options | `field_definition_key` + `revision_number` + `value` | — |
| field_rules | `field_set_key` + `field_set_version` + `sort` + Fingerabdruck aus `condition_json`/`action_json` | — |
| field_sets | `key` | — |
| field_set_versions | `field_set_key` + `version` | — |
| field_set_version_fields | `field_set_key` + `version` + `field_definition_key` | — |
| field_set_assignments | `field_set_key` + optional `category_key` / `medium_code` | — |

Fingerabdrücke:

- Spotpreisliste: sortierte Menge `(hour, day_group, second_price)` plus Metadaten `name`, `valid_from`, `version` nur zur Anzeige; Inhaltentscheidung über Items und die Kernfelder der Liste.
- Produktionspreisliste: `unit_price`, `is_discountable`, `is_ae_eligible`, `name`, `version`.
- OA-TKP-Liste: sortierte Item-Menge inklusive Inventar-, Medien- und Preisfelder.

Ziel-IDs entstehen neu. Bereits vorhandene Staging-IDs für Radio Hamburg bleiben. Deshalb bleiben die Pins der Testvorgänge auf denselben Staging-IDs, wenn der Fingerabdruck unverändert ist.

## 4. Tabelle-zu-Tabelle-Importmatrix

| Tabelle | Paket | Aktion | Konflikt wenn |
|---|---|---|---|
| organizations | ja | create oder unchanged | Name abweichend |
| calculation_methods | ja | create wenn fehlend; sonst verify | Inhalt zu `key` abweichend |
| advertising_categories | ja | create wenn fehlend; sonst update nur erlaubter Felder oder unchanged | Namens-/Sort-/Aktiv-Konflikt nach Regel unten |
| advertising_media | ja | create / unchanged | gleicher `code`, anderer Inhalt |
| advertising_category_calculation_methods | ja | create / unchanged | gleicher Schlüssel, anderer Inhalt |
| advertising_medium_calculation_methods | ja | create / unchanged | gleicher Schlüssel, anderer Inhalt |
| inventories | ja | create / unchanged | gleicher `code`, anderer Name/Typ/Aktiv |
| inventory_medium_rules | ja | create / unchanged | gleicher Schlüssel, anderer Inhalt; auch inaktive Regeln |
| price_lists + items | ja | siehe Abschnitt 5 | Fingerabdruck oder Status-Schutz verletzt |
| production_price_lists | ja | siehe Abschnitt 5 | Fingerabdruck oder Status-Schutz verletzt |
| oa_platforms | ja | create / unchanged | gleicher `code`, anderer Inhalt |
| oa_platform_medium_rules | ja | create / unchanged | gleicher Schlüssel, anderer Inhalt |
| oa_targeting_definitions | ja | create / unchanged | gleicher `code`, anderer Inhalt |
| oa_tkp_price_lists + items | ja | siehe Abschnitt 5 | Fingerabdruck oder Status-Schutz verletzt |
| oa_adserver_surcharges | ja, auch leer | create / unchanged | gleicher Schlüssel, anderer Inhalt |
| field_definitions und Kinder | ja | verify; create nur wenn fehlend | Abweichung an vorhandenem Schlüssel |
| users | nein | verboten | jede Änderung bricht den Lauf ab |
| crm_* | nein | verboten | jede Änderung an Konten/Versionen/Importen bricht ab |
| calculations und Kinder | nein | verboten | Anzahl oder Inhalt darf sich nicht ändern |
| dispo_orders und Kinder | nein | verboten | Anzahl oder Inhalt darf sich nicht ändern |
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

Alle 14 Inventare, alle 6 Kategorien, alle 42 Medien und alle 202 Regeln gehören in das Paket, sofern sie in der Quelle liegen. Es gibt keinen stillen Filter auf acht Spot-Inventare, zwei Preisjahre oder Spots/SWF.

## 5. Preisstatus und Radio-Hamburg-Schutz

### 5.1 Export

Exportiert werden alle Spot-, Produktions- und OA-TKP-Preislisten der Quelle unabhängig vom Status `active`, `archived` oder `draft`. Es gibt keine Jahresbeschränkung im Vertrag. Die aktuelle Quelle enthält nur 2026 und 2027; weitere Jahre werden mitexportiert, falls sie später entstehen.

Jedes Preisobjekt trägt:

- `source_status`
- `import_status=draft`
- fachlichen Schlüssel
- Fingerabdruck-relevante Felder und Items

### 5.2 Apply-Phase Inhalt

Der Apply schreibt neue Preisobjekte ausschließlich als `draft`. Er aktiviert und archiviert nicht.

Regeln je fachlichem Schlüssel:

1. Ziel hat denselben Fingerabdruck → `unchanged`. Der bestehende Staging-Status bleibt. Für Radio Hamburg bleiben aktive Listen und Pins damit erhalten.
2. Ziel hat denselben Schlüssel als `draft` und abweichenden Fingerabdruck → `conflict`.
3. Ziel hat denselben Schlüssel als `active` oder `archived` und abweichenden Fingerabdruck → `conflict`. Es wird keine stille neue Revision erzeugt, die den Pin umgeht oder den aktiven Stand verdoppelt.
4. Schlüssel fehlt im Ziel → `create` als `draft` mit Quellrevision und `source_status` im Paket.

Zusätzlicher Pin-Schutz vor Apply und danach:

- Die IDs und Versionen der von `K-2026-00001`, `K-2026-00002` und `DA-2026-00001-01` referenzierten Spot- und Produktionspreislisten müssen unverändert bleiben.
- Ändert sich eine dieser IDs, Versionen oder Fingerabdrücke, bricht der Lauf ab.

### 5.3 Status-Phase

Die Übernahme von `source_status` ist ein eigener, PO-freigegebener Schritt nach dem Apply.

Erlaubt ist nur:

- `draft` → `active`, wenn `source_status=active` und der Fingerabdruck unverändert ist
- `draft` → `archived`, wenn `source_status=archived` und der Fingerabdruck unverändert ist

Nicht erlaubt:

- Statuswechsel an Listen, die bereits `active` oder `archived` sind
- Statuswechsel an Listen, die von den drei Testvorgängen gepinnt sind
- Aktivierung oder Archivierung als Seiteneffekt des Inhalts-Apply

Damit werden historische Revisionen konsistent nachgezogen, ohne unbeabsichtigt zu aktivieren oder zu archivieren.

### 5.4 Erwartete Quelle laut Lesung vom 23.11.2026

Spot:

- 16 aktive Listen
- 1 archivierte Liste: Radio Hamburg 2027 Revision 2, 72 Items

Produktion, alle `spot_production`, nicht rabattierbar, nicht AE-fähig:

| Schlüssel | Quelle |
|---|---|
| Kombi 2026 r1 | 600 € active |
| Kombi 2026 r2 | 600 € archived |
| Kombi 2027 r1 | 600 € active |
| Kombi+ 2026 r1 | 600 € active |
| Radio Hamburg 2026 r1 | 600 € active |
| ROCK / OLDIE / CARAVAN / ffn / Bollerwagen 2026 r1 | je 400 € active |

OA-TKP:

- 2026 r1 active, 41 Items
- 2027 r1 active, 41 Items

Fehlende Produktionsjahre 2027 außer Kombi werden nicht erfunden.

## 6. Konflikt- und Änderungsregeln

Allgemein:

- gleicher Fachschlüssel, gleicher Inhalt → `unchanged`
- Fachschlüssel fehlt → `create`
- gleicher Fachschlüssel, anderer Inhalt → `conflict`, fail-closed
- Paket enthält ausgeschlossene Entität → `conflict`
- Zielzeile fehlt im Paket → kein Löschen, kein Deaktivieren

Kategorien und Medien:

- vorhandene Radio-Hamburg-Medien bleiben `unchanged`, wenn der Inhalt gleich ist
- neue Medien inklusive Social, Events, Gegengeschäft und OA werden angelegt
- abweichender Inhalt an einem bereits importierten Medium ist Konflikt

Regeln:

- aktive und inaktive Regeln werden übertragen
- `inventory_code + medium_code` ist eindeutig
- abweichende Buchungskennzeichen, Längen, Aufschläge oder Flags sind Konflikt

Methoden:

- fehlende Methode darf angelegt werden
- vorhandene Methode mit abweichendem Namen, Sort oder Aktiv-Flag ist Konflikt
- stilles Überschreiben ist verboten

Guard vor und nach dem Plan:

- Anzahl `users` unverändert
- Anzahl `crm_accounts` unverändert
- Anzahl `calculations` unverändert
- Anzahl `dispo_orders` unverändert
- Staging-Admin `role=admin` weiterhin genau der bestehende Datensatz

## 7. Importreihenfolge

1. Organisation
2. Berechnungsmethoden
3. Kategorien
4. Medien
5. Inventare
6. Kategorie-Methoden
7. Medium-Methoden
8. Inventar-Medien-Regeln
9. Felddefinitionen
10. Felddefinitions-Revisionen
11. Felddefinitions-Options
12. Feldsets
13. Feldset-Versionen
14. Feldset-Version-Felder
15. Feldregeln
16. Feldset-Zuordnungen
17. OA-Plattformen
18. OA-Plattform-Medien-Regeln
19. OA-Targeting-Definitionen
20. Spotpreis-Drafts inkl. Items
21. Produktionspreis-Drafts
22. OA-TKP-Drafts inkl. Items
23. OA-Adserver-Aufschläge
24. Guards und Pin-Prüfung
25. getrennte Status-Phase nur nach eigenem PO-GO

Alles läuft in einer Transaktion für den Inhalts-Apply. Die Status-Phase ist eine zweite Transaktion.

## 8. Dry-Run, Idempotenz und Rollback

Dry-Run:

- lädt Paket, prüft Manifest und SHA256
- plant alle Operationen ohne Schreiben
- liefert je Schlüssel `create`, `unchanged` oder `conflict`
- liefert Summen je Entität und die Pin-Schutzprüfung
- bricht bei jedem Konflikt ab

Idempotenz:

- zweiter Dry-Run oder Apply desselben unveränderten Pakets erzeugt keine zusätzlichen Creates
- bereits angelegte Drafts mit gleichem Fingerabdruck bleiben `unchanged`

Rollback:

- vor jedem Apply frische Staging-Sicherung
- Apply nur gegen `--confirm-database` der aktiven Staging-DB
- Apply gegen `dispo_mat_core` und `dispo` bleibt verboten
- bei Fehler Transaktionsabbruch
- kein Löschen der Testvorgänge als Rollback-Mittel
- Wiederherstellung nur über die gesicherte Staging-Datenbank

## 9. Test- und Abnahmekriterien

Code vor dem ersten operativen Lauf:

- Unit-/Feature-Tests für Paket `2.0.0`, Guards, Fingerabdrücke, Konflikte, Idempotenz
- Test, dass `1.0.0`-Importer `2.0.0` ablehnt und umgekehrt
- Test, dass aktive RH-Listen mit gleichem Fingerabdruck `unchanged` bleiben
- Test, dass abweichende aktive Listen konfliktieren statt überschrieben zu werden
- Test, dass Pin-IDs der drei Testvorgänge nach Apply gleich bleiben
- Test, dass Benutzer-, CRM-, Calc- und Dispo-Zähler unverändert bleiben
- Test, dass Status-Phase Drafts aktivieren/archivieren kann, gepinnte und bereits aktive Listen aber nicht anfasst

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
10. Nachweis: 14 Inventare, 42 Medien, 202 Regeln, 17 Spotlisten, 10 Produktionslisten, OA-Stamm vorhanden; Testvorgänge und Admin unverändert

## 10. Implementierungsplan

Nur nach Merge-GO für den Code und getrenntem operativen GO:

1. Neuer Contract `MasterDataTransferContractV2` oder versionierte Vertragsklasse; `1.0.0` unangetastet lassen
2. Exporter um alle Fachdateien, Status und OA erweitern
3. Importer um Planer, Guards und Status-Phase erweitern
4. CLI um Schema-Auswahl oder Auto-Erkennung per Manifest erweitern
5. Feature-Tests gemäß Abschnitt 9
6. Docs und Runbook aktualisieren
7. STOP bis operatives Export-GO

Keine Vermischung mit Benutzeranlage, CRM-Transfer oder Bewegungsdaten.

## 11. STOP-Kriterien

Sofort STOP, wenn eines zutrifft:

- fehlendes oder abweichendes Manifest / SHA256
- Schema nicht `2.0.0`
- Paket enthält Users, CRM, Calcs, Dispo oder Betriebsdaten
- Konflikt an Fachschlüssel oder Fingerabdruck
- Änderung an Staging-Admin, CRM-Testkunde oder den drei Testvorgängen
- Änderung an gepinnten Preislisten-IDs oder -Versionen
- Apply ohne frische Sicherung
- Apply gegen Quelle oder Produktion
- Status-Phase ohne eigenes PO-GO
- Versuch, fehlende Produktionspreise 2027 zu erfinden
- stilles Löschen von Zielzeilen, die nicht im Paket sind

## 12. Verhältnis zu EPIC-03-B

| Punkt | 1.0.0 | 2.0.0 |
|---|---|---|
| Inventare | 8 Spot-Codes | alle Inventare der Quelle |
| Kategorien | Spots + SWF | alle sechs Kategorien |
| OA | ausgeschlossen | vollständig enthalten |
| Social/Events/Barter | ausgeschlossen | Katalog und Regeln enthalten |
| Preisjahre | nur 2026/2027 | alle vorhandenen Jahre |
| Preisstatus im Export | nur active | active, archived, draft |
| Apply-Status | nur draft | nur draft; Status separat |
| RH-Pilot | ausgeführt und historisch | geschützt, nicht neu geschrieben bei Fingerabdruck-Gleichheit |

Die erfolgreiche Radio-Hamburg-Ausführung und der Vertrag 1.0.0 bleiben historische Referenz. Neue Läufe für den Gesamtbestand nutzen ausschließlich 2.0.0.
