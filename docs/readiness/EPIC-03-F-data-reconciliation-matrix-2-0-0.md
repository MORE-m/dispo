# EPIC-03-F – Datenabgleich-Matrix Schema 2.0.0

**Status:** Vorbereitung. Zahlen vom 23.11.2026 sind **historische Referenzwerte**, keine aktuellen Ist-Werte.
**Datum:** 2026-11-25
**Vertrag:** [`../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md`](../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md)
**Historische Lesung:** [`EPIC-03-F-full-master-data-readiness-2026-11-23.md`](EPIC-03-F-full-master-data-readiness-2026-11-23.md)

## Hinweis zu Counts

Spalten „Quelle (hist.)“ und „Staging (hist.)“ stammen aus der Readiness-Lesung vom **2026-11-23**. Vor dem operativen Lauf müssen beide Seiten **neu** gezählt und in einer Ausführungsnotiz festgehalten werden.

„Erwartung“ beschreibt die fachliche Planung aus Vertrag + historischer Differenz. Der Dry-Run-Bericht ist verbindlich; weicht er ab → STOP und Auswertung, keine stille Korrektur.

Eingebettete Kindmengen (`price_list_items`, `oa_tkp_price_list_items`) erscheinen in der Whitelist, sind aber nicht eigene Paketdateien.

---

## Gesamtsicht 25 Whitelist-Tabellen

