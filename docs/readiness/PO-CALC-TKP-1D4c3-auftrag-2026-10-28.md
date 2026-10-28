# PO-CALC-TKP-1D4c-3 – Vorbereitung der echten OA-TKP-Stammdaten

Stand: 28. Oktober 2026 (**Analyse / Datenvorbereitung – kein Import, kein D4d**)  
Base: `f71886a3e5668990c2704784e0893d0d45b53830` (`origin/main`, PR **#194** gemergt)  
Post-Merge-CI: `40649436142` SUCCESS  
Live-DB-Stichprobe: `dispo_mat_core` (lokal, **nur lesend**)

**STOP:** Kein Import (auch kein Dry-Run mit nicht freigegebenen Fachdaten), kein Merge, kein Deploy, keine Registry-Freigabe.

## 1. Standprüfung

| Signal | Ist |
|---|---|
| `origin/main` / `dispo-main` HEAD | `f71886a…` = erwartet |
| PR #194 (D4c-2 Import-CLI) | gemergt |
| CI `40649436142` | SUCCESS |
| Registry `oa_tkp` × `tkp` | **`planned`** |
| `current_released_version` | `null` |
| Commands | `oa:import-platforms`, `oa:import-targetings`, `oa:import-price-list`, `oa:activate-price-list` |
| D4c-1 Vorlagen | vorhanden unter `docs/readiness/import-templates/` |
| D4c-2 Vertrag | `docs/readiness/PO-CALC-TKP-1D4c2-auftrag-2026-10-27.md` |
| `oa_tkp_price_lists` / items | **0 / 0** |
| `oa_platforms` / `oa_targeting_definitions` | **0 / 0** |
| `oa_adserver_surcharges` | **0** |
| Aktive Medium×Inventar-Regeln OA-Kern (`oa_tkp`×`tkp`) | **41** |
| `native_ad` OA-TKP-Assignment | **keines** |
| Runtime-Jahre (`PriceListCalendar`) | **2026 / 2027** |

## 2. Datenquellenübersicht

Untersucht: Readiness-Dokus (D4c-1/D4c-2 und Vorgänger), Import-Vorlagen, `docs/initialdaten.md`, `docs/anforderungskatalog.md`, `docs/berechnungslogik.md`, Katalog-/Entscheidungslogs, Fixture-/Testwerte, lokale Stammdaten-Tabellen (lesend).

| Quelle | Art | Inhalt zu OA Listen-/Mindest-TKP | Klassifikation |
|---|---|---|---|
| `docs/readiness/import-templates/oa-tkp-price-matrix-2026-2027.csv` | Liefer-/Abnahmevorlage | 82 Zeilen, **alle** `list_tkp`/`minimum_tkp` leer | **nicht belegt** (Warteschablone) |
| `oa-tkp-price-list.import.skeleton.json` | Import-Skeleton | `items: []` | **nicht belegt** |
| `resources/import-templates/oa-tkp-price-list.fixture.example.json` | Formatbeispiel | `0.0001` / `0.0001` | **Test-/Template** – nicht operativ |
| Feature-/Unit-/E2E-Tests | Fixtures | u. a. `12/8`, `99/1`, `18/11`, `20/15` | **Test-/Fixture** – nicht operativ |
| E2E D3d-4 | Runtime-Probe | Plattform `E2E D3d4 Platform` | **Test** – Codes nicht übernehmen |
| `docs/anforderungskatalog.md` OA-004 / `docs/initialdaten.md` | Fachregel | Adserver-TKP **15 EUR** Spotify/Deezer/YouTube | **belegt als Adserver-Aufschlag**, **nicht** Listen-/Mindest-TKP; Runtime laut D4c-1 **OUT** erstes Release |
| Social-Media-Festpreise in `initialdaten.md` | Fachpreise anderer Gattung | 450/900/1300 EUR usw. | **andere Produktgattung** – keine OA-TKP-Listen |
| `docs/initialdaten.md` „Vor Produktivsetzung noch zu liefern“ | Gap-Liste | explizit: digitale Festpreise OA + finale Targetings **offen** | bestätigt: **keine freigegebene OA-TKP-Liste im Repo** |
| Live-DB `dispo_mat_core` | operative Stammdaten | 0 Preislisten/Items/Plattformen/Targetings | **keine historischen OA-TKP-Preise lokal** |
| Spot-Classic-Preislisten | andere Engine | vorhanden (Spot), nicht OA | **nicht übertragbar** |

**Ergebnis Preisquellen:** Es gibt **keine freigegebenen fachlichen Listen- oder Mindest-TKPs** für Online Audio im Projektstand. Keine historischen OA-TKP-Listen in der lokalen DB. Vorhandene Zahlen sind entweder Test/Fixture/Template oder Adserver-Regel (15 EUR) außerhalb des Preislisten-Items.

**Keine Preise geschätzt oder erfunden.**

## 3. Abnahmematrix (41 × 2 Jahre)

Vollständige Zeilenmatrix (CSV):  
`docs/readiness/import-templates/oa-tkp-abnahmematrix-d4c3-2026-2027.csv`

Spalten je Zeile: Medium, Inventar, Preisjahr, Listen-TKP, Mindest-TKP, Quelle, Freigabestatus, fehlende Angaben (+ technische Meta aus D4c-1).

### Kurzüberblick Live-Regeln (41 Paare)

| Medium | Inventare | Anzahl |
|---|---|---|
| `pre_stream` | 10 Stream-Inventare* | 10 |
| `in_stream` | dieselben 10 | 10 |
| `pre_stream_influencer` | dieselben 10 | 10 |
| `in_stream_influencer` | dieselben 10 | 10 |
| `midroll_music_environment` | nur `inv_more_kombi_online_audio` | 1 |
| **Summe** | | **41** |
| `native_ad` | — | **OUT** (kein `oa_tkp`-Assignment) |

\*Inventare: `inv_80er_90er_oldie_antenne_hamburg`, `inv_caravan_fm`, `inv_ffn_hamburg_plus`, `inv_more_hamburg_kombi`, `inv_more_hamburg_kombi_plus`, `inv_more_kombi_online_audio`, `inv_more_kombi_podcast`, `inv_radio_bollerwagen_dab_plus_hamburg`, `inv_radio_hamburg`, `inv_rock_antenne_hamburg`.  
Booking-Code: `Online Audio (UA)`.

### Status je Preisjahr

| Jahr | Zeilen | Listen-TKP belegt | Mindest-TKP belegt | Freigabestatus | Bemerkung |
|---|---:|---:|---:|---|---|
| **2026** | 41 | **0** | **0** | `nicht_freigegeben` | parallel offen; kein Auto-Go |
| **2027** | 41 | **0** | **0** | `nicht_freigegeben` | **bevorzugtes Vorbereitungsjahr** (Auftrag), **ohne** unterstellte Fachfreigabe |

Alle 82 Zeilen: `quelle = keine belegte Fachquelle im Repo/Doku`, `fehlende_angaben = list_tkp+minimum_tkp`.

## 4. Vorhandene belegte Preise

| Was | Belegt? | Verwendbar für OA-Preislisten-Import? |
|---|---|---|
| Listen-TKP Medium×Inventar 2026/2027 | **nein** | — |
| Mindest-TKP Medium×Inventar 2026/2027 | **nein** | — |
| Adserver-TKP 15 EUR (Spotify/Deezer/YouTube) | ja (OA-004 / initialdaten) | **nein** für `oa_tkp_price_list_items`; eigener Pfad `oa_adserver_surcharges`, Runtime OUT erstes Release |
| Social-Media-Festpreise | ja | **nein** (andere Gattung) |

**Belegte operative OA Listen-/Mindest-TKPs: 0.**

## 5. Fehlende Preiswerte

Blockierend für Draft-Import und Activate laut D4c-2-Vertrag (`list_tkp`/`minimum_tkp` > 0, Punkt, ≤ 4 NK, `minimum ≤ list`):

- **41 × Listen-TKP 2026**
- **41 × Mindest-TKP 2026**
- **41 × Listen-TKP 2027**
- **41 × Mindest-TKP 2027**

Gesamt: **164 fehlende Pflichtwerte** (82 Paare × 2 Felder), solange beide Jahre voll abgedeckt werden sollen.

Zusätzlich fachlich offen (kein Zahlenfeld, aber Release-Entscheidung):

- ob erste Revision **alle 41** oder eine **Teilmenge** enthält
- Revisionsnummer / Versionslabel / `name` / `valid_from`
- ob 2026 und 2027 parallel oder gestaffelt geliefert werden

## 6. Plattform- und Targetingbedarf

### Plattformen

| Aspekt | Status |
|---|---|
| Live `oa_platforms` | **0** |
| Vorlage | `oa-platforms.template.csv` / Skeleton `[]` – **leer** |
| Belegte operative Codes/Namen | **keine** |
| Midroll-Name „Spotify / Deezer / Youtube …“ | nur Medium-Bezeichnung, **keine** Plattform-Stammdaten |
| Test-/E2E-Codes (`fixture_*`, `cli_plat_*`, `E2E D3d4 Platform`, …) | **dürfen nicht** in operative Vorlagen |

**Bedarf (blockierend für Wizard-Allocation):** fachlich freigegebene Liste mit mind. `code`, `name`; optional `is_active`, `sort`, `is_adserver_relevant`. Mindestens **eine** aktive Plattform nötig, sobald OA-Positionen gespeichert werden sollen (Allocation ≥ 1).

### Targetings

| Aspekt | Status |
|---|---|
| Live `oa_targeting_definitions` | **0** |
| Vorlage | `oa-targetings.template.csv` / Skeleton `[]` – **leer** |
| Belegte verkaufte Targetings inkl. Aufschläge | **offen** (`initialdaten.md`: „finale … Targetings … noch zu liefern“) |
| Erstes Release ohne Targeting | laut D4c-1 **möglich** (Engine mit leerer Selection) |

**Bedarf:** Entscheidung „keine Targetings im ersten Release“ **oder** Lieferung `code`/`name`/`category` (`technisch`\|`dmp`)/`tkp_surcharge` (≥ 0).

## 7. Import-Readiness (gegen D4c-2-Vertrag)

| Baustein | Status |
|---|---|
| CLI Platforms / Targetings / Price-List / Activate | **technisch bereit** (auf `main`) |
| Draft-only + Non-Draft-Guard + Jahres-Lock | **bereit** |
| Fach-JSON Platforms | **fehlt** |
| Fach-JSON Targetings | **fehlt** (optional entscheidbar) |
| Fach-JSON Price-List 2026 und/oder 2027 mit 41 Items | **fehlt** (Preise leer) |
| Dry-Run mit freigegebenen Fachdaten | **noch nicht möglich** |
| Produktiver Import / Activate | **gesperrt** (keine Fachdaten; Auftrag: kein Dry-Run mit nicht freigegebenen Daten) |
| Registry / D4d | **OUT** bis nach Import-Abnahme |

**Import-Readiness-Status: NOT READY (fachlich blockiert).**  
Technische Import-Pipeline: READY. Fachdatenlieferung: BLOCKING.

## 8. Vorschlag erster Release-Umfang

Empfehlung (ohne unterstellte Freigabe):

1. **Preisjahr 2027** als bevorzugtes **Vorbereitungs**- und Abnahmejahr (Matrix + Draft-JSON zuerst für 2027 befüllen lassen).
2. **2026** parallel nur, wenn Fachbereich beide Jahre liefert; sonst 2026 bewusst zurückstellen (kein stilles Kopieren 2027→2026).
3. **Produktumfang Preise:** alle **41** aktiven Paare in der ersten Draft-Revision des gewählten Jahres – sofern Fachliste nicht ausdrücklich eine Teilmenge freigibt (Teilmenge = bewusste Fachentscheidung).
4. **Plattformen:** Mindestkatalog operativ freigeben (Codes/Namen), **ohne** Test-Codes; Adserver-Flag nur wenn fachlich belegt.
5. **Targetings:** erstes Release **ohne** Targeting-Stammdaten, sofern kein verkaufter Katalog vorliegt (explizite Fachentscheidung dokumentieren).
6. **Adserver 15 EUR / Festpreis / Native / Registry:** bleiben **außerhalb** dieses Daten-Prep-Slices; Activate erst nach Draft-Abnahme; D4d separat.
7. **Kein Import**, bis Fachbereich die Matrix unterschrieben/geliefert hat und JSON dem D4c-2-Vertrag entspricht.

## 9. Konkrete Anforderungen an den Fachbereich

1. **Ausgefüllte Preis-Matrix** (Vorlage D4c-1 bzw. Abnahmematrix D4c-3) für das gewählte Jahr (Empfehlung zuerst **2027**): je Medium×Inventar `list_tkp` und `minimum_tkp` (Dezimal **Punkt**, max. 4 NK, beide > 0, `minimum ≤ list`).
2. Explizite Aussage: **alle 41** Paare oder dokumentierte **Teilmenge** für Revision 1.
3. Entscheidung **2026**: parallel / später / nicht im ersten Release.
4. **Plattformliste** (`code`, `name`, optional Flags) – freigegeben, keine Demo-/E2E-Codes.
5. **Targetings:** „keine im ersten Release“ **oder** vollständige Zeilen inkl. Kategorie und Aufschlag.
6. Revisionsmeta: gewünschte `revision_number`, `version`/`name`, ggf. `valid_from`.
7. Benannte Freigabe-Person / Datum für Import-Abnahme (Draft → Activate).
8. Klarstellung, ob Adserver-15-EUR-Regel später separat geliefert wird (nicht Teil Preislisten-Items).

Lieferformat: ausgefüllte CSV → technisch JSON gemäß Skeleton; Import dann über D4c-2-CLI (separater operativer Schritt nach Freigabe).

## 10. Artefakte dieses Auftrags

| Datei | Zweck |
|---|---|
| diese Datei | Analyseergebnis D4c-3 |
| `docs/readiness/import-templates/oa-tkp-abnahmematrix-d4c3-2026-2027.csv` | vollständige Abnahmematrix 82 Zeilen mit Quelle/Status/Lücken |

Unverändert belassen: D4c-1-Leermatrix, Skeletons, Registry, DB-Inhalte.

## OUT

- Produktiver Import / Dry-Run mit unfreigegebenen Fachdaten
- Registry-Freigabe / D4d
- Deploy
- Erfundene oder aus Fixtures übernommene TKPs/Plattformen
- Adserver-Surcharge-Importer

## Statuszeile

> D4c-3: Fachliche Datenlage geprüft. 41 Paare bestätigt, 0 belegte Listen-/Mindest-TKPs, Plattformen/Targetings offen. Import-CLI bereit, Import-Readiness fachlich NOT READY. STOP vor Import/Merge/Deploy/Registry.
