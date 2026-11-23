# EPIC-03-F – Vollständiger Fachdatenbestand – Readiness

**Datum:** 2026-11-23
**Lesung:** lokale Quelle `dispo_mat_core` und Staging `spn26jz7_dispo_staging`, nur lesend
**Schema:** beide Seiten 79 Migrationen
**Status:** Fachlicher Umfang vom PO am 23.11.2026 genehmigt. Technischer Vertrag: [`../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md`](../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md).
**STOP:** kein Export, kein Import, keine Implementierung, kein Schreibvorgang bis zur technischen Abnahme und weiterem PO-GO

Ziel ist ein fachlich nutzbarer Staging-Bestand aus der Quelle. Kalkulationen, Dispoaufträge und ihre abhängigen Bewegungsdaten bleiben ausgenommen. Die drei vorhandenen Radio-Hamburg-Testvorgänge und der Staging-Admin bleiben unverändert. Eine Datenbankkopie ist nicht das Mittel, weil die numerischen IDs bereits auseinanderlaufen: Radio Hamburg ist in der Quelle Inventar 3 und auf Staging Inventar 1.

EPIC-03-B Schema `1.0.0` bleibt historisch für das ausgeführte Radio-Hamburg-Paket. Der genehmigte Gesamtumfang wird nur über Vertrag `2.0.0` umgesetzt.

## 1. Was die Lesung geklärt hat

Beide Seiten enthalten dieselben 75 Tabellen. Methoden, Kategorien, Felddefinitionen, Feldsets und die sechs gemeinsamen E-Mail-Domains sind inhaltlich gleich. Unterschiede liegen im Katalogumfang, in den Preislisten und in den Bewegungsdaten.

Die Quelle hat 14 Inventare, 42 Werbemittel und 202 Regeln. Staging hat daraus bisher 1 Inventar, 27 Werbemittel und 27 Regeln. Spotpreislisten: Quelle 17 Listen mit 1.074 Positionen, Staging 2 Listen mit 144 Positionen. Produktionspreislisten: Quelle 10, Staging 1. Online Audio ist in der Quelle vorhanden und auf Staging leer.

Preisjahre in den Preis- und Produktionstabellen sind ausschließlich 2026 und 2027. Weitere Jahre gibt es nicht. Der eine archivierte Spotstand ist Radio Hamburg 2027, Revision 2, 72 Positionen. Alle übrigen Spotlisten sind aktiv.

Produktionspreise, alle nicht rabattierbar und nicht AE-fähig:

| Inventar | 2026 | 2027 |
|---|---|---|
| MORE Hamburg-Kombi | 600 € aktiv, zusätzlich Revision 2 archiviert | 600 € aktiv |
| MORE Hamburg-Kombi+ | 600 € aktiv | fehlt |
| Radio Hamburg | 600 € aktiv | fehlt |
| ROCK, OLDIE, CARAVAN, ffn, Bollerwagen | je 400 € aktiv | fehlt |

Die früher nicht benannten zwei Produktionslisten sind die archivierte Revision 2 und die aktive Liste 2027 der MORE Hamburg-Kombi. Die Regel-Differenz 201 zu 202 ist die aktive Regel `native_ad` auf `inv_more_kombi_podcast` vom 15.11.2026. Sie liegt außerhalb des bisherigen Spotpakets.

Inventare ohne Spotpreisliste: Online-Audio-Kombi, Podcast-Kombi und die vier Event-Kombis. Online Audio hat stattdessen zwei aktive TKP-Listen 2026 und 2027 mit je 41 Positionen. Social, Events und Gegengeschäft haben Medien und Regeln, aber keine eigenen Preislisten.

## 2. Grenze des bestehenden Imports

EPIC-03-B kann ein Manifest, SHA256, einen Dry-Run, Konflikte vor dem Schreiben, eine Idempotenzprüfung und einen Schutz der Benutzer bereits. Apply gegen die Quelldatenbank ist gesperrt. Preislisten kommen als Entwurf hinein und werden nicht automatisch aktiviert.

Für das neue Ziel reicht das nicht:

