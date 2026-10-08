# PO-BLP501A-1 – Datenlieferung Trailer × Average (operativ)

Status: **Lokal umgesetzt** (nur `dispo_mat_core`; Deploy offen)
Stand: 8. Oktober 2026
Basis: `origin/main` @ `d97a5aefc3948e297fb510f7146e23bb38f71c14`
Ziel-DB: lokale Entwicklungs-DB **`dispo_mat_core`**
Bezug: [`PO-BLP501A-1`](PO-BLP501A-1-swf-trailer-average.md);
Daten-Readiness: [`BL-P5-01a-swf-trailer-average-data-2026-10-07`](../readiness/BL-P5-01a-swf-trailer-average-data-2026-10-07.md)

> Lokale Schreibfreigabe + Browser-Abnahme ausgeführt. **Kein** Deploy. Andere Umgebungen unberührt.

## Entschiedene Admin-Zielwerte

Quelle: ausdrückliche PO-Vorgabe (nicht RHH-Referenz-Übernahme).

| Inventar | Länge | Aufschlag |
|---|---:|---:|
| Radio Hamburg | 20 s | 30 % |
| ROCK ANTENNE Hamburg | 20 s | 30 % |
| 80er 90er OLDIE ANTENNE Hamburg | 20 s | 30 % |
| CARAVAN.fm | 20 s | 30 % |

Geltung: ab Pflegezeitpunkt für **neue** Trailer-Positionen; bestehende Positions-/Snapshot-Verträge unverändert;
je Inventar im Admin änderbar; kein Hardcode/Auto-Seed; keine zeitgesteuerte Konfiguration.

## Freigaben

- [x] Lesende Prüfung Ziel-DB
- [x] **A-write** Migration BL-P5-01a (+ ROCK-Preflight-Entblocker)
- [x] **B** Admin-Pflege 20 s / 30 % alle vier
- [x] Lokale Abnahme Calc→Dispo (serverseitig)
- [x] Lokale Browser-Abnahme Port 8056 (Admin → Wizard → Dispo + Inventarwechsel)
- [ ] **C** Deploy / andere Umgebungen

## Lokales Ergebnis (Kurz)

| Thema | Ergebnis |
|---|---|
| Migration | registriert; `kind=swf_trailer`; Average-Profil gesetzt |
| Rules | alle vier **20 / 30** |
| Spotlisten 2026 | aktiv; je **72/72** workbook-paritätisch; unverändert |
| Writer-Abnahme | K-2026-00003…00006 / DA-…00003-01…00006-01; Inventarwechsel K-2026-00007 |
| Browser-Abnahme | Calc **8**/Dispo **7** (Summe 21.320,00); Inventarwechsel Calc **9**; Calc #1/#2 unverändert |
| Sicherung | `local-backups/bl-p5-01a-20261007-223050/` (nicht committen) |

Details: Daten-Readiness + [`reviews/swf-trailer-average-data-readiness`](../reviews/swf-trailer-average-data-readiness/).
