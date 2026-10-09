# Daten-Readiness: BL-P5-02a – Operative Spotproduktionspreise

Status: **LOKAL EINGERICHTET** (nur `dispo_mat_core`; **kein** Deploy)
Stand: 9. Oktober 2026
Code-Basis: `origin/main` / `dispo-main` @ `157d8a290982077cff709c163818ac1a0f02cdd1`
(Merge PR [#131](https://github.com/MORE-m/dispo/pull/131);
Post-Merge-CI [37846313463](https://github.com/MORE-m/dispo/actions/runs/37846313463) SUCCESS)
Ziel-DB: lokale Entwicklungs-DB **`dispo_mat_core`**
PO: [`PO-BLP502-1`](../entscheidungen/PO-BLP502-1-produktion-sonstiges.md),
[`Auflösungsvertrag`](../entscheidungen/PO-BLP502-1-aufloesungsvertrag.md),
[`PO-BLP502-1-datenlieferung`](../entscheidungen/PO-BLP502-1-datenlieferung.md)
Worktree-Doku: `dispo-wt-docs-spot-production-data-readiness`
Branch: `docs/spot-production-data-readiness`

> Lokale Einrichtung ≠ Deployment. Andere Umgebungen unverändert.
> `.env` / Port **8000** / Checkout-Inhalt unberührt (nur `public/build` lokal
> neu gebaut, gitignored). Keine Dumps/Secrets im Docs-PR.

## 0. Urteil

| Ebene | Status |
|---|---|
| Code auf `main` | **ja** (`157d8a2…` / PR #131) |
| Migrationen BL-P5-02a lokal | **ausgeführt** (drei DONE) |
| Admin Active 8 Inventare | **600/400 €**, 2026, Flags false/false |
| Lokale Browser-Abnahme | **bestanden** (Port **8059**) |
| Deploy / andere Umgebungen | **nein** / unberührt |
| `BL-P5-02` / `AT-11` vollständig | **nein** (teilweise) |

---

## 1. Sicherung

| | |
|---|---|
| Pfad | `/Applications/XAMPP/xamppfiles/htdocs/dispo-tool/local-backups/bl-p5-02a-20261009-103516/` |
| Full dump | `dispo_mat_core-full.sql` (XAMPP `mysqldump`, `--skip-triggers`; „Dump completed“) |
| Logical | `baseline-before-acceptance.json`, `logical-post-acceptance.json` |
| Restore | `RESTORE.md` im Sicherungsordner |
| Commit | **nicht** – außerhalb Git-Worktrees |

---

## 2. Migrationen

| Migration | Ergebnis |
|---|---|
| `2026_10_08_120000_bl_p5_02a_spot_production` | **DONE** |
| `2026_10_08_140000_bl_p5_02a_production_line_ae_percent` | **DONE** |
| `2026_10_08_200000_bl_p5_02a_production_line_position_discounts` | **DONE** |

Gezielt per `--path=…` (keine anderen Pending). Schema inkl. `ae_percent`,
`position_discount_percent`, `position_discounts_snapshot`. Spotlisten unberührt.
Calc-/Dispo-Bestand vor Admin-Pflege unverändert (9/7).

---

## 3. Admin-Pflege (Writer-Lifecycle)

Akteur: `admin@example.com`. Je Inventar: `createDraft` → `activate`
(`ProductionPriceListAdminWriter`). Keine SQL-Inserts. Keine vorbestehenden Listen.

| ID | Inventar | Code | unit_price | Flags | version/rev | status |
|---:|---|---|---:|---|---|---|
| 1 | Radio Hamburg | `inv_radio_hamburg` | 600,00 | false/false | 1 / 1 | active |
| 2 | MORE Hamburg-Kombi | `inv_more_hamburg_kombi` | 600,00 | false/false | 1 / 1 | active |
| 3 | MORE Hamburg-Kombi+ | `inv_more_hamburg_kombi_plus` | 600,00 | false/false | 1 / 1 | active |
| 4 | ROCK ANTENNE Hamburg | `inv_rock_antenne_hamburg` | 400,00 | false/false | 1 / 1 | active |
| 5 | OLDIE ANTENNE Hamburg | `inv_80er_90er_oldie_antenne_hamburg` | 400,00 | false/false | 1 / 1 | active |
| 6 | CARAVAN.fm | `inv_caravan_fm` | 400,00 | false/false | 1 / 1 | active |
| 7 | ffn Hamburg Plus | `inv_ffn_hamburg_plus` | 400,00 | false/false | 1 / 1 | active |
| 8 | RADIO BOLLERWAGEN | `inv_radio_bollerwagen_dab_plus_hamburg` | 400,00 | false/false | 1 / 1 | active |

Genau eine Active je Inventar×Spotproduktion×2026. Audit: 8× created + 8× activated.
Admin-UI Reload (Port 8059): RH #1 = 600,00 Aktiv; ROCK #4 = 400,00 Aktiv.

---

## 4. Lokale Abnahme (9. Oktober 2026)

Prüfserver: `php artisan serve --host=127.0.0.1 --port=8059` (danach beendet).
Kennzeichnung: Präfix **„BL-P5-02a operative Abnahme“**. Testdaten belassen.

### 4a. Vertical Slice RH + ROCK (Browser)

Calc **#10** / `K-2026-00010` → Dispo **#8** / `DA-2026-00010-01`.

| Position | Medien brutto | Produktion | N/N Position |
|---|---:|---:|---:|
| Radio Hamburg 10×30 s mo_fr@8 | **16.800,00** | 2 × 600,00 = **1.200,00** | 18.000,00 |
| ROCK 10×30 s mo_fr@8 | **5.100,00** | 3 × 400,00 = **1.200,00** | 6.300,00 |
| **Summe** | **21.900,00** | **2.400,00** zusätzlich | 24.300,00 |

Dispo: eigene **S**-Zeilen (list #1 / #4); Medienzeilen ohne Produktionsverdoppelung.
Prod-Flags false → `ae_percent=0`, `position_discount_percent=0`.
Mit aktivierter Auftrags-AE (15 %) bleiben Produktionszeilen **1.200,00** (keine Prod-AE).

### 4b. Menge 0 (Playwright gegen Port 8059)

Calc **#11** / `K-2026-00011`: RH Produktion Menge 0 → Summe **0,00 €**, Einzelpreis 600,00;
Speichern/Reload OK.

### 4c. Inventarwechsel RH→ROCK Menge 2

Calc **#12** / `K-2026-00012`: vor Wechsel 2×600=**1.200,00**; nach Wechsel Pin Liste #4,
2×400=**800,00**; Save/Reload OK.

### 4d. Bestand

Calc **1–9** und Dispo **1–7**: `updated_at` unverändert gegenüber Pre-Acceptance-Baseline.

---

## 5. Freigaben

| Schritt | Status |
|---|---|
| A Lesung | erledigt |
| B Migration + Admin | **erledigt** |
| C Lokale Abnahme | **erledigt** |
| Deploy | **offen** / separat |

## 6. Grenzen

`BL-P5-02`/`AT-11` teilweise; Sonstiges/Überschreibung/andere Träger offen;
kein Deploy; Preise weiter über Admin-Nachfolger änderbar.