- nur acht Inventarcodes und zwei Kategorien
- Online Audio, Social, Events und Gegengeschäft sind ausgeschlossen
- nur aktive Preislisten 2026/2027, archivierte Stände fehlen
- keine OA-Plattformen, kein Targeting, keine TKP-Listen
- Methoden werden nur verglichen, nicht angelegt
- der Abgleich läuft über fachliche Schlüssel, nicht über IDs; das ist beizubehalten

Die passende Erweiterung ist ein neues Paketformat `2.0.0` auf derselben technischen Linie: ein Gesamtpaket, Manifest, SHA256, Dry-Run mit Zeilenbericht, Abbruch bei Konflikt, eine Transaktion, wiederholbar ohne Doppelanlage. Zusätzlich muss der Lauf abbrechen, wenn sich die Anzahl von Benutzern, CRM-Konten, Kalkulationen oder Dispoaufträgen ändert. Es werden keine Zeilen gelöscht, die im Paket fehlen.

## 3. Tabelle-zu-Tabelle-Matrix

Klasse `Fachdaten` kann nach freigegebenem Vertrag übertragen werden. `PO` wird untersucht, aber in diesem Vorschlag nicht übertragen. `Ausschluss` gehört nicht in das Paket. `Gleich` bedeutet: der verglichene Fachschlüssel ist auf beiden Seiten bereits identisch und soll nur bestätigt, nicht überschrieben werden.

