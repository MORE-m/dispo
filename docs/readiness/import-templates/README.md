# OA-TKP Import-Vorlagen (PO-CALC-TKP-1D4c-1)

Nur Felddefinitionen und leere Matrizen. **Keine erfundenen Fachwerte.**

## Dateien

| Datei | Format für Importer | Zweck |
|---|---|---|
| `oa-tkp-price-matrix-2026-2027.csv` | Fachlieferung (Excel) | 82 Zeilen: 41 Paare × 2026/2027; `list_tkp`/`minimum_tkp` leer |
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
- Pflicht: `code`, `name`, `category` (`technisch`\|`dmp`), `tkp_surcharge` (≥ 0, Punkt, max. 4 NK)
- Optional: `revision_number`, `is_active`, `valid_from`, `valid_to`

## Arbeitsablauf Fachbereich

1. CSV-Matrix 2026 (und ggf. 2027) mit echten TKPs befüllen
2. Plattform-CSV befüllen; Targetings nur wenn verkauft
3. Technisch: CSV → JSON gemäß Skeleton
4. Import erst nach D4c-2-CLI / PO-Freigabe; Activate getrennt vom Draft-Import

CSV-Trenner: `;` (Excel DE). Dezimaltrenner in Werten: `.`
