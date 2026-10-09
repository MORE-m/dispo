# Bericht: Lokale Einrichtung + Browser-Abnahme BL-P5-02a (`dispo_mat_core`)

Stand: 9. Oktober 2026
Code: `157d8a290982077cff709c163818ac1a0f02cdd1` (PR #131).
Ziel-DB: **`dispo_mat_core`**. Prüf-Port: **8059** (`.env` / Port **8000** unverändert).
**Kein** Deploy. Sicherung: `local-backups/bl-p5-02a-20261009-103516/` (außerhalb Git).

## Ergebnis Einrichtung

| Schritt | Status |
|---|---|
| Sicherung (mysqldump + Baseline-JSON) | OK |
| Migrationen 120000 / 140000 / 200000 | OK |
| AdminWriter 8× Draft→Active (600/400, Flags false) | OK |
| Audit created/activated | 16 Events |
| Admin-UI Reload RH/ROCK | OK |

## Ergebnis Browser-Abnahme (Port 8059)

Login: `admin@example.com`. UI ohne Payload-Manipulation.

### Vertical Slice

Calc **#10** / `K-2026-00010` → Dispo **#8** / `DA-2026-00010-01`.

| | Medien | Produktion |
|---|---:|---:|
| Radio Hamburg | 16.800,00 | 2 × 600,00 = **1.200,00** |
| ROCK | 5.100,00 | 3 × 400,00 = **1.200,00** |
| Summe | **21.900,00** | **2.400,00** zusätzlich |

Dispo: S-Zeilen mit Preis/Menge/Betrag; Medienzeilen separat.

### Weitere Prüfpunkte

| Prüfpunkt | Ergebnis |
|---|---|
| Menge 0 speicherbar | Calc **#11** OK (0,00 € / Pin 600) |
| Inventarwechsel RH→ROCK Menge 2 | Calc **#12**: 1.200→**800**; Pin Liste #4 |
| Prod-Rabatt/AE = 0 | DB bestätigt; auch mit Auftrags-AE 15 % |
| Bestand Calc 1–9 / Dispo 1–7 | unverändert |

Hinweis: Für fehlende Vite-Manifest-Einträge der Produktionspreis-UI wurde lokal
`npm run build` ausgeführt (`public/build` gitignored; kein Source-Commit).

## Offen

Deploy und alle Nicht-Lokal-Umgebungen. `BL-P5-02`/`AT-11` teilweise.