| Tabelle | Bedeutung | Quelle | Staging | Für nutzbares Staging | Klasse | Abgleich und Risiko |
|---|---|---:|---:|---|---|---|
| organizations | Mandant | 1 | 1 | ja | Fachdaten | ein Datensatz, Name muss gleich sein, sonst Konflikt |
| calculation_methods | Durchschnitt, Kalender, Festpreis, TKP, freie Position | 5 | 5 | ja | Gleich | Schlüssel `key`; abweichender Inhalt bleibt Konflikt, kein Überschreiben |
| advertising_categories | sechs Oberkategorien | 6 | 6 | ja | Gleich | Schlüssel `key`; Standardmethode ist bereits gleich |
| advertising_media | gesamter Medienkatalog | 42 | 27 | ja | Fachdaten | Schlüssel `code`; 27 vorhandene nur unverändert, 15 neu, Abweichung ist Konflikt |
| advertising_category_calculation_methods | Methoden je Kategorie | 5 | 5 | ja | Fachdaten | Schlüssel Kategorie + Methode + Profil; kein zweites SWF-Update erwarten |
| advertising_medium_calculation_methods | Methoden je Medium | 6 | 0 | ja für OA | Fachdaten | sechs OA-/Native-Zeilen, neu; keine SWF-Zeilen mehr in der Quelle |
| inventories | 8 Spot-Sender/Kombis, OA, Podcast, 4 Events | 14 | 1 | ja | Fachdaten | Schlüssel `code`; 13 neu, Radio Hamburg unverändert |
| inventory_medium_rules | aktive und inaktive Regeln | 202 | 27 | ja | Fachdaten | Schlüssel Inventar + Medium; RH-Regeln unverändert, übrige neu, inklusive inaktiver OA-Regeln |
| price_lists | Spotpreislisten inkl. archivierter Revision | 17 | 2 | ja | Fachdaten | Schlüssel Inventar + Jahr + Revision; gleiche aktive RH-Listen unverändert, nie per ID kopieren |
| price_list_items | Stundenpreise | 1.074 | 144 | ja | Fachdaten | gehören zur Liste; gleicher Fingerabdruck bleibt unverändert |
| production_price_lists | Spotproduktion | 10 | 1 | ja | Fachdaten | Schlüssel Inventar + Art + Jahr + Revision; RH 2026 bleibt, fehlende 2027-Jahre nicht erfinden |
| oa_platforms | Spotify, YouTube, Deezer, smartx, AllMediaDesk | 5 | 0 | ja für OA | Fachdaten | Schlüssel `code`, alle neu |
| oa_platform_medium_rules | Plattform zu OA-Medium | 9 | 0 | ja für OA | Fachdaten | Schlüssel Plattform + Medium |
| oa_targeting_definitions | DMP- und Geo-Aufschlag | 2 | 0 | ja für OA | Fachdaten | Schlüssel `code`; Aufschläge 25 und 15 |
| oa_tkp_price_lists | OA-TKP 2026 und 2027, aktiv | 2 | 0 | ja für OA | Fachdaten | Schlüssel Jahr + Revision |
| oa_tkp_price_list_items | TKP-Positionen | 82 | 0 | ja für OA | Fachdaten | 41 je Jahr, hängen an Liste, Inventar und Medium |
| oa_adserver_surcharges | Adserver-Aufschläge | 0 | 0 | leer mitführen | Fachdaten | leeres Soll ausdrücklich ins Manifest, damit niemand später Daten erwartet |
| field_definitions | Systemfelder für Calc und Dispo | 5 | 5 | bereits da | Gleich | Schlüssel `key` ist gleich |
| field_definition_revisions | Feldrevisionen | 5 | 5 | bereits da | Gleich | nur bestätigen; bei Abweichung Konflikt |
| field_definition_revision_options | Feldoptionen | 0 | 0 | leer | Gleich | nichts zu tun |
| field_rules | eine Feldregel | 1 | 1 | bereits da | Gleich | nur bestätigen |
| field_sets | zwei Feldsets | 2 | 2 | bereits da | Gleich | Schlüssel gleich |
| field_set_versions | Feldset-Versionen | 2 | 2 | bereits da | Gleich | nur bestätigen |
| field_set_version_fields | Felder je Version | 5 | 5 | bereits da | Gleich | nur bestätigen |
| field_set_assignments | Zuordnung Feldset zu Medium | 0 | 0 | leer | Gleich | nichts zu tun |
| users | Benutzer, Rollen, Passwort-Hashes | 8 | 1 | Admin bleibt | PO | nicht übertragen; Staging-Admin unverändert; keine Hashes, Tokens oder Adressen |
| crm_accounts | Kunden und Agenturen | 3.460 | 1 | nein für den Katalog | PO | Staging-Konto ist der Testkunde und bleibt; Salesforce-IDs nicht übertragen |
| crm_account_versions | CRM-Stände | 3.460 | 1 | nein für den Katalog | PO | hängt an Konten und Importen |
| crm_imports | CRM-Importläufe | 12 | 0 | nein | PO | Betriebsdaten eines Imports, mit Benutzerbezug |
| crm_conflicts | offene CRM-Konflikte | 0 | 0 | nein | PO | leer, trotzdem nicht Teil des Fachpakets |
| crm_shared_email_domains | gemeinsame Domains für Abgleich | 6 | 6 | bereits da | PO | Inhalt ist bereits gleich; nicht erneut übertragen |
| calculations | Quell-Kalkulationen, alle Entwurf | 13 | 2 | nein | Ausschluss | Staging-Testkalkulationen bleiben |
| calculation_field_values | Kopfwerte | 4 | 2 | nein | Ausschluss | hängt an Kalkulation |
| calculation_order_discounts | Auftragsebene | 0 | 0 | nein | Ausschluss | Bewegungsdaten |
| calculation_positions | Positionen | 6 | 2 | nein | Ausschluss | hängt an Kalkulation, Inventar und Preisliste |
| calculation_position_components | Komponenten | 0 | 0 | nein | Ausschluss | Bewegungsdaten |
| calculation_position_discounts | Positionsrabatte | 0 | 0 | nein | Ausschluss | Bewegungsdaten |
| calculation_position_field_values | Positionswerte | 12 | 4 | nein | Ausschluss | Bewegungsdaten |
| calculation_position_oa_platform_allocations | OA-Verteilung | 0 | 0 | nein | Ausschluss | Bewegungsdaten |
| calculation_position_oa_targeting_selections | OA-Targeting am Vorgang | 0 | 0 | nein | Ausschluss | Bewegungsdaten |
| calculation_position_planner_entries | Planzeilen | 0 | 0 | nein | Ausschluss | Bewegungsdaten |
| calculation_position_production_lines | Produktionszeilen | 0 | 1 | nein | Ausschluss | die Staging-Zeile gehört zum Testvorgang |
| calculation_position_time_ranges | Zeitfenster | 4 | 2 | nein | Ausschluss | Bewegungsdaten |
| calculation_number_sequences | Nummernkreis | 1 | 1 | Staging behalten | Ausschluss | sonst kollidieren die Testnummern |
| dispo_orders | Quell-Dispoaufträge | 13 | 1 | nein | Ausschluss | Staging-Entwurf bleibt |
| dispo_order_positions | Dispo-Positionen | 25 | 2 | nein | Ausschluss | hängt am Auftrag |
| dispo_order_position_field_values | Positionswerte | 44 | 2 | nein | Ausschluss | Bewegungsdaten |
| dispo_order_field_values | Kopfwerte | 13 | 2 | nein | Ausschluss | Bewegungsdaten |
| dispo_order_comments | Kommentare | 4 | 0 | nein | Ausschluss | Bewegungsdaten |
| dispo_order_uploads | Dateien | 1 | 0 | nein | Ausschluss | Bewegungsdaten und Dateien |
| dispo_order_status_events | Statushistorie | 11 | 0 | nein | Ausschluss | Bewegungsdaten |
| dispo_order_approval_requests | Freigaben | 3 | 0 | nein | Ausschluss | Bewegungsdaten |
| dispo_order_number_sequences | Nummernkreis | 0 | 0 | Staging behalten | Ausschluss | Testnummer bleibt die erste |
| configuration_snapshots | eingefrorene Feldstände | 69 | 7 | nein | Ausschluss | Quelle enthält Vorgangsstände; Staging-Snapshots gehören zu den Testvorgängen |
| configuration_snapshot_sources | Snapshot-Herkunft | 103 | 8 | nein | Ausschluss | hängt am Snapshot |
| configuration_snapshot_source_fields | Snapshot-Felder | 226 | 19 | nein | Ausschluss | hängt am Snapshot |
| configuration_snapshot_source_rules | Snapshot-Regeln | 55 | 5 | nein | Ausschluss | hängt am Snapshot |
| snapshot_field_definitions | eingefrorene Felder | 139 | 14 | nein | Ausschluss | von Testvorgängen referenziert |
| snapshot_field_rules | eingefrorene Regeln | 43 | 4 | nein | Ausschluss | von Testvorgängen referenziert |
| spot_classic_plan_rows | Planzeilen am Vorgang | 16 | 2 | nein | Ausschluss | hängt an Kalkulationspositionen |
| budget_proposals | Budgetvorschläge | 0 | 0 | nein | Ausschluss | hängt an Kalkulation und Benutzer |
| standard_offers | Standardangebote | 0 | 0 | nein | Ausschluss | Angebotsdokumente, nicht Stammdaten |
| standard_offer_versions | Angebotsversionen | 0 | 0 | nein | Ausschluss | hängt an Kalkulation und Snapshot |
| standard_offer_number_sequences | Nummernkreis | 0 | 0 | nein | Ausschluss | Betriebszähler |
| price_list_imports | lokale Importläufe | 3 | 0 | nein | Ausschluss | Benutzer- und Laufhistorie |
| audit_events | Audit | 3.689 | 8 | nein | Ausschluss | Betriebs- und Benutzerhistorie |
| notification_outbox | Benachrichtigungen | 4 | 0 | nein | Ausschluss | Versandzustand |
| sessions | Sitzungen | 1 | 4 | nein | Ausschluss | technische Sitzungen |
| password_reset_tokens | Reset-Tokens | 3 | 0 | nein | Ausschluss | Zugangsdaten |
| cache | Cache | 6 | 10 | nein | Ausschluss | Betriebsdaten |
| cache_locks | Cache-Sperren | 0 | 0 | nein | Ausschluss | Betriebsdaten |
| jobs | Queue | 0 | 0 | nein | Ausschluss | Betriebsdaten |
| job_batches | Queue-Batches | 0 | 0 | nein | Ausschluss | Betriebsdaten |
| failed_jobs | fehlgeschlagene Jobs | 0 | 0 | nein | Ausschluss | Betriebsdaten |
| migrations | Schema-Historie | 79 | 79 | durch Code | Ausschluss | nicht als Daten übertragen |

