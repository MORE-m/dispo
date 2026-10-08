# Daten-Readiness: BL-P5-01a – Operative Trailer-Daten (lokal eingerichtet)

Status: **LOKAL EINGERICHTET** (nur `dispo_mat_core`; **kein** Deploy)
Stand: 8. Oktober 2026
Code-Basis: `origin/main` @ `d97a5aefc3948e297fb510f7146e23bb38f71c14`
(Merge PR [#129](https://github.com/MORE-m/dispo/pull/129);
Post-Merge-CI [37668554937](https://github.com/MORE-m/dispo/actions/runs/37668554937) SUCCESS)
Ziel-DB: lokale Entwicklungs-DB **`dispo_mat_core`**
PO: [`PO-BLP501A-1`](../entscheidungen/PO-BLP501A-1-swf-trailer-average.md),
[`PO-BLP501A-1-datenlieferung`](../entscheidungen/PO-BLP501A-1-datenlieferung.md)
Worktree-Doku: `dispo-wt-docs-swf-trailer-average-data-readiness`

> Lokale Einrichtung ≠ Deployment. Andere Umgebungen unverändert.
> `.env` / Port 8000 unberührt. Keine Dumps/Secrets im Docs-PR.

## 0. Urteil

| Ebene | Status |
|---|---|
| Code auf `main` | **ja** (`d97a5ae…`) |
| Migration BL-P5-01a lokal | **ausgeführt** und registriert |
| Trailer `kind` / Average-Profil | **`swf_trailer`** / `average`→`swf_trailer` |
| Admin-Werte RH/ROCK/OLDIE/CARAVAN | **20 s / 30 %** |
| Spotlisten 2026 | unverändert aktiv, 72/72, Workbook-paritätisch |
| Lokale Abnahme Calc→Dispo | **bestanden** (Writer 4 Inventare + Inventarwechsel) |
| Browser-Abnahme Admin→Wizard→Dispo | **bestanden** (Port 8056; Calc **8**/Dispo **7**, Inventarwechsel Calc **9**) |
| Deploy / andere Umgebungen | **nein** / unberührt |

---

## 1. Sicherung

| | |
|---|---|
| Pfad | `/Applications/XAMPP/xamppfiles/htdocs/dispo-tool/local-backups/bl-p5-01a-20261007-223050/` |
| Full dump | `dispo_mat_core-full.sql` (XAMPP `mysqldump`, tables-only, `--skip-triggers`; Exit 0, „Dump completed“) |
| Critical tables | `critical-tables.sql` |
| Logical JSON | `logical-pre-mutation.json`, Schema `surcharge_percent-schema-before.json` |
| ROCK vor Temp-Reset | `rock-rule-before-temp-reset.json` |
| Restore | siehe `RESTORE.md` im Sicherungsordner (`mysql … < dispo_mat_core-full.sql`) |
| Commit | **nicht** – Ordner außerhalb der Git-Worktrees |

---

## 2. Migrationsresultat

| Prüfpunkt | Nachher |
|---|---|
| Migration | `2026_10_07_120000_bl_p5_01a_swf_trailer_catalog_and_nullable_surcharge` **DONE** |
| Pending | **keine** |
| `surcharge_percent` | nullable, Default `null` |
| Medium `trailer_station_voice` | `kind=swf_trailer` |
| Kategorie-Methode | `average` + `engine_profile_key=swf_trailer` |
| Kategorie-Default | `average` |
| Trailer-Regeln direkt nach `up()` | alle vier **NULL/NULL** (fail-closed) |
| Spot-Medien | unverändert (`spot_classic` usw.) |
| Preflight | ROCK zuvor per AdminWriter temporär 30/0; `kind` **nicht** vorab gesetzt |

---

## 3. Vorher / Nachher je Inventar (Trailer-Regel)

| Inventar | Rule | Vorher (lesen) | Nach Temp-Reset | Nach Migration | Nach Admin-Pflege |
|---|---|---|---|---|---|
| Radio Hamburg | #184 | 30 / 0 | — | NULL / NULL | **20 / 30** |
| ROCK ANTENNE Hamburg | #147 | **20 / 30** | **30 / 0** | NULL / NULL | **20 / 30** |
| 80er 90er OLDIE ANTENNE Hamburg | #30 | 30 / 0 | — | NULL / NULL | **20 / 30** |
| CARAVAN.fm | #65 | 30 / 0 | — | NULL / NULL | **20 / 30** |

Buchung/Planung unverändert: RH/ROCK/OLDIE `SWF (K)` / `disposition`; CARAVAN `UC` / `disposition`.
Disc/AE unverändert true/true. Spotpreislisten **nicht** verändert.

Pflegeweg: `InventoryMediumRuleAdminWriter::update` (Lock/Audit), äquivalent Kombinationstabelle;
Akteur lokal `admin@example.com`.

---

## 4. Lokale Abnahme

Kennzeichnung: `customer_name` / Campaign / Product mit Präfix **„BL-P5-01a lokale Abnahme“**.
Kontrollfenster: 10 Einheiten, `mo_fr`, Stunde 8–9, Länge 20, Aufschlag 30 %.
Formel: `10 × Sekundenpreis(mo_fr,8) × 20 × 1,30`; `length_index` = 100.
Weg: `CalculationWriter` Preview → Create (Save) → Reload aus DB → `DispoOrderWriter`
(kein Port-8000-Neustart; kein Browser nötig für diese serverseitige Abnahme).

| Inventar | Zellpreis mo_fr@8 | Erwartung | Calc | Dispo | IDs |
|---|---:|---:|---|---|---|
| Radio Hamburg | 56.0000 | **14560.00** | K-2026-00003 | DA-2026-00003-01 | Calc **3** / Dispo **3** |
| ROCK ANTENNE Hamburg | 17.0000 | **4420.00** | K-2026-00004 | DA-2026-00004-01 | Calc **4** / Dispo **4** |
| 80er 90er OLDIE ANTENNE Hamburg | 8.0000 | **2080.00** | K-2026-00005 | DA-2026-00005-01 | Calc **5** / Dispo **5** |
| CARAVAN.fm | 1.0000 | **260.00** | K-2026-00006 | DA-2026-00006-01 | Calc **6** / Dispo **6** |

Zusätzlich Inventarwechsel RH→ROCK: Calc **7** / K-2026-00007
Preview RH 14560.00 → nach Wechsel Preview/Save ROCK 4420.00; Pin Preisliste #5; Länge/Aufschlag 20/30; Index 100.

Bestehende Kalkulationen **1** und **2** weiterhin vorhanden (nicht als Abnahme-Testdaten angefasst).
Detail: `local-acceptance-results.json` im Sicherungsordner.

### 4b. Browser-Abnahme (8. Oktober 2026)

Prüfserver: `php artisan serve --host=127.0.0.1 --port=8056` (nur für diese Abnahme; danach beendet).
Code: `d97a5aef…`. DB: `dispo_mat_core`. `.env` / Port 8000 unverändert.

| Prüfpunkt | Ergebnis |
|---|---|
| Admin 4× Trailer-Regeln 20 s / 30 %, editierbar, Reload RH | OK |
| Wizard 4 Inventare Trailer Average, ohne Calendar/Festpreis/Komponenten | OK |
| Preview-Beträge 14560 / 4420 / 2080 / 260 = **21320** | OK |
| Save → Reload Calc **#8** | OK |
| Dispoauftrag **#7** / `DA-2026-00008-01` alle 4 Positionen | OK |
| Inventarwechsel RH→ROCK Calc **#9** (14560→4420, Länge/Menge/Zeitraum erhalten) | OK |
| Calc **#1** / **#2** unverändert | OK |

Kennzeichnung Browser-Testdaten: Präfix **„BL-P5-01a Browser-Abnahme“** bzw. Inventarwechsel.
Nicht gelöscht. Protokoll: [`reviews/swf-trailer-average-data-readiness/README.md`](../reviews/swf-trailer-average-data-readiness/README.md).

---

## 5. Gültigkeit

Keine neuen Gültigkeitsfelder. Werte gelten für **neue** Trailer-Positionen ab Admin-Pflege;
Snapshots bestehender Vorgänge unverändert. Preisjahr über aktive Spotliste 2026.

---

## 6. Verbleibende Blocker / Grenzen

| Thema | Status |
|---|---|
| Deploy / Staging / Prod | **nicht** freigegeben |
| Andere Umgebungen | unberührt – Migration/Pflege nur lokal |
| Browser-UI-Abnahme Port 8056 | **bestanden** (8.10.2026) |
| Formale Deploy-Freigabe C | offen |

---

## 7. Bewusst nicht gemacht

- Deploy, Seeds, weitere Migrationen, Produktcode-Änderung
- Änderung an `.env` / Port-8000-Prozess
- Spotlisten-Mutation
- Folgeslices (weitere SWF, Produktion, Standardangebote)
- Hardcodes / Auto-Seed der 20/30-Werte
- Aufnahme von DB-Dumps, Sicherungen oder Zugangsdaten in Git