| # | Tabelle | Fachschlüssel | Abhängigkeiten / FK | Quelle (hist.) | Staging (hist.) | Erwartung Creates / Unchanged / Updates | Mögliche Konflikte | Nachweis nach Apply |
|---|---|---|---|---:|---:|---|---|---|
| 1 | `organizations` | fachlich ein Mandant über `name` | — | 1 | 1 | 0 / 1 / 0 | Name abweichend; >1 Mandant | Count=1; Name unverändert zum erwarteten Mandanten |
| 2 | `calculation_methods` | `key` | — | 5 | 5 | 0 / 5 / 0 | Inhalt zu `key` abweichend | Alle 5 Keys; Inhalt unverändert |
| 3 | `advertising_categories` | `key` | optional `default_calculation_method_key` → Methoden | 6 | 6 | 0 / ≤6 / ≤6 erlaubt | Namensänderung; verbotene Felder | Keys gleich; nur erlaubte Metadaten-Updates laut Report |
| 4 | `advertising_media` | `code` | `category_key`; optional Default-Methode | 42 | 27 | ~15 / ~27 / 0 | gleicher Code, anderer Inhalt | Count Quelle; Stichprobe neuer Codes; RH-/Bestand unverändert |
| 5 | `advertising_category_calculation_methods` | `category_key` + `method_key` | Kategorie, Methode; Inhalt inkl. `engine_profile_key` | 5 | 5 | 0 / 5 / 0 | Profil/Inhalt abweichend | 5 Zeilen; Engine-Profile unverändert wenn Unchanged |
| 6 | `advertising_medium_calculation_methods` | `medium_code` + `method_key` | Medium, Methode; inkl. `engine_profile_key` | 6 | 0 | 6 / 0 / 0 | Duplikat/Inhalt bei späterem Re-Run | 6 OA-/Native-Zeilen vorhanden |
| 7 | `inventories` | `code` (ein Mandant) | Organisation | 14 | 1 | 13 / 1 / 0 | gleicher Code, anderer Inhalt inkl. `logo_path` | 14 Codes; RH Unchanged; Logos siehe unten |
| 8 | `inventory_medium_rules` | `inventory_code` + `medium_code` | Inventar, Medium | 202 | 27 | ~175 / ~27 / 0 | Inhalt/Strategie/Flags abweichend | Count≈202; RH-Regeln Unchanged; inkl. inaktiver OA |
| 9 | `price_lists` | `inventory_code` + `year` + `revision_number` | Inventar; Items eingebettet | 17 | 2 | ~15 draft create / ~2 unchanged / 0 | Fingerabdruck oder Metadaten abweichend | Neue Listen `draft`; RH 2026/2027 r1 unverändert bei Gleichheit |
| 10 | `price_list_items` | gehört zur Liste (`hour`,`day_group`) | `price_lists` | 1.074 | 144 | mit Elternliste | Item-Diff bei gleichem Listenschlüssel | Item-Counts je Liste; Fingerabdruck RH stabil |
| 11 | `production_price_lists` | `inventory_code` + `production_type` + `year` + `revision_number` | Inventar | 10 | 1 | ~9 draft create / ~1 unchanged / 0 | Fingerabdruck/Metadaten; **keine erfundenen 2027** | RH 2026 Unchanged; fehlende 2027 außer Kombi bleiben fehlend |
| 12 | `oa_platforms` | `code` | — | 5 | 0 | 5 / 0 / 0 | Code-Inhalt abweichend bei Re-Run | 5 Plattformen |
| 13 | `oa_platform_medium_rules` | `platform_code` + `medium_code` | Plattform, Medium | 9 | 0 | 9 / 0 / 0 | Inhalt abweichend | 9 Regeln |
| 14 | `oa_targeting_definitions` | `code` + `revision_number` | — | 2 | 0 | 2 / 0 / 0 | Revision/Inhalt; mehrere Revisionen je Code erlaubt | Beide Revisionen; Aufschläge laut Paket |
| 15 | `oa_tkp_price_lists` | `year` + `revision_number` | Items eingebettet | 2 | 0 | 2 draft create / 0 / 0 | Fingerabdruck/Metadaten | 2026+2027 als draft; je 41 Items hist. |
| 16 | `oa_tkp_price_list_items` | gehört zur Liste | Liste, Medium, Inventar | 82 | 0 | mit Elternliste | Item-Diff | 82 Items gesamt (hist. Referenz) |
| 17 | `oa_adserver_surcharges` | `code` + `revision_number` | — | 0 | 0 | 0 / 0 / 0 (leere Menge mitführen) | unerwartete Zeilen / Inhalt | Count=0 bleibt; leeres Paket-Array ok |
| 18 | `field_definitions` | `key` | aktive Revision später Phase B | 5 | 5 | 0 / 5 / 0 | Inhalt abweichend | Keys + `current_revision` konsistent |
| 19 | `field_definition_revisions` | `field_definition_key` + `revision` | Felddefinition | 5 | 5 | 0 / 5 / 0 | Inhalt abweichend | 5 Revisionen Unchanged |
| 20 | `field_definition_revision_options` | Def-Key + Revision + Options-`key` | Revision | 0 | 0 | 0 / 0 / 0 | unerwartete Optionen | Count=0 |
| 21 | `field_sets` | `key` | aktive Version später Phase B | 2 | 2 | 0 / 2 / 0 | Inhalt abweichend | Keys + `active_version` konsistent |
| 22 | `field_set_versions` | `field_set_key` + `version` | Feldset | 2 | 2 | 0 / 2 / 0 | Status/Inhalt abweichend | 2 Versionen Unchanged |
| 23 | `field_set_version_fields` | Set-Key + Version + Field-Key | Version, Feldrevision (**NOT NULL FK**) | 5 | 5 | 0 / 5 / 0 | Revisionsreferenz/Inhalt; NULL-FK verboten | 5 Zuordnungen; FK gültig |
| 24 | `field_rules` | Mengenvergleich je Set-Version (kein Zeilen-Unique) | Feldset-Version | 1 | 1 | 0 / Mengen-unchanged / 0 | abweichende vorhandene Menge → conflict | Regelmenge identisch; keine Dubletten |
| 25 | `field_set_assignments` | Set-Key + Process + semantische Zielidentität | Feldset; Remapping `target_identity` | 0 | 0 | 0 / 0 / 0 | Inhalt bei gleichem fachlichem Ziel | Count=0; Remapping-Logik bereit für spätere Daten |

---

## Besonders zu beachten

### Inventare und Medien

