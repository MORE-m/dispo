# PO-BLP502-1 – Datenlieferung Spotproduktion (operativ)

Status: **Lokal umgesetzt** (nur `dispo_mat_core`; Deploy offen)
Stand: 9. Oktober 2026
Basis: `origin/main` / `dispo-main` @ `157d8a290982077cff709c163818ac1a0f02cdd1`
(Merge PR [#131](https://github.com/MORE-m/dispo/pull/131);
Post-Merge-CI [37846313463](https://github.com/MORE-m/dispo/actions/runs/37846313463) SUCCESS)
Ziel-DB: lokale Entwicklungs-DB **`dispo_mat_core`**
Bezug: [`PO-BLP502-1`](PO-BLP502-1-produktion-sonstiges.md),
[`Auflösungsvertrag`](PO-BLP502-1-aufloesungsvertrag.md);
Daten-Readiness: [`BL-P5-02a-spot-production-data-2026-10-09`](../readiness/BL-P5-02a-spot-production-data-2026-10-09.md)

> Freigaben **A/B/C** lokal erledigt. **C** = lokale Fach-/Browser-Abnahme,
> **nicht** Deployment-Freigabe. Deploy / andere Umgebungen separat offen.
> Synthetische 150/80 bleiben Fixture-only.

## Entschiedene Admin-Zielwerte

| Inventar | Code | ID | Einzelpreis netto € | Jahr | Rabattfähig | AE-fähig |
|---|---|---:|---:|---|---|---|
| Radio Hamburg | `inv_radio_hamburg` | 3 | **600,00** | 2026 | nein | nein |
| MORE Hamburg-Kombi | `inv_more_hamburg_kombi` | 1 | **600,00** | 2026 | nein | nein |
| MORE Hamburg-Kombi+ | `inv_more_hamburg_kombi_plus` | 2 | **600,00** | 2026 | nein | nein |
| ROCK ANTENNE Hamburg | `inv_rock_antenne_hamburg` | 4 | **400,00** | 2026 | nein | nein |
| 80er 90er OLDIE ANTENNE Hamburg | `inv_80er_90er_oldie_antenne_hamburg` | 5 | **400,00** | 2026 | nein | nein |
| CARAVAN.fm | `inv_caravan_fm` | 6 | **400,00** | 2026 | nein | nein |
| ffn Hamburg Plus | `inv_ffn_hamburg_plus` | 9 | **400,00** | 2026 | nein | nein |
| RADIO BOLLERWAGEN DAB+ Hamburg | `inv_radio_bollerwagen_dab_plus_hamburg` | 10 | **400,00** | 2026 | nein | nein |

Produktionsart: Spotproduktion. Pflege: Admin-Modul (Writer-Lifecycle).
Spätere Änderungen: Nachfolgerversion; Pins/Snapshots erhalten.

## Freigaben

- [x] **A** Lesende Prüfung Ziel-DB
- [x] **B** Migration BL-P5-02a (drei Pfade) + Admin-Pflege alle acht
- [x] **C** Lokale Fach-/Browser-Abnahme Port **8059**
- [ ] Deploy / andere Umgebungen (separat; **nicht** Freigabe C)

## Lokales Ergebnis (Kurz)

| Thema | Ergebnis |
|---|---|
| Sicherung | `local-backups/bl-p5-02a-20261009-103516/` (nicht committen) |
| Migrationen | drei DONE; Tabellen + AE-/Rabatt-Spalten |
| Active-Listen | 8× Spotproduktion 2026; 3×600 / 5×400; Flags false/false; v1 |
| Audit | 16 Events `created`/`activated` (Akteur `admin@example.com`) |
| Abnahme Calc | **#10** / K-2026-00010 (RH+ROCK); **#11** Menge 0; **#12** Inventarwechsel |
| Dispo | **#8** / `DA-2026-00010-01` mit 2× S-Zeilen |
| Kontrolle | 2×600=1200; 3×400=1200; Summe Prod. 2400; Medien 16800+5100=21900 |
| Bestand Calc 1–9 / Dispo 1–7 | `updated_at` unverändert |

Details: Daten-Readiness + [`reviews/spot-production-data-readiness`](../reviews/spot-production-data-readiness/).
