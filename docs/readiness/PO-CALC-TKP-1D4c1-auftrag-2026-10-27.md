# PO-CALC-TKP-1D4c-1 – OA-TKP Go-live-Stammdaten und Import-Readiness

Stand: 27. Oktober 2026 (Analyse / Vorbereitung – **kein Import, kein D4d**)  
Base: `2364085b0b387a7b68199f9a4f6db7fca8e0ad57` (PR #192 gemergt)  
Post-Merge-CI: `40539908691` SUCCESS  
Live-DB-Stichprobe: `dispo_mat_core` (lokal)

## Ziel

Fachliche und technische Abnahme der Go-live-Stammdaten vorbereiten, damit echte
Preislisten, Plattformen und optionale Targetings anschließend **kontrolliert**
importiert werden können.

## Release-Schutz (unverändert)

| Signal | Status |
|---|---|
| Registry `oa_tkp` × `tkp` | `planned` |
| `current_released_version` | `null` |
| `v1` | `implemented` |
| `allowsHttpMutation()` | `false` |
| HTTP Store/Update | geschlossen (422 ohne Release) |
| Wizard-Save | an Registry gekoppelt (geschlossen) |
| Budget- / SO-TKP | gesperrt |
| LiveBookability OA | nicht freigegeben |

## Live-Ist (lokal `dispo_mat_core`)

| Objekt | Anzahl |
|---|---|
| OA-Kernmedien aktiv + Assignment `tkp`/`oa_tkp` | 5 |
| Aktive Medium×Inventar-Regeln (OA-Kern) | **41** |
| `oa_tkp_price_lists` / items | **0 / 0** |
| `oa_platforms` | **0** |
| `oa_targeting_definitions` | **0** |
| `native_ad` Assignment `oa_tkp` | **kein** |

## Medium-/Inventar-Matrix (41)

### Kernmedien

| Code | Bezeichnung | Assignment | Freigabe-Vorschlag |
|---|---|---|---|
| `pre_stream` | Pre-Stream | `tkp` × `oa_tkp` aktiv | erstes Release (sofern Fachliste Preise liefert) |
| `in_stream` | In-Stream | `tkp` × `oa_tkp` aktiv | erstes Release |
| `pre_stream_influencer` | Pre-Stream Influencer | `tkp` × `oa_tkp` aktiv | erstes Release |
| `in_stream_influencer` | In-Stream Influencer | `tkp` × `oa_tkp` aktiv | erstes Release |
| `midroll_music_environment` | Mid-Roll Spotify / Deezer / Youtube Musikumfeld | `tkp` × `oa_tkp` aktiv | erstes Release (1 Inventar) |
| `native_ad` | Native-Ad | **kein** OA-TKP-Assignment | **OUT** |

### Inventare der vier Stream-Medien (je 10)

| Inventar-Code | Bezeichnung |
|---|---|
| `inv_80er_90er_oldie_antenne_hamburg` | 80er 90er OLDIE ANTENNE Hamburg |
| `inv_caravan_fm` | CARAVAN.fm |
| `inv_ffn_hamburg_plus` | ffn Hamburg Plus |
| `inv_more_hamburg_kombi` | MORE Hamburg-Kombi |
| `inv_more_hamburg_kombi_plus` | MORE Hamburg-Kombi+ |
| `inv_more_kombi_online_audio` | MORE-Kombi Online Audio |
| `inv_more_kombi_podcast` | MORE-Kombi Podcast |
| `inv_radio_bollerwagen_dab_plus_hamburg` | RADIO BOLLERWAGEN DAB+ Hamburg |
| `inv_radio_hamburg` | Radio Hamburg |
| `inv_rock_antenne_hamburg` | ROCK ANTENNE Hamburg |

Booking-Code der Regeln: `Online Audio (UA)`.

### Midroll

| Medium | Inventar-Code | Bezeichnung |
|---|---|---|
| `midroll_music_environment` | `inv_more_kombi_online_audio` | MORE-Kombi Online Audio |

### Preisliste / Fachwerte

Für **alle 41** Paare: aktive Zuordnung vorhanden, **Listen-/Mindest-TKP fehlen**
(blockierend für produktives Pricing). Keine neuen Zuordnungen anlegen.

Vollständige Liefermatrix:  
`docs/readiness/import-templates/oa-tkp-price-matrix-2026-2027.csv`

## Preisjahre 2026 / 2027

Runtime-Auswahl: `PriceListCalendar::currentYear()` / `nextYear()` → **2026 / 2027**.

Pro Jahr gilt:

- max. **eine** `active`-Revision (App + Resolver fail-closed bei 0 oder >1)
- Items decken freizugebende Medium×Inventar-Paare ab
- `list_tkp` > 0, `minimum_tkp` > 0, `minimum_tkp ≤ list_tkp`
- Dezimal: Punkt, max. 4 NK, keine Floats, kein stilles Runden
- Versionsidentität: `year` + `revision_number` (+ `version`/`name` fachlich)
- Historie: alte Revision vor Activate **archivieren** (Importer auto-archiviert nicht)
- Pinning: bestehende Calc behält Snapshots; Rebind nur bei Jahr/Medium/Inventar-Wechsel + Expected-Token

**Keine erfundenen TKPs.** Zellen in der Vorlage bleiben leer bis zur Fachfreigabe.

## Plattformen

### Vertrag

| Feld | Pflicht Import | Runtime |
|---|---|---|
| `code` | ja (Unique) | Lookup / Snapshot |
| `name` | ja | Snapshot in Allocation |
| `is_active` | default true | neue inaktive abgelehnt; Pin erlaubt |
| `sort` | default 0 | Wizard-Sortierung |
| `is_adserver_relevant` | default false | Kennzeichnung; kein Pricing |

Wizard lädt aktive `oa_platforms`. Allocation **Pflicht** (≥1); Summe Impressions = Positions-Impressions. Snapshot speichert Code/Name/Impressions.

### Belegte operative Namen

**Keine.** Lokal 0 Zeilen. Spotify/Deezer/YouTube nur im Midroll-**Medium**-Namen, nicht als Plattform-Stammdaten. E2E-/Fixture-Codes sind **nicht** operativ.

Vorlage: `docs/readiness/import-templates/oa-platforms.template.csv`

## Targetings (optional im ersten Release)

| Feld | Regel |
|---|---|
| `code` / `name` | Pflicht bei Lieferung |
| `category` | nur `technisch` \| `dmp` |
| `tkp_surcharge` | absolut, ≥ 0, max. 4 NK |
| `revision_number` | ≥ 1; Runtime wählt höchste aktive |
| `is_active` / `valid_from` / `valid_to` | optional |

Keine fiktiven Aufschläge. Ohne Lieferung: Release ohne Targeting-Auswahl möglich (Engine mit leerer Selection).

Vorlage: `docs/readiness/import-templates/oa-targetings.template.csv`

## Importprozess (bestehend)

Services: `OaPlatformImporter`, `OaTargetingDefinitionImporter`, `OaTkpPriceListImporter`.  
CLI/Admin-Einstieg: **fehlt**. Keine manuellen SQL-Inserts.

Empfohlener Ablauf:

1. Mat-Core/Katalog unverändert (41 Paare)
2. Plattformen Dry-Run → Write
3. Optional Targetings Dry-Run → Write
4. Preisliste `status=draft` Dry-Run → Write (vollständige Items)
5. Fachliche Prüfung Draft
6. Vor Activate: andere aktive Revision desselben Jahres archivieren
7. Activate (`status=active`) – genau eine active/Jahr
8. Resolver-Stichprobe je Jahr/Medium/Inventar
9. Registry-Freigabe = **separates D4d**, nicht Teil von 1D4c-1

Rollback: nur TX-Atomizität; kein Undo-Importer → Activate erst nach Abnahme; bei Fehler neue Draft-Revision.

## Empfehlung Import-Bedienung (ohne Implementierung)

Schlanker Artisan-Einstieg sinnvoll:

```text
php artisan oa:import-platforms path.json --dry-run
php artisan oa:import-targetings path.json --dry-run
php artisan oa:import-price-list path.json --dry-run
php artisan oa:activate-price-list --year=2026 --revision=1   # fail-closed, archiviert Vorgänger nur mit --archive-previous
```

Protokoll: Exit-Code ≠ 0 bei Fehlern; stdout-Summary + Fehlerliste; optional JSON-Report-Datei.  
Keine Admin-UI nötig für den ersten Go-live, solange Fachdaten als JSON/CSV→JSON geliefert werden.

## Go-live-Checkliste

| # | Voraussetzung | Status |
|---|---|---|
| 1 | Echte Preislisten vorhanden | **blockierend** (0) |
| 2 | Aktive Preislistenrevision | **blockierend** |
| 3 | Abdeckung freizugebender Paare | **offen** (Matrix ja, Preise nein) |
| 4 | Plattformen vorhanden | **blockierend** (0; Allocation Pflicht) |
| 5 | Preislistenresolver erfolgreich | **offen** (erst nach Import) |
| 6 | HTTP Persistenz technisch abgesichert | **erfüllt** (D4b-2) |
| 7 | Wizard-Save an Registry | **erfüllt** (D4b-1) |
| 8 | Mixed Spot/OA getestet | **erfüllt** (Feature + Smoke) |
| 9 | Dispo-Handoff getestet | **erfüllt** (D3*) |
| 10 | Operativer Flight | **erfüllt** (D3d-2) |
| 11 | Material/Completion | **erfüllt** (D3d-3a) |
| 12 | Browser-Smoke OA | **erfüllt** (D3d-4) |
| 13 | Budget/SO gesperrt | **erfüllt** |
| 14 | Rollback dokumentiert | **offen** (TX ok; operatives Undo-Verfahren festlegen) |
| 15 | Registry Release | **nicht Teil** 1D4c-1 → D4d |
| 16 | Reporting | **nicht Teil** erstes Release |
| 17 | Adserver-Surcharges Runtime | **nicht Teil** erstes Release |
| 18 | Import-CLI | **offen** (empfohlen vor produktivem Import) |

## Offene Fachentscheidungen

1. Welche der 41 Paare gehören in die **erste** aktive Revision 2026 (alle vs. Teilmenge)?
2. Wird **2027** parallel als Draft oder schon active vorbereitet?
3. Konkrete Plattformliste (Codes/Namen/`is_adserver_relevant`/`sort`)
4. Targetings im ersten Release: keine / welche verkauften?
5. Revisionsnummerierung und Versionslabeling
6. Wer archiviert/aktiviert (Rolle) und ob CLI vor D4d Pflicht ist

## Kleinster sinnvoller nächster Schritt

1. **D4c-1-Doku-PR** (dieser Branch) nach PO-Abnahme mergen.  
2. **Fachbereich** füllt Vorlagen (Preise, Plattformen, optional Targetings).  
3. Technischer Folge-Slice **D4c-2** (Import-CLI) laut  
   `docs/readiness/PO-CALC-TKP-1D4c2-auftrag-2026-10-27.md` – erst nach gesonderter Freigabe implementieren.  
4. Danach erst kontrollierter Import; **D4d** weiter separat.

## OUT dieses Auftrags

- Produktiver Import
- Registry-Freigabe / D4d
- D4c-2-Implementierung
- Erfundene TKPs / Demo-Plattformen als Stammdaten