- Alle **14** Inventare der Quelle (hist.): 8 Spot/Kombi + OA-Kombi + Podcast-Kombi + 4 Event-Kombis.
- Staging hatte hist. nur Radio Hamburg; 13 Creates erwartet.
- Medien hist. 42 Quelle / 27 Staging → 15 Creates; bestehende 27 nur Unchanged.
- Inventare ohne Spotpreisliste (hist.): OA-Kombi, Podcast-Kombi, vier Event-Kombis – trotzdem im Paket.
- `logo_path` ist fachlicher Inhalt. Fehlende Binärdateien im Staging-Storage sind ein **operatives Risiko**, kein stilles Erfinden von Dateien (siehe Teil D).

### Spot- und Produktionspreise einschließlich Revisionen

Historische Spot-Referenz:

- 17 Listen, davon 16 active; 1 archived: Radio Hamburg 2027 Revision 2 (72 Items)
- Staging: 2 aktive RH-Listen (2026/2027 r1)
- Apply legt Neue nur als `draft` an; Status später

Historische Produktions-Referenz (alle `spot_production`, nicht rabattierbar, nicht AE-fähig):

| Schlüssel | Quelle (hist.) |
|---|---|
| Kombi 2026 r1 | 600 € active |
| Kombi 2026 r2 | 600 € archived |
| Kombi 2027 r1 | 600 € active |
| Kombi+ 2026 r1 | 600 € active |
| Radio Hamburg 2026 r1 | 600 € active (Staging vorhanden) |
| ROCK / OLDIE / CARAVAN / ffn / Bollerwagen 2026 r1 | je 400 € active |

**Fehlende Produktionspreislisten 2027** außer Kombi werden **nicht** künstlich ergänzt. Das ist eine bekannte fachliche Lücke, kein Importfehler.

### Online Audio, Plattformen und TKP

| Entität | Quelle (hist.) | Staging (hist.) | Erwartung |
|---|---:|---:|---|
| OA-Plattformen | 5 | 0 | 5 Creates |
| Plattform-Medien-Regeln | 9 | 0 | 9 Creates |
| Targeting-Definitionen | 2 | 0 | 2 Creates |
| TKP-Listen | 2 (2026+2027 active) | 0 | 2 draft Creates |
| TKP-Items | 82 (41 je Jahr) | 0 | mit Listen |
| Adserver-Aufschläge | 0 | 0 | leere Menge mitführen |

### Felddefinitionen, Feldsets und Zuordnungen

- Hist. bereits weitgehend gleich (Unchanged-Pfad).
- Phase B setzt `current_revision_id` / `active_version_id` nach.
- `field_set_version_fields.field_definition_revision_id` muss bei Create sofort gesetzt sein (NOT NULL).
- `field_rules`: Mengenvergleich, keine Teilanlage.
- `field_set_assignments`: `target_identity` wird auf Staging neu gebildet, nie Quell-ID kopiert.

### Erlaubte Updates (einzige Update-Klasse)

Nur `advertising_categories`, und nur wenn `key` und `name` bereits gleich:

- `is_active`, `sort`, `default_calculation_method_id` über Key

Alle anderen inhaltlichen Abweichungen → `conflict`.

---

## Erwartete Aktionslogik (Kurz)

| Situation | Aktion |
|---|---|
| Fachschlüssel fehlt auf Staging | `create` |
| Schlüssel + Inhalt gleich | `unchanged` |
| Schlüssel gleich, Inhalt anders | `conflict` (außer erlaubte Kategorie-Metadaten → `update`) |
| Zielzeile fehlt im Paket | kein Löschen, kein Deaktivieren |
| Preis Fingerabdruck/Metadaten abweichend | `conflict`, keine stille neue Revision |

---

## Nachweis-Paket nach Apply (fachlich)

1. Dry-Run- und Apply-Reports archivieren (JSON-Pfad laut CLI).
2. Counts der 25 Tabellen gegen frische Ausgangslesung + Report-Creates abgleichen.
3. Stichprobe je Familie: ein Unchanged-RH-Objekt, ein neues Draft-Preisobjekt, ein neues OA-Objekt, ein neues Inventar.
4. Bestätigen: keine Creates für ausgeschlossene Tabellen.
5. Bestätigen: Produktionspreise 2027 außer Kombi weiterhin fehlend, sofern in Quelle fehlend.
