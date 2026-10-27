# OA-TKP Import-Vorlagen (1D4c-1)

Nur Felddefinitionen und leere Matrizen. **Keine erfundenen Fachwerte.**

| Datei | Zweck |
|---|---|
| `oa-tkp-price-matrix-2026-2027.csv` | 82 Zeilen (41 Paare × Jahre 2026/2027), TKP-Spalten leer |
| `oa-tkp-price-list.import.skeleton.json` | JSON für `OaTkpPriceListImporter` |
| `oa-platforms.template.csv` / `.import.skeleton.json` | Plattformlieferung |
| `oa-targetings.template.csv` / `.import.skeleton.json` | optionale Targetings |

CSV-Trenner: `;` (Excel DE). Dezimaltrenner in Werten: `.`

Nach Fachfreigabe: CSV prüfen → JSON erzeugen → Service-/CLI-Import mit `--dry-run` → Draft → Activate.
