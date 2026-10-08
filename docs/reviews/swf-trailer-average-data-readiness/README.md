# Bericht: Lokale Einrichtung + Browser-Abnahme BL-P5-01a (`dispo_mat_core`)

Stand: 8. Oktober 2026 (Browser-Abnahme); Einrichtung 7. Oktober 2026
Code: `d97a5aefc3948e297fb510f7146e23bb38f71c14` (PR #129).
Ziel-DB: **`dispo_mat_core`**. Prüf-Port: **8056** (`.env` / Port **8000** unverändert).
**Kein** Deploy, keine Preislistenänderung. Lokale Migration/Pflege bereits ausgeführt.
Dieser Bericht ist **lokale Abnahme**, kein reproduzierbarer CI-Test.
Sicherung: `local-backups/bl-p5-01a-20261007-223050/` (außerhalb Git, nicht im PR).

## Ergebnis Einrichtung (7.10.)

| Schritt | Status |
|---|---|
| Sicherung (mysqldump tables-only + logical JSON) | OK |
| ROCK #147 temp. 30/0 via AdminWriter | OK |
| Migration BL-P5-01a | OK → kind/Profil/nullable; Rules NULL/NULL |
| Admin 20/30 alle vier | OK |
| Serverseitige Abnahme 4× Preview→Save→Dispo + Inventarwechsel | OK |

Writer-Test-IDs: Calc 3–6 / Dispo 3–6; Inventarwechsel Calc 7.
Kontrollbeträge: 14560 / 4420 / 2080 / 260.

## Ergebnis Browser-Abnahme (8.10., Port 8056)

Login: `admin@example.com`. UI-Weg ohne Payload-/API-Manipulation.

### 1. Admin Kombinationen

| Inventar | Rule-URL | Länge | Aufschlag | Editierbar | Reload | Zuordnung |
|---|---|---:|---:|---|---|---|
| Radio Hamburg | `/administration/kombinationen/184` | 20 | 30 % | ja | ja | RH × Trailer |
| ROCK ANTENNE Hamburg | `/administration/kombinationen/147` | 20 | 30 % | ja | — | ROCK × Trailer |
| OLDIE ANTENNE Hamburg | `/administration/kombinationen/30` | 20 | 30 % | ja | — | OLDIE × Trailer |
| CARAVAN.fm | `/administration/kombinationen/65` | 20 | 30 % | ja | — | CARAVAN × Trailer (UC) |

Keine temporären Preisparameter geändert.

### 2. Wizard 4 Inventare → Preview → Save → Reload → Dispo

Neue Calc **#8** (`K-2026-00008`): Kunde/Kampagne/Produkt mit Präfix **BL-P5-01a Browser-Abnahme**.
Je Position: Trailer, 10 Spots, mo_fr, 08:00–09:00, Länge 20 s, Average; Calendar/Festpreis/Spot-Komponenten nicht angeboten. Ohne Rabatt/AE.

| Inventar | Erwartung | Preview | Save/Reload | Dispo #7 |
|---|---:|---:|---:|---|
| Radio Hamburg | 14.560,00 | 14.560,00 | 14.560,00 | 14.560,00 |
| ROCK | 4.420,00 | 4.420,00 | 4.420,00 | 4.420,00 |
| OLDIE | 2.080,00 | 2.080,00 | 2.080,00 | 2.080,00 |
| CARAVAN | 260,00 | 260,00 | 260,00 | 260,00 |
| **Summe** | **21.320,00** | **21.320,00** | **21.320,00** | (4 Positionen) |

Dispoauftrag: **#7** / `DA-2026-00008-01`; Buchung RH/ROCK/OLDIE `SWF (K)`, CARAVAN `UC`.

### 3. Inventarwechsel RH → ROCK

Neue Calc **#9** (`K-2026-00009`): RH Trailer 10 × 20 s → Mediabrutto 14.560,00 → Inventar ROCK.
Erhalten: Länge 20 s, 10 Spots, Average-Zeitraum 08:00–08:59.
Mediabrutto nach Wechsel: **4.420,00**. Save/Reload: **4.420,00**.

### 4. Bestehende Daten

Calc **#1** / **#2**: `updated_at` unverändert (2026-10-01 / 2026-10-06); Beträge unverändert.
Calc 3–7 und Dispo 3–6 nicht angefasst. Neue Testdaten (#8/#9/#7) bewusst belassen.

### Grenzen / Fehler

Keine Produktfehler gefunden. Bewusst nicht: Deploy, weitere Migration, Preislistenänderung,
Produktcode-Änderung. Docs-only-Draft-PR sichert diesen lokalen Stand.

## Offen

Deploy und alle Nicht-Lokal-Umgebungen.
