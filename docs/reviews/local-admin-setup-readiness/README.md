# Review: lokale Admin-Einrichtung nach Leerstand

Stand: 13. Oktober 2026
Art: lesende Prüfung und Einrichtungsplan, danach **ausgeführt** (Abschnitt 8) und per Browser-Smoke geprüft (Abschnitt 9). Technische lokale Prüfung, kein Deploy.
Basis: `origin/main` / `dispo-main` @ `11dcc522608965b82b3204de87dad23f0d975631`
Merge: PR [#139](https://github.com/MORE-m/dispo/pull/139)
Post-Merge-CI: [38433789254](https://github.com/MORE-m/dispo/actions/runs/38433789254) SUCCESS
Worktree: `docs/local-admin-setup-readiness` / `dispo-wt-docs-local-admin-setup-readiness`
Geprüfte DB: lokale MySQL **`dispo_mat_core`** @ `127.0.0.1:3306`
Admin-UI: Separatserver `http://127.0.0.1:8073` (danach beendet). Port **8000**, `.env` von `dispo-main` und bestehende Worktrees unberührt.

Code auf `main` bleibt umgesetzt. Abschnitte 1–7 halten den Stand **vor** der Neueinrichtung fest. Abschnitt 8 ist die Einrichtung, Abschnitt 9 der Smoke. Abschnitt 10 ist der getrennte Nachzug vom 14. Oktober 2026. Die Ursache des damaligen Leerstands ist weiterhin unbekannt und gilt nicht als behoben. Historische Dumps wurden nicht zurückgespielt.

### Abgrenzung

| Stand | Bedeutung |
|---|---|
| Frühere Feature-Abnahmen (Trailer `BL-P5-01a`, Spotproduktion `BL-P5-02a`, Salesforce `BL-P2-03a`, 8-Inventar-Smoke) | damaliger Bestand; nicht widerrufen; machen `BL-P2-03`, `BL-P5-01` und `BL-P5-02` nicht vollständig; `CRM-004` bleibt offen |
| Browser-Abnahme PR #136/#137/#138, 13. Oktober 2026, Kopie `dispo_accept_crm_calc_20261013` | synthetische Enablement-Prüfung, während `dispo_mat_core` leer war; keine Bestandsabnahme; Bericht bleibt im eigenen Worktree und wird hier nicht übernommen |
| Dieser Bericht | Neueinrichtung und Smoke auf der operativen `dispo_mat_core` danach |

## Urteil

| Ebene | Stand |
|---|---|
| Schema / Migrationen | **vollständig** (62 Dateien = 62 Zeilen in `migrations`, keine fehlende Datei) |
| Operativer Katalog | **leer**: 0 Inventare, 0 Werbemittel, 0 Kombinationsregeln, 0 Spotpreislisten, 0 Produktionspreislisten |
| Admin reicht allein für Buchbarkeit | **nein** (`kind`, `component_profile`, `engine_profile_key` sind keine freien Admin-Felder) |
| Kleinster späterer Schreibweg | vorhandener Initialkatalog-Seeder, danach Matrix-Importer, dann Admin für Trailer-Länge/Aufschlag und Produktionspreise |
| Spotpreise | vorhandener Artisan-Adapter, **nicht** die Admin-Excel-Seite |
| Salesforce | Admin-Import vorhanden; Parser kennt die vier bestätigten Typen; Seite zeigt den Typvertrag unvollständig |
| Calc→Dispo-Smoke / Deploy | **nicht** Teil dieses Plans |

Die Urteilstabelle ist der Plan vor der Ausführung. Der ausgeführte Stand steht in Abschnitt 8 und 9.

## 1. Ausgangsbestand

Leere Tabellen und fehlende Migrationen sind getrennt. Alle unten genannten Tabellen **existieren**. Leer heißt Zeilenzahl 0.

| Bereich | Anzahl | Einordnung |
|---|---:|---|
| `migrations` | 62 | vollständig, inkl. CRM `2026_10_10_160000_…` |
| Benutzer | 8 | Rollen vorhanden; siehe unten. Keine Kontaktdaten in diesem Bericht |
| `organizations` | 0 | keine eigene Admin-Seite; erste Inventaranlage legt „more Marketing“ an |
| Oberkategorien | 6 | aktiv, angelegt 11.10.2026 |
| Berechnungsmethoden | 5 | systemdefiniert, alle katalogaktiv |
| Kategorie-Methodenzuordnungen | 3 | nur `spots`: average, calendar, fixed_price, jeweils Profil `spot_classic` |
| Werbemittel-Methodenzuordnungen | 0 | |
| Inventare | 0 | |
| Werbemittel | 0 | |
| `inventory_medium_rules` | 0 | |
| Felddefinitionen / Revisionen | 5 / 5 | Systemfelder |
| Feldsets | 2 | beide Version 1 **active** (`system_calculation_core`, `system_dispo_order_core`) |
| Feldset-Zuordnungen | 0 | für die Systemkerne nicht erforderlich (`is_assignable=0`) |
| Feldregeln | 1 | `period_open=false` verlangt `position_flight_period` |
| Spotpreislisten / Items / Importe | 0 / 0 / 0 | |
| Produktionspreislisten | 0 | |
| Kalkulationen / Positionen / Dispo | 1 / 0 / 0 | ein Entwurf ohne Positionen, siehe Fensterhinweis |
| CRM-Accounts / Importe | 5 / 9 | kein vollständiger Salesforce-Bestand, siehe Fensterhinweis |
| Standardangebote / Audit | 0 / 23 | Audit aus dem Fenster, nicht aus dem früheren Katalog |

### Benutzer und Rollen

Aggregiert, ohne Namen oder Adressen:

| Rolle | Anzahl | Dispo-Flag `can_view_dispo_orders` | Sonderfreigabe |
|---|---:|---|---|
| admin | 1 | 0 | 0 |
| disposition | 1 | 0 | 0 |
| management | 1 | 0 | 0 |
| sales | 5 | 0 | 0 |

Sieben Konten entsprechen dem lokalen Beispielschema. Ein Vertriebskonto liegt außerhalb davon und wird hier nicht identifiziert. `can_view_dispo_orders=0` sperrt die Kernrollen nicht: Admin, Vertrieb, Disposition und Geschäftsführung dürfen Dispoaufträge unabhängig von diesem Flag sehen. Administration dürfen Admin und Geschäftsführung. CRM-Import ebenso. Es gibt keine Admin-Seite zum Anlegen von Benutzern. `DevUserSeeder` darf bei einer Neueinrichtung **nicht** erneut laufen: er überschreibt Name, Rolle und Passwort bestehender Beispielkonten.

### Bereits vorhandene Konfiguration

Oberkategorien, alle aktiv: `spots`, `special_advertising_formats`, `online_audio`, `social_online`, `events_promotion`, `barter`.

Methoden: `average`, `calendar`, `fixed_price`, `tkp`, `free_position`.

Nur `spots` hat Zuordnungen, Default ist `average`:

| Methode | `engine_profile_key` | Registry laut Admin-UI |
|---|---|---|
| average | `spot_classic` | released / v1; zusätzlich Registry-Paar `swf_trailer` released / v1, **ohne** Kategoriezuordnung |
| calendar | `spot_classic` | released / v1 |
| fixed_price | `spot_classic` | **planned**, nicht ausführbar |

`special_advertising_formats` hat keine Methodenzuordnung und keinen Default. `crm_shared_email_domains` enthält die sechs Migrations-Freemail-Domains (gmail, gmx, outlook, hotmail, yahoo, googlemail). Das ist Migrationssaat, kein CRM-Bestand.

System-Feldsets sind aktiv und reichen für den Kalkulations- und Dispo-Kern. Zusätzliche Feldset-Zuordnungen sind für diese Kerne keine Einrichtungsvoraussetzung.

### Fensterhinweis (nicht dieser Review)

Zwischen der ersten Lesung und dem Admin-Check sind auf der laufenden Port-8000-Instanz Datensätze entstanden. Der Separatserver startete erst danach und hat keinen Katalog, keine Preislisten und keinen Import geschrieben.

| Zeitpunkt | Beobachtung |
|---|---|
| 19:38–19:39 | 9 CRM-Importvorgänge. Drei Uploads tragen den SHA-Präfix des früher abgenommenen Exports und blieben `uploaded` mit 0 Zeilen. Ein Vorgang ist `applied`: 4 gültige Zeilen. |
| danach | 5 Accounts: 4 Kunden nicht vorläufig, 1 Kunde vorläufig. Das ist kein 3453-Zeilen-Bestand. |
| 19:41 | Kalkulation `K-2026-00001`, Status Entwurf, 0 Positionen. Kundenname vorhanden, hier nicht wiedergegeben. |

Diese Sätze bleiben stehen. Sie sind nicht der operative Katalog und keine Freigabe, den historischen Export erneut anzuwenden.

## 2. Admin-Einrichtungswege

Rolle für alle genannten Seiten: `access-administration` (Admin oder Geschäftsführung). CRM-Import zusätzlich `import-crm-accounts` (dieselbe Rollenmenge). Geprüft als lokaler Admin auf Port 8073.

| Bereich | Route | Anlegbar in der UI | Pflicht | Abhängigkeit davor | Aktivierung / Änderung | UI reicht aus dem Ist-Zustand |
|---|---|---|---|---|---|---|
| Inventare | `/administration/inventare`, `/neu` | Name, Kurzcode, Typ Sender/Kombi, Sortierung, Logo-Pfad | Name, Kurzcode, Typ | keine Stammdaten; erste Anlage erzeugt die Organisation | Checkbox „Aktiv anlegen“ ist gesetzt und nicht abwählbar. Kurzcode und Typ danach fest. Deaktivieren statt Löschen | Liste ist leer, Formular ist da. Aktiv allein ist keine Buchbarkeit |
| Werbemittel | `/administration/katalog/werbemittel/neu` | Name, Code, Oberkategorie, Länge, Sortierung | Name, Code, Oberkategorie | aktive Oberkategorie | Länge vorbelegt 30. Rabatt und AE sind angehakt und nicht abwählbar. Anlage ist aktiv. `kind` wird serverseitig abgelehnt | Formular da, Medienliste leer. **Neue Medien bleiben `kind=null` und damit nicht buchbar** |
| Kategorien | `/administration/katalog/oberkategorien` | Key, Name, Sortierung, Methoden-Desired-State | Key, Name | Methoden existieren bereits | Default nur aus freigegebenen Zuordnungen mit Profil. Neue Zuordnung speichert `engine_profile_key=null` und lässt bestehende Profile stehen | Die sechs Kategorien sind da. Profil `swf_trailer` lässt sich hier nicht setzen |
| Berechnungsmethoden | `/administration/katalog/berechnungsmethoden/{id}` | nur Name, Hilfetext, Sortierung | Name, Sperrversion | — | Key, Profil, Registry und Zuordnungen sind gesperrt. Deaktivieren nur ohne aktive Zuordnung | kein Anlegeformular. Registry ist read-only |
| Kombinationstabelle | `/administration/kombinationen/neu` | Inventar, Werbemittel, Buchungskennzeichen, Einplanung, Hinweis, Länge, Aufschlag, Strategie | für aktive Zeile: Inventar, Werbemittel, Buchungskennzeichen, Einplanung | aktives Inventar und aktives Werbemittel | Aktiv, Rabatt und AE sind angehakt und nicht abwählbar. Paar danach fest. Länge vorbelegt **30**, Aufschlag **0**, Einplanung Disposition, Strategie gemeinsame Gesamtlänge | Dropdowns sind leer. Formular legt keine Trailer-Regel fail-closed an |
| Spotpreislisten | `/administration/preislisten`, `/import` | Entwurf je Inventar/Jahr, manuelle Positionen, Excel nach Kanonvertrag | Inventar, Jahr, Name | Inventar muss existieren | Import erzeugt Entwürfe, keine Auto-Aktivierung. Aktivieren ist ein eigener Schritt | UI-Excel erwartet `inventory_code, hour, day_group, second_price`. Das MORE-Workbook ist dieser Vertrag nicht |
| Produktionspreise | `/administration/produktionspreise/neu` | Inventar, Art, Jahr, Name, Nettopreis | Inventar, Jahr, Name, Preis | Inventar | Art nur Spotproduktion. Rabatt und AE starten aus und sind auf der Anlagemaske nicht schaltbar. Entwurf, dann Aktivieren. Jahr/Inventar/Art danach fest. Kopie erzeugt einen neuen Entwurf | Inventar-Dropdown leer. Für die acht bestätigten Werte reicht die UI, sobald die Inventare existieren |
| Salesforce-CSV | `/administration/crm/import` | Upload, Prüfung, Apply mit Fingerprint | CSV-Datei | keine Katalogdaten | Apply schreibt Accounts und kann Meridian nachtragen | Seite vorhanden. Hinweistext nennt nur Kunde/Agentur |

Eine Kombinationszeile ist operativ nutzbar für Buchungskennzeichen und Einplanung. Sie ist **keine** Kalkulierbarkeit. Buchbar für neue Positionen wird ein Werbemittel erst, wenn `kind` gesetzt ist, das Engine-Profil der aktiven Methode zu diesem `kind` passt und das Registry-Paar `released` ist. Inventarregel und aktive Preisliste prüft der Laufzeitpfad zusätzlich.

## 3. Was die UI nicht setzen kann

Neue Werbemittel speichern `kind=null`. Ein gesetztes `kind` weist die Admin-Anlage ab. Ohne `kind` bleibt das Medium für neue Kalkulationen gesperrt, auch wenn die Oberkategorie `spots` bereits `average`/`calendar` mit `spot_classic` trägt.

`component_profile` (Tandem, Tridem) kommt in keinem Admin-Controller vor. Die Zuordnung entsteht nur im Initialkatalog-Bootstrap.

Neue Kategorie-Zuordnungen schreiben `engine_profile_key` ausdrücklich `null`. Bestehende Profile bleiben beim Speichern unverändert. `swf_trailer` an `special_advertising_formats` / `average` legt nur der Bootstrap an, und die Trailer-Migration tut das nur, wenn das Medium `trailer_station_voice` schon existiert. Auf dem leeren Katalog ist diese Zuordnung deshalb nicht entstanden. Die Migration selbst ist angewendet; sie hatte nichts zu schalten.

`fixed_price` + `spot_classic` ist in der Registry `planned`. Die Kategorie zeigt das als „Geplant“. Eine aktive Zuordnung macht Festpreis nicht kalkulierbar. TKP und freie Position haben kein Registry-Paar.

Trailer-Aufschlag: fehlt das Feld im Payload, bleibt er bei `kind=swf_trailer` `null` (nicht konfiguriert, fail-closed) und wird sonst `0`. Die Anlagemaske sendet immer Länge 30 und Aufschlag 0. Für einen echten Trailer wäre das eine ausdrückliche 30-Sekunden/0-Prozent-Regel und nicht der bestätigte Wert 20 Sekunden / 30 Prozent. Diese Maske ist für die vier Trailer-Regeln der falsche Weg.

## 4. Datenquellen

Vorhanden heißt auffindbar und vom jeweiligen Importer lesbar. Nichts davon wurde angewendet. Eine vorhandene Datei ist kein neuer freigegebener operativer Export. Synthetische Spotpreise werden nicht empfohlen. Die 75 leeren Basiszellen des MORE-Workbooks bleiben Fehlstellen.

| Quelle | Ort | Eignung | Für die Neueinrichtung |
|---|---|---|---|
| Initialkatalog | `InitialCatalogMatCoreSeeder` | 14 Inventare, 42 Werbemittel, setzt `kind` und `component_profile`, legt `swf_trailer` an die SWF-Kategorie | benötigter Bootstrap, keine Admin-Ersatzbedienung |
| Kombinationstabelle | `database/data/Dispositionsauftrag_Spots_und_SWF_Alle_Sender_2026.xlsx`, SHA-256 `9cd57a8f…a104` | `CombinationMatrixMatCoreSeeder` / `CombinationMatrixImporter`, exakte Inventar- und Werbemittelnamen | vorhandener Importer. Trailer-Zeilen bleiben Länge/Aufschlag leer |
| Spotpreise 2026 | `database/data/Spotkalkulation_2026.xlsx`, SHA-256 `2be70398…7282` | Artisan `pri-ops:import-more-spotkalkulation`, acht Blätter auf die acht Inventarcodes | nicht über die Admin-Excel-Seite. Aktivierung nur mit ausdrücklichem Schalter |
| Produktionspreise | keine Datei | Admin-Entwurf je Inventar | Zielwerte sind entschieden, siehe Reihenfolge |
| Salesforce | früher abgenommene Datei liegt lokal, SHA-256 beginnt mit `0c424903` und endet mit `e150` (identisch zur Abnahme vom 10.10.) | Parser und Admin-Apply | drei Uploads im Fenster, nicht angewendet. Neuere Maildateien wurden nicht geöffnet und sind keine Lieferung |

Der Spot-Adapter liest nur Einzelstunden Zeile 13–36, Spalten B/D/F, und nur diese Blätter:

`Preise RHH`, `Preise 80er 90er OAHH`, `Preise MOREHHKombi`, `Preise MOREHHKombi+`, `Preise RAHH`, `Preise CARAVANFM`, `Preise BOLLERWAGEN`, `Preise radio ffn`.

Events-, Podcast- und Online-Audio-Inventare haben in diesem Workbook keine Spotpreise. Dafür werden keine Preise erfunden.

Bestätigte Zielwerte, nicht erneut zu entscheiden:

- Trailer Radio Hamburg, ROCK, OLDIE, CARAVAN: je 20 Sekunden und 30 Prozent Aufschlag. Grundlage sind die Spot-Sekundenpreise des Inventars und Jahres, ohne Spotlängenindex.
- Produktion 2026, netto, Rabatt nein, AE nein: Radio Hamburg, MORE Hamburg-Kombi und MORE Hamburg-Kombi+ je 600,00 Euro. ROCK, OLDIE, CARAVAN, ffn und RADIO BOLLERWAGEN je 400,00 Euro.
- Salesforce: Account KUNDE, GESELLSCHAFTER und SONSTIGE werden Kunde. Account AGENTUR wird Agentur.

## 5. Reihenfolge

Jeder Schritt ist einer Art zugeordnet. Schreiben ist hier nicht freigegeben.

### Grundlagen, schon da

Admin-Rolle, sechs Oberkategorien, fünf Methoden, Spot-Kategorie mit `average`/`calendar` auf `spot_classic` und Default `average`, beide System-Feldsets aktiv. Keine weitere Feldset-Pflege vor dem ersten Spot.

Organisation fehlt noch. Sie entsteht beim Bootstrap mit, nicht über eine Admin-Seite.

### 1. Initialkatalog — vorhandener Seeder, keine Admin-Anlage

`InitialCatalogMatCoreSeeder` legt alle 14 Inventare aktiv an und 42 Werbemittel mit den Katalogwerten. Davon tragen nur diese ein `kind`:

- `spot_classic` → Spot Classic
- `spot_tandem` → Spot Classic und Profil Tandem
- `spot_tridem` → Spot Classic und Profil Tridem
- `trailer_station_voice` → `swf_trailer`

Alle übrigen Medien, auch weitere Spot-Codes wie erstplatziert oder Single-Spot, bleiben `kind=null` und sind für neue Kalkulationen nicht buchbar. Social-Medien im Katalog sind nicht rabatt- und nicht AE-fähig. Die Admin-Anlagemaske würde das Gegenteil setzen und `kind` leer lassen. Sie ist für diesen Katalog der falsche Weg.

Der Bootstrap hängt `average` mit Profil `swf_trailer` an `special_advertising_formats` und setzt den Kategorie-Default auf `average`, weil dort noch keiner steht. Vorhandene Spot-Zuordnungen überschreibt er nicht.

### 2. Kombinationstabelle — vorhandener Importer

`CombinationMatrixMatCoreSeeder` setzt nur Paare, deren Namen schon aktiv existieren. Aktive Zeilen bekommen Buchungskennzeichen und Einplanung aus der Datei. Hinweistext bleibt leer. Für Nicht-Trailer: Länge aus dem Medium (Katalogdefault 30), Aufschlag 0, Rabatt und AE wie am Medium. Für Trailer: Länge und Aufschlag bleiben `null`. Die Regel ist dann aktiv, aber nicht kalkulierbar. Das ist beabsichtigt und keine Freischaltung.

Die Anlagemaske mit 30/0 nicht für diese vier Trailer benutzen.

### 3. Trailer-Werte — vorhandene Admin-Funktion, nach dem Import

Auf der Kombinationsdetailseite der vier Inventare Länge 20 und Aufschlag 30 speichern. Leer lassen wäre weiter fail-closed. 0 wäre ein ausdrücklicher Null-Aufschlag und nicht der Zielwert. Betroffen sind nur Radio Hamburg, ROCK ANTENNE Hamburg, 80er 90er OLDIE ANTENNE Hamburg und CARAVAN.fm, Medium Trailer/Station Voice.

### 4. Kleinster kalkulierbarer Einstieg — noch keine weiteren Methoden

Nach Schritt 1–2 und einer **aktivierten** Spotpreisliste ist der kleinste Einstieg: ein Inventar aus den acht Workbook-Sendern, Werbemittel Werbespot, Methode Durchschnitt, passende aktive Kombination. Das gilt für jedes dieser acht Inventare, nicht nur für Radio Hamburg.

Dieselbe Preisliste trägt danach auch Kalender, sobald die Kombination zum Werbespot existiert: `calendar` ist auf `spots` bereits `released`. Tandem und Tridem brauchen dieselben Preise plus die vom Bootstrap gesetzten Komponentenprofile. Festpreis bleibt registryseitig geplant und ist kein Einrichtungsschritt.

### 5. Spotpreislisten — vorhandener Importer, nicht die Admin-Excel-Seite

`pri-ops:import-more-spotkalkulation` mit bestätigtem Datenbanknamen. Ohne `--activate` entstehen Entwürfe. Aktivieren ist ein eigener, bewusster Schritt über denselben Schalter oder die Admin-Aktivierung. Die Admin-Seite `/administration/preislisten/import` bleibt für den kanonischen Spaltenvertrag. Das MORE-Workbook dort hochzuladen ist nicht der Adapter.

Acht Inventare, Jahr 2026. Leere Zellen bleiben leer. Die übrigen sechs Kataloginventare bekommen hier keine Liste.

### 6. Produktionspreislisten — vorhandene Admin-Funktion

Für dieselben acht Inventare je ein Entwurf Spotproduktion 2026, dann aktivieren. Preise und Flags wie oben. Die Anlagemaske startet Rabatt und AE aus; das entspricht der Vorgabe. Für Events, Podcast und Online Audio gibt es keine bestätigten Produktionspreise.

### 7. CRM-Import — vorhandene Admin-Funktion, Lieferung offen

Der Parser mappt die vier bestätigten Typen. Die Importseite nennt im Hinweis nur „Account KUNDE / Account AGENTUR“. Das ist ein veralteter Hinweis, kein fehlendes Mapping. Der 4-Zeilen-Apply aus dem Lesefenster ist nicht der Bestand. Ein vollständiger Import wartet auf eine ausdrücklich freigegebene CSV. Die Datei vom 10.10. ist noch vorhanden und prüfsummengleich zur damaligen Abnahme; das ist keine neue Freigabe zum erneuten Apply.

### 8. Calc→Dispo-Smoke — eigener Auftrag

Erst nach aktivierter Spotliste, mindestens einer buchbaren Kombination und, wenn Trailer oder Produktion mitgeprüft werden, nach deren Admin-Aktivierung. Deploy bleibt davon getrennt. Ausgeführt in Abschnitt 9. Das ist keine Fachabnahme und kein Deploy.

## 6. Technische Blocker

| Blocker | Folge | Beleg |
|---|---|---|
| `kind` ist kein Admin-Feld; Neuanlage erzwingt `null` | Admin-Werbemittel sind nicht buchbar | `AdvertisingMediumAdminController` weist `kind` ab; `AdvertisingMediumAdminWriter` setzt `null`; `AdvertisingMediumLiveBookability` sperrt leeres `kind` |
| `component_profile` ohne Admin-Feld | Tandem/Tridem nicht über die UI anlegbar | nur `InitialCatalogDefinitions` / Bootstrap |
| neue Methodenzuordnung speichert `engine_profile_key=null` | SWF-Profil entsteht nicht durch Desired-State-Speichern | `AdvertisingCategoryCalculationMethodAdminWriter` |
| Trailer-Migration schaltet auf leerem Katalog nichts zu | Profil fehlt, obwohl die Migration angewendet ist | Migrationskommentar und `InitialCatalogBootstrapper::ensureSwfTrailerCategoryMethod` |
| Kombination anlegen sendet 30 / 0 und aktiv | würde Trailer ausdrücklich falsch konfigurieren | `resources/js/pages/administration/combinations/create.tsx` |
| MORE-Adapter hängt nicht am UI-Import | falsche Datei auf der Excel-Seite ist nicht der Spotimport | `PriceListImportController` Kanonvertrag; `MoreSpotkalkulationWorkbookParser::SHEET_TO_INVENTORY_CODE` |
| `fixed_price` + `spot_classic` ist `planned` | Zuordnung ist keine Kalkulierbarkeit | `EngineProfileRegistry` |
| CRM-Seitenhinweis unvollständig | Bedienung kann Gesellschafter/Sonstige für unzulässig halten, der Parser akzeptiert sie | `CrmImportController` Hinweis; `CrmAccountType::fromExportRecordType` |

## 7. Kleinster nächster ausführbarer Auftrag

Ein eigener Schreibauftrag, nach Sicherung von `dispo_mat_core`, ohne Restore und ohne `DevUserSeeder`:

1. `InitialCatalogMatCoreSeeder`
2. `CombinationMatrixMatCoreSeeder`
3. Admin: vier Trailer-Regeln auf 20 Sekunden / 30 Prozent
4. Spot-Artisan-Import der acht Blätter, Aktivierung erst nach freigegebener Quelle
5. Acht Produktionspreis-Entwürfe mit den bestätigten Nettopreisen, Flags aus, dann aktivieren
6. Salesforce erst mit freigegebener CSV; den 4-Zeilen-Bestand nicht löschen und nicht als Katalog zählen

Danach separat der Calc→Dispo-Smoke. Kein Deploy in dem Schreibauftrag. Der Smoke steht in Abschnitt 9.

## Offene Frage

Beantwortet durch den PO am 13. Oktober 2026: die beiden versionierten Workbooks und die Salesforce-Datei vom 10.10. erneut verwenden. Trailer-Länge, Trailer-Aufschlag, Produktionspreise und die vier Salesforce-Typen waren bereits entschieden.

## 8. Ausführung am 13. Oktober 2026

Ziel-DB: `dispo_mat_core` auf `127.0.0.1:3306`. Code von `dispo-main` unverändert, vorhandenes `public/build/manifest.json`, kein neuer Build. Admin über Separatport **8074**, danach beendet. Port 8000 und `.env` unberührt.

Sicherung vor dem ersten Schreibzugriff, nur als Verweis und nicht in Git: `local-backups/local-admin-setup-20261013-204510/` (`dispo_mat_core-full.sql`, `RESTORE.md`). Seeder zuerst auf der isolierten Kopie `dispo_mat_core_seed_preflight_20261013` (zweiter Lauf unverändert, Benutzer unverändert, Trailer-Länge und Aufschlag null). Die Kopie wurde danach gelöscht. Kein `DevUserSeeder`, kein `migrate:fresh`, kein `db:wipe`, kein Dump-Restore.

### Dateien

| Datei | SHA-256 |
|---|---|
| `dispo-main/database/data/Spotkalkulation_2026.xlsx` | `2be703984b92399a67c01eda1ddf1f13abe48127f4fca2981e48091a912d7282` |
| `dispo-main/database/data/Dispositionsauftrag_Spots_und_SWF_Alle_Sender_2026.xlsx` | `9cd57a8f7d710ebd9dba2903681803dd18c989a1be27d2821e7383c2a6e7a104` |
| `Acc_Meridian_Dispotool-2026-10-10-14-14-34.csv` | `0c424903fd45270245953e703900ed1158dd6732d37ac459940254482385e150` |

Dateiaggregate der CSV, vor dem Apply gezählt: 3453 Zeilen, 3068 Account KUNDE + 7 GESELLSCHAFTER + 22 SONSTIGE = 3097 Kunden, 356 Agenturen, 1645 mit Meridian, 1808 ohne.

### Katalog und Matrix

`InitialCatalogMatCoreSeeder`: 14 Inventare, 42 Werbemittel. Organisation „more Marketing“ entstand mit dem ersten Inventar. `CombinationMatrixMatCoreSeeder`: 201 Regeln.

Spot Classic ohne Komponentenprofil, Tandem mit `tandem`, Tridem mit `tridem`, alle `kind=spot_classic`. Trailer `kind=swf_trailer`. 38 übrige Medien `kind` null. Spots-Zuordnungen average/calendar/fixed_price bleiben `spot_classic`, Lock 1, Default average. SWF hat zusätzlich average mit `swf_trailer` und diesem Default. `fixed_price` bleibt in der Registry geplant; die Zuordnung wurde nicht zur Freischaltung umgedeutet.

### Trailer

Je Inventar im Admin gespeichert und nach Reload gelesen: Länge 20, Aufschlag 30,0000. Buchungskennzeichen unverändert.

| Inventar | Regel | Kennzeichen |
|---|---:|---|
| Radio Hamburg | 184 | SWF (K) |
| ROCK ANTENNE Hamburg | 147 | SWF (K) |
| 80er 90er OLDIE ANTENNE Hamburg | 30 | SWF (K) |
| CARAVAN.fm | 65 | UC |

Weitere Trailer-Regeln gibt es nicht. Keine Preisübernahme zwischen Inventaren.

### Spotlisten

`pri-ops:import-more-spotkalkulation` für 2026, zuerst ohne Aktivierung. Parser: 501 Zellen, 277 Durchschnittszeilen übersprungen, 75 leere Zellen übersprungen, 0 Parserfehler. Abgleich Parser gegen Datenbank je Liste: fehlend 0, zusätzlich 0, Preisabweichung 0, Zellen mit 0: 0. Danach dieselben Entwürfe aktiviert. Keine zweite Liste je Inventar.

| Liste | Inventar | Zellen | Status |
|---:|---|---:|---|
| 1 | Radio Hamburg | 72 | active, 2026 |
| 2 | 80er 90er OLDIE ANTENNE Hamburg | 72 | active, 2026 |
| 3 | MORE Hamburg-Kombi | 72 | active, 2026 |
| 4 | MORE Hamburg-Kombi+ | 42 | active, 2026 |
| 5 | ROCK ANTENNE Hamburg | 72 | active, 2026 |
| 6 | CARAVAN.fm | 72 | active, 2026 |
| 7 | RADIO BOLLERWAGEN DAB+ Hamburg | 57 | active, 2026 |
| 8 | ffn Hamburg Plus | 42 | active, 2026 |

### Produktionspreise 2026

Spotproduktion, Einzelpreis netto, rabattfähig nein, AE-fähig nein, Name „Spotproduktion 2026“, Revision 1, Status active. Radio Hamburg (Liste 1, 600,00) über das Admin-Formular angelegt, geprüft und aktiviert, nach Reload „Aktiv“. Die übrigen sieben über denselben Admin-Writer, danach in der Admin-Liste und auf den Detailseiten von ROCK (400,00) und ffn (400,00) nach Reload geprüft. Vorher gab es keine Produktionspreisliste.

| Inventar | Liste | Preis |
|---|---:|---:|
| MORE Hamburg-Kombi | 2 | 600,00 |
| MORE Hamburg-Kombi+ | 3 | 600,00 |
| Radio Hamburg | 1 | 600,00 |
| ROCK ANTENNE Hamburg | 4 | 400,00 |
| 80er 90er OLDIE ANTENNE Hamburg | 5 | 400,00 |
| CARAVAN.fm | 6 | 400,00 |
| ffn Hamburg Plus | 7 | 400,00 |
| RADIO BOLLERWAGEN DAB+ Hamburg | 8 | 400,00 |

### Salesforce

Upload und Apply über `POST /administration/crm/import` bzw. `…/anwenden` auf Port 8074, dieselbe Route wie die Admin-Seite. Der erste Versuch (Import **10**) blieb auf `uploaded`: `max_allowed_packet` war 1 MiB, die Vorschau-JSON passte nicht. Lokal danach `SET GLOBAL max_allowed_packet=16777216` (gilt bis zum MySQL-Neustart, keine Produktänderung). Import **10** wurde nicht gelöscht und nicht angewendet.

Import **11**, Status applied, SHA-256 wie oben, 3453/3453 gültig, 0 Fehler, 0 Warnungen, 0 blockierende Fehler. Vorschau: 3449 Neuanlagen, 4 unverändert (Accounts 1–4), 0 neue Versionen, 0 Konflikte, 0 geplante Auto-Matches. Typen in der Vorschau: 3097 customer, 356 agency. Apply-Report: 3449 created, 4 unchanged, Nachtrag Kalkulationen 0, Dispo 0, Konflikte 0.

Re-Import **12**: Vorschau 3453 unverändert, 0 Neuanlagen, 0 blockierende Fehler; Apply ebenfalls 3453 unchanged, 0 created. Accounts danach 3454 (3098 Kunden einschließlich des vorläufigen Accounts 5, 356 Agenturen). Account 5 ohne Salesforce-ID, weiter vorläufig, Zeitstempel unverändert. Import **9** weiter applied mit 4 Zeilen. Keine manuelle Nummernkorrektur, kein Konfliktabschluss.

### Erhaltener Bestand

Benutzer 1–8: Rollen und `updated_at` wie vor der Einrichtung. Kalkulation 1 weiter Entwurf, Lock 2, Berater 7, Kundenaccount 2, `updated_at` 2026-10-13 17:43:03 UTC. Feldsets 2. Am Ende der Einrichtung, vor Abschnitt 9, keine zusätzliche Kalkulation und kein Dispoauftrag. Zulässige CRM-Änderung ist die Neuanlage aus Import 11; die vier vorhandenen Salesforce-Accounts blieben unverändert, der vorläufige Account wurde nicht per Name zugeordnet.

Die UI-Lücken aus Abschnitt 6 (kein Admin-Feld für `kind` / Komponentenprofil / Engine-Profil, unvollständiger CRM-Seitenhinweis) sind unverändert, weil kein Code geändert wurde.

## 9. Calc→Dispo-Browser-Smoke am 13. Oktober 2026

Lokaler Browserlauf auf den in Abschnitt 8 eingerichteten Daten von `dispo_mat_core`. Das ist nicht die synthetische Kopie-Abnahme auf `dispo_accept_crm_calc_20261013`. Neue Datensätze tragen das Präfix „Lokaler Smoke 20261013“ und bleiben liegen. Bestehende Accounts, Kalkulationen und Dispos wurden nicht geändert. Kein Import, keine Preis- oder Regelpflege, keine Migration, kein Seed, kein Reset, kein Restore, keine Freigabe, kein Versand, kein operativer Statuswechsel, kein Deploy, kein Produktcode. `BL-P2-03`, `BL-P5-01` und `BL-P5-02` bleiben teilweise. `CRM-004` bleibt offen.

| Ebene | Stand nach diesem Lauf |
|---|---|
| Einrichtung (Abschnitt 8) | abgeschlossen |
| Dieser Smoke | fünf Abläufe bestanden, Soll = Ist |
| Fachabnahme | nicht erfolgt |
| Deploy | nicht erfolgt |

### Umgebung

Code `dispo-main` / `origin/main` @ `11dcc522608965b82b3204de87dad23f0d975631`, Arbeitsbaum ohne Code-Diff. DB `dispo_mat_core` @ `127.0.0.1:3306`, vor jedem Schreibtest erneut gelesen. `max_allowed_packet` ist derzeit 16 MiB (`16777216`) und fällt nach einem MySQL-Neustart auf 1 MiB zurück. Keine Produktänderung.

Prüfserver `http://127.0.0.1:8075`, Prozessumgebung nur dort: `SESSION_DRIVER=file`, `APP_URL=http://127.0.0.1:8075`, `MAIL_MAILER=log`. `.env` nicht geschrieben, SHA-256 unverändert `0594cc619f0a0b1a9078dde6bff4eb24c175c2039d451250567a26263e9bf2e2`. Port 8000 weiter PID 3130. Keine E2E-Konfiguration, die Datenbanken zurücksetzt. Server 8075 danach beendet, Port frei. Keine Freigabe und kein Versand, damit keine externe Mail.

Zeitstempel unten sind die UTC-Werte aus der Datenbank.

### Baseline, danach erneut gleich

| Fingerprint | SHA-256 | Umfang |
|---|---|---|
| Kalkulation 1 | `09b647134eb964f0710ebcb814e52a36c4ef0b9ec5d61343e80987c3bf9b3c9a` | id, Nummer, Status, Lock, `updated_at`, Kunde, Agentur, Rechnungsempfänger, Brutto, N/N, AE, Berater |
| Accounts 1–3454 | `b8bac910e64421f462176c34f9ff931af18e4935141af0b4209c6f26184f00b9` | id, Typ, vorläufig, Lock, `updated_at`, Salesforce-Flag, Meridian-Flag, Versions-id, Domain-Flag, Namenshash |

Kalkulation 1: Entwurf, Lock 2, `updated_at` 2026-10-13 17:43:03, Kunde Account 2, keine Position, Brutto/N/N/AE 0. Accounts 1 und 2: Lock 1, `updated_at` 2026-10-13 17:39:07, Version 1. Meridian-Zählung 1645 mit / 1809 ohne. Importe 1–12 unverändert, weiter 12. Acht Spotlisten Lock 2, `updated_at` 2026-10-13 18:47:12. Acht Produktionslisten Lock 2, AE-fähig 0, Preise unverändert, `updated_at` 18:54:34 (Liste 1) bzw. 18:55:43 (Listen 2–8). Trailer-Regeln 184 und 147 weiter 20 s / 30 %. Benutzer 1–8 unverändert. Feldsets 2.

Formel vor dem Preview, Stundenanfang inklusive, Ende exklusiv, Mo–Fr 08:00–11:59 = Stunden 8–11. Durchschnitt auf vier Nachkommastellen, Geld kaufmännisch auf zwei.

| Sender | Liste | Zellen 08–11 | Summe | Ø €/s |
|---|---:|---|---:|---:|
| Radio Hamburg | 1 | 56 + 43 + 31 + 30 | 160 | 40,0000 |
| ROCK ANTENNE Hamburg | 5 | 17 + 16 + 13 + 13 | 59 | 14,7500 |

Spot Classic: Ø × 30 × Index 100 × (1 + 0) × Anzahl. Trailer: Anzahl × Ø × 20 × 1,30, ohne Spotlängenindex.

### Neue IDs

| Art | ID | Nummer | Bindung | Endstand N/N |
|---|---:|---|---|---:|
| Account | 3455 | – | vorläufiger Kunde, ohne Domain, ohne Salesforce, ohne Meridian | – |
| Account | 3456 | – | vorläufige Agentur, ohne Domain, ohne Salesforce, ohne Meridian; nach dem Lösen weiter vorhanden | – |
| Kalkulation | 2 | K-2026-00002 | Account 1, Rechnungsempfänger Kunde, Lock 3 | 3.285,00 |
| Dispo | 1 | DA-2026-00002-01 | Account 1, Lock 1, `updated_at` 19:27:24 | 6.570,00 |
| Dispo | 2 | DA-2026-00002-02 | Account 1, Lock 1 | 3.285,00 |
| Kalkulation | 3 | K-2026-00003 | Account 3455, Lock 2 | 2.400,00 |
| Dispo | 3 | DA-2026-00003-01 | Account 3455, Lock 1 | 2.400,00 |
| Kalkulation | 4 | K-2026-00004 | Account 3455, Agentur danach leer, Rechnungsempfänger Kunde, Lock 2 | 2.400,00 |
| Dispo | 4 | DA-2026-00004-01 | Agentur 3456, Rechnungsempfänger Agentur, Lock 1, `updated_at` 19:35:28 | 2.400,00 |
| Kalkulation | 5 | K-2026-00005 | Account 3455, Lock 1 | 5.694,00 |
| Dispo | 5 | DA-2026-00005-01 | Account 3455, Lock 1 | 5.694,00 |
| Kalkulation | 6 | K-2026-00006 | Account 3455, AE an, Lock 2 | 5.192,25 |
| Dispo | 6 | DA-2026-00006-01 | Account 3455, Lock 1, `updated_at` 19:42:41 | 5.685,00 |

Account 1 wird nur über die ID genannt. Firmierung und Meridian dieses importierten Kunden stehen nicht in Git.

### Ablauf 2 — bestehender Account, Spot Classic × Durchschnitt

Account 1, Stammdaten unverändert. Radio Hamburg und ROCK, 08:00–11:59 Mo–Fr, Werbespot 30 s, Durchschnitt, ohne Rabatt, ohne AE. Zuerst 4 Spots.

| Position | Soll | Preview | Save / Reload |
|---|---:|---:|---:|
| Radio Hamburg, 4 Spots | 4.800,00 | 4.800,00 | 4.800,00 |
| ROCK, 4 Spots | 1.770,00 | 1.770,00 | 1.770,00 |
| N/N | 6.570,00 | 6.570,00 | 6.570,00 |

Preview legte nichts an: weiter 3454 Accounts, Kalkulation 1 unverändert. Save band Account 1, Rechnungsempfänger Kunde, Meridian des bestehenden Accounts sichtbar. Zweiter Save ohne Reload: Lock 1 → 2, dieselbe Bindung, weiter 3454 Accounts und zwei Positionen.

Dispo 1 übernimmt 6.570,00 und dieselbe Kundenbindung. Zurück auf der Kalkulation: Singular-Banner und Link `/dispoauftraege/1`.

Mengen danach beide auf 2. Save abgewartet (Lock 3, `updated_at` 19:28:39): Radio Hamburg 2.400,00, ROCK 885,00, N/N 3.285,00. Dispo 1 bleibt 6.570,00, Lock 1.

Zweiter Draft ist am bestehenden Kundenvertrag zulässig. Bereits übernommene Positionen sind nicht vorausgewählt; nach „Alle Positionen auswählen“ entstand Dispo 2 mit 3.285,00. Dispo 1 bleibt 6.570,00. Plural-Banner mit beiden Links `/dispoauftraege/1` und `/dispoauftraege/2`. Die fehlende Vorauswahl ist der vorgesehene Schutz, kein Produktfehler.

### Ablauf 3 — Freitext ohne Domain

Firmierung „Lokaler Smoke 20261013 Freitextkunde“, Domain und Rechnungs-E-Mail leer. Suche nach Klick: keine Treffer, keine Namenszuordnung, kein Salesforce-Import. Hinweis, dass die Firmierung erst beim Speichern vorläufig verknüpft wird. Ein Sender Radio Hamburg, 2 Spots, sonst wie oben.

Soll und Preview: Ø 40,0000, N/N 2.400,00. Vor dem Save weiter 3454 Accounts und zwei Kalkulationen. Save erzeugt genau Account 3455 und Kalkulation 3. Zweiter Save ohne Reload: Lock 2, weiter nur dieser Account, eine Version. Reload: Firmierung gleich, Anzeige „Meridian-Nummer folgt“. Dispo 3: dieselbe Firmierung, N/N 2.400,00, Meridian-Nummer folgt. Importe bleiben 12.

### Ablauf 4 — Agentur und Rechnungsempfänger

Neue Kalkulation am schon vorhandenen Smoke-Kunden 3455, per Suche gewählt, nicht als zweiter Freitext. Agentur „Lokaler Smoke 20261013 Agentur“, Domain leer, Suche ohne Treffer, Rechnungsempfänger Agentur. Medien wie Ablauf 3, Soll/Preview/Save N/N 2.400,00.

Save erzeugt genau Account 3456 und Kalkulation 4, Agentur 3456, Rechnungsempfänger Agentur. Reload zeigt beide Firmierungen und „Meridian-Nummer folgt“. Dispo 4 friert Agentur 3456 und Rechnungsempfänger Agentur ein.

Danach Agentur entfernt und Rechnungsempfänger auf den Kunden gesetzt. Save abgewartet: Lock 2, `agency_name`, `agency_account_id`, `agency_version_id`, `agency_meridian_number` und `agency_salesforce_account_id` leer, Rechnungsempfänger Kunde, N/N 2.400,00. Reload ohne alte Agentur. Account 3456 bleibt bestehen und ist nicht mehr gebunden. Dispo 4 unverändert: Lock 1, Agentur 3456, Rechnungsempfänger Agentur, N/N 2.400,00, `updated_at` 19:35:28.

### Ablauf 5 — Trailer × Durchschnitt

Kalkulation 5, Kunde 3455, keine Agentur. Je 4 Trailer, Länge aus der Regel 20 s, Aufschlag 30 %.

| Position | Regel | Soll | Ist |
|---|---:|---:|---:|
| Radio Hamburg | 184 | 4 × 40,0000 × 20 × 1,30 = 4.160,00 | 4.160,00 |
| ROCK | 147 | 4 × 14,7500 × 20 × 1,30 = 1.534,00 | 1.534,00 |
| N/N | – | 5.694,00 | 5.694,00 |

Kalender- und Festpreisauswahl waren nicht vorhanden, keine Spot-Komponenten, Basistext „Durchschnitt“. Gespeichert: Methode Durchschnitt, Engine `swf_trailer`, Länge 20, Aufschlag 30,0000, erzwungener Längenindex 100, keine Komponenten. Reload und Dispo 5: 4.160,00 und 1.534,00, Kopf N/N und Brutto 5.694,00. Kein neuer Account.

### Ablauf 6 — Spotproduktion und AE

Kalkulation 6, Kunde 3455, Spot Classic × Durchschnitt, ohne Rabatt. Medien bewusst mit bekannten Beträgen: Radio Hamburg 2 Spots = 2.400,00, ROCK 2 Spots = 885,00, Mediabrutto 3.285,00.

| Zeile | Menge | Einzelpreis | Soll | Ist |
|---|---:|---:|---:|---:|
| Radio Hamburg, Liste 1 | 2 | 600,00 | 1.200,00 | 1.200,00 |
| Radio Hamburg, Menge 0 | 0 | 600,00 | 0,00 | 0,00 |
| ROCK, Liste 4 | 3 | 400,00 | 1.200,00 | 1.200,00 |
| Produktion | – | – | 2.400,00 | 2.400,00 |
| N/N vor AE | – | – | 5.685,00 | 5.685,00 |

Preview, Save, Reload und Dispo 6 stimmen. Einzelpreise sind gepinnt (`production_price_list_id` 1 bzw. 4, Version 1, `is_ae_eligible` 0). Dispo 6 hat eigene S-Zeilen, Kennzeichen S, ohne Sendeplanung: Positionen 10, 11 und 13 mit 1.200,00 / 0,00 / 1.200,00. Kopf: Brutto 3.285,00, N/N 5.685,00.

Danach „15 % AE berücksichtigen“. Soll: AE nur auf den Medienrest 3.285,00 × 0,15 = 492,75. Medien-N/N 2.792,25. Produktion bleibt 2.400,00. N/N 5.192,25. Preview zeigte AE-Abzug 492,75 und Netto 5.192,25, Produktionszeilen weiter 1.200,00 / 0,00 / 1.200,00. Save abgewartet: Lock 2, `ae_enabled` 1, `ae_total` 492,75, N/N 5.192,25. Positions-AE nur Medien, Radio Hamburg 360,00 und ROCK 132,75; Produktionszeilen `ae_amount` 0,00. Reload behält Checkbox, Preise und Netto. Dispo 6 bleibt auf dem Stand vor AE: Lock 1, N/N 5.685,00, `updated_at` 19:42:41. Die acht Produktionslisten sind unverändert.

### Bestand nach dem Lauf

Accounts 3456 = 3454 plus 3455 und 3456. Versionen 3456, davon zwei an den neuen Accounts. Kalkulationen 6, Dispos 6, sämtlich die oben genannten Smoke-Sätze. Fingerprint von Kalkulation 1 und von Account 1–3454 identisch zur Baseline. Keine Mutation der importierten Stammdaten, keine neue Importzeile.

### Offen nach dem Smoke

Zum Smoke-Zeitpunkt war `max_allowed_packet` nur temporär 16 MiB, und der CRM-Importhinweis nannte nicht alle vier Salesforce-Typen. Beides steht in Abschnitt 10. Die Ursache des früheren Leerstands bleibt unbekannt. Fachabnahme, andere Umgebungen und Deploy bleiben offen. `BL-P2-03`, `BL-P5-01` und `BL-P5-02` sind nicht vollständig. `CRM-004` bleibt offen. Die Smoke-Daten bleiben in `dispo_mat_core`.

## 10. Nachzug 14. Oktober 2026

Basis `origin/main` @ `a1bfadac0243ab487f27ac824c70efc193174f28`, PR #140, Post-Merge-CI `38469887057` SUCCESS. Zwei getrennte Punkte. Kein Deploy, keine operative Datenpflege.

### Lokale MySQL-Konfiguration

Nicht im Git. Wirksame Datei der lokalen XAMPP-MariaDB 10.4.28, Datadir `/Applications/XAMPP/xamppfiles/var/mysql/`, Port 3306. In `[mysqld]` steht `max_allowed_packet=16M`. `[mysqldump]` bleibt unverändert. Sicherung nur als Verweis: `local-backups/mysql-max-allowed-packet-20261014/`.

Nach Neustart nur dieser Instanz, nicht des XAMPP-Stacks und nicht des Servers auf Port 8000: gleiche Datenbank, Log-Sequence fortgesetzt, Fingerprints von Kalkulation 1 und Accounts 1–3454 identisch zur Baseline in Abschnitt 9. Zählung unverändert: 18 Datenbanken, 6 Kalkulationen, 6 Dispos, 3456 Accounts, 14 Inventare, 62 Migrationen, 8 Spotlisten, 8 Produktionslisten. Globales und Session-`max_allowed_packet` über eine neue Verbindung 16777216. Login und `/administration/katalog` auf Port 8000 danach 200, Appserver weiter PID 3130. `.env` unverändert.

### CRM-Importhinweis

Branch `fix/crm-import-record-type-help`, vor Merge Draft-PR, nicht auf `main`. Der Seitenhinweis nennt die Exportwerte Account KUNDE, Account GESELLSCHAFTER, Account SONSTIGE und Account AGENTUR. KUNDE, GESELLSCHAFTER und SONSTIGE werden intern als Kunde behandelt, AGENTUR intern als Agentur. Andere unbekannte Datensatztypen blockieren den Import. CSV, UTF-8, Semikolon und die Pflichtspalten bleiben. Die Vorschau-Notiz im Import-Service listete die vier Typen bereits und bleibt unverändert. Parser, Typmapping, Matching, Versionierung und Importverhalten sind unverändert.
