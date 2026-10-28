# OA-TKP Import-Vorlagen (PO-CALC-TKP-1D4c-1 / D4c-3)

Nur Felddefinitionen und leere Matrizen bzw. Abnahmestatus. **Keine erfundenen Fachwerte.**

Analyse D4c-3 (28.10.2026): noch **keine** belegten Listen-/Mindest-TKPs im Repo – siehe  
`docs/readiness/PO-CALC-TKP-1D4c3-auftrag-2026-10-28.md`.

## Dateien

| Datei | Format für Importer | Zweck |
|---|---|---|
| `oa-tkp-price-matrix-2026-2027.csv` | Fachlieferung (Excel) | 82 Zeilen: 41 Paare × 2026/2027; `list_tkp`/`minimum_tkp` leer |
| `oa-tkp-abnahmematrix-d4c3-2026-2027.csv` | Fach-Abnahme | dieselbe Matrix + Quelle/Freigabestatus/fehlende Angaben (D4c-3) |
| `oa-tkp-price-list.import.skeleton.json` | **Objekt** → `OaTkpPriceListImporter::import($payload)` | JSON-Import; `items` aus Matrix befüllen; `status` zuerst `draft` |
| `oa-platforms.template.csv` | Fachlieferung | Plattformzeilen |
| `oa-platforms.import.skeleton.json` | **JSON-Array** → `OaPlatformImporter::import($rows)` | leeres Array `[]` |
| `oa-targetings.template.csv` | Fachlieferung | optionale Targetings |
| `oa-targetings.import.skeleton.json` | **JSON-Array** → `OaTargetingDefinitionImporter::import($rows)` | leeres Array `[]` |

## Validierungsregeln (kurz)

### Preisliste
- `year`: 2000–2100 (Go-live: 2026 und/oder 2027)
- `revision_number` ≥ 1
- `status`: `draft` \| `active` \| `archived` — Fachimport startet als **`draft`**
- `items[].medium_code`: nur `pre_stream`, `in_stream`, `pre_stream_influencer`, `in_stream_influencer`, `midroll_music_environment` (`native_ad` OUT)
- `items[].inventory_code`: muss in `inventories` existieren
- `list_tkp` / `minimum_tkp`: **> 0**, Dezimal **Punkt**, max. **4** Nachkommastellen, kein Float, `minimum_tkp ≤ list_tkp`
- Max. **eine** `active`-Revision pro Jahr (Activate = D4c-2 / operativer Schritt)

### Plattformen
- Pflicht: `code`, `name`
- Optional: `is_active` (default true), `sort` (default 0), `is_adserver_relevant` (default false)
- Keine Demo-/E2E-Codes

### Targetings (optional)
- Pflicht: `code`, `name`, `category` (`technisch`\|`dmp`), `tkp_surcharge` (Listen-Aufschlag ≥ 0, Punkt, max. 4 NK)
- `minimum_tkp_surcharge`: Mindest-Aufschlag ≥ 0, max. 4 NK, `≤ tkp_surcharge`; **fehlend → `0.0000`** (D4c-4a)
- Optional: `revision_number`, `is_active`, `valid_from`, `valid_to`
- **Hinweis:** Mindestaufschläge werden erst ab **D4c-4b** in Engine/Under-Min verwendet; Import speichert sie bereits.

## Arbeitsablauf Fachbereich

1. CSV-Matrix 2026 (und ggf. 2027) mit echten TKPs befüllen
2. Plattform-CSV befüllen; Targetings nur wenn verkauft
3. Technisch: CSV → JSON gemäß Skeleton
4. Dry-Run und Import über D4c-2-CLI (siehe unten); Activate getrennt vom Draft-Import
5. Erst nach erfolgreicher Aktivierung und Resolver-Prüfung: separates D4d-Go/No-Go

CSV-Trenner: `;` (Excel DE). Dezimaltrenner in Werten: `.`

## D4c-2 CLI (operativ)

```bash
php artisan oa:import-platforms path.json --dry-run
php artisan oa:import-platforms path.json

php artisan oa:import-targetings path.json --dry-run
php artisan oa:import-targetings path.json

php artisan oa:import-price-list path.json --dry-run   # nur status=draft
php artisan oa:import-price-list path.json

php artisan oa:activate-price-list --year=2026 --revision=1 --dry-run
php artisan oa:activate-price-list --year=2026 --revision=1 --archive-previous
```

- Preislisten-CLI akzeptiert **nur** `status=draft`; überschreibt keine `active`/`archived` Revision.
- Aktivierung ist **nie** Teil des Imports; Vorgänger nur mit `--archive-previous`.
- Keine Demo-/Skeleton-Werte als operative Preise übernehmen.
- Rollback fehlerhafter Aktivierung: neue Draft-Revision importieren, dann Activate mit `--archive-previous` (historische Pins bleiben).