Fremdschlüssel laufen von den Preis- und Regelzeilen zu Inventar, Medium, Kategorie und Methode, von OA-Positionen zusätzlich zu Plattform und TKP-Liste, von Feldversionen zu Felddefinitionen. Kalkulationen und Dispoaufträge hängen daran, zusätzlich an CRM, Benutzer und Snapshots. Das Paket schreibt nur die Fachdaten-Eltern und danach ihre Kinder. Bewegungsdaten werden nicht mitgezogen, auch wenn ein Fremdschlüssel auf ein übertragenes Inventar zeigt.

## 4. Drei Klassen

**Automatisch übertragbar nach neuem Vertrag:** Organisation, Medien, Kategorie- und Medium-Methoden, alle 14 Inventare, alle 202 Regeln, Spotpreislisten einschließlich der archivierten Radio-Hamburg-Revision, Produktionspreise und der gesamte Online-Audio-Stamm inklusive TKP. Methoden, Kategorien und der Feldkatalog sind bereits gleich und werden nur bestätigt.

**PO-Entscheidung, jetzt nicht übertragen:** Benutzer und der gesamte CRM-Bestand. Die Quelle hat einen Admin, eine Disposition, ein Management und fünf Sales-Benutzer. Staging hat genau einen Admin. Konten sind 3.097 feste Kunden, 356 feste Agenturen, 6 vorläufige Kunden und 1 vorläufige Agentur, jeweils mit Salesforce-Bezug und Versionsstand. Der Staging-Testkunde bleibt. Die gemeinsamen E-Mail-Domains sind schon identisch und werden nicht noch einmal bewegt.

**Ausgeschlossen:** alle Kalkulationen, Dispoaufträge, Positionen, Snapshots, Planzeilen, Nummernkreise, Audits, Benachrichtigungen, Importläufe, Sessions, Tokens, Cache, Jobs und die Migrationshistorie. Die Quell-Dispoaufträge verteilen sich auf 10 Entwürfe, 2 abgeschlossene Aufträge und 1 Sales-Anfrage. Sie bleiben in der Quelle.

## 5. Vorgehen nach Freigabe

1. Vertrag `2.0.0` und Tests im Code. Der bisherige Spotvertrag bleibt für das bereits ausgeführte Paket gültig.
2. Ein Export der Fachdaten aus der Quelle.
3. SHA256 und Manifest prüfen. Das Manifest nennt die ausgeschlossenen Tabellen ausdrücklich.
4. Genau ein Staging-Dry-Run. Der Bericht zeigt je Schlüssel Create, Unchanged oder Konflikt. Ein Konflikt bricht ab.
5. PO-Entscheidung über diesen Bericht.
6. Erst danach Apply in einer Transaktion und, getrennt davon, die Aktivierung der neu angelegten Entwürfe.
7. Rollback bleibt die bestehende Staging-Sicherung vor dem Apply. Es gibt keinen zweiten Schreibpfad und kein Löschen der Testvorgänge.

Neue Preislisten werden wie bisher als Entwurf angelegt. Die Aktivierung bleibt der zweite, eigene Schritt. So wird Staging nutzbar, ohne eine ungeprüfte Statuskopie. Bestehende aktive Radio-Hamburg-Listen bleiben unverändert, wenn der Fingerabdruck gleich ist.

## 6. Offene Entscheidungen

- Ob Benutzer später einzeln auf Staging angelegt werden. Hashes werden dabei nicht kopiert.
- Ob und wann der CRM-Bestand in einem eigenen Paket folgt.
- Ob die Aktivierung aller neuen Listen im selben Fenster wie das Apply liegen soll.
- Fehlende Produktionspreise 2027 außer bei der MORE Hamburg-Kombi bleiben eine fachliche Lücke.
- Social, Events und Gegengeschäft haben keine Preislisten. Der Katalog kann ankommen, eine bepreiste Kalkulation daraus noch nicht.
- Teilraster bleiben: Kombi+ und ffn je 42, Bollerwagen 57 Positionen je Jahr.

## 7. GO / STOP

Der fachliche Umfang ist genehmigt. **STOP** bleibt für Implementierung, Export, Dry-Run, Apply, Aktivierung und jeden Schreibvorgang, bis der technische Vertrag `2.0.0` abgenommen und ein weiteres PO-GO erteilt ist.
