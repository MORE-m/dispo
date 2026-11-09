# PO-CALC-TKP-1D4d-PRE-2 – OA-TKP Preislisten 2026/2027 Datenbereitstellung

Stand: 9. November 2026
Basis: `main` `c7800600c91a93cb7bdac396089a7f38f24250d4` (PR #218 / D4d + PRE-1 gemergt)
Branch: `feat/po-calc-tkp-1d4d-pre2-price-lists-2026-2027`
Worktree: `dispo-wt-po-calc-tkp-1d4d-pre2`
Vorgänger: `docs/readiness/PO-CALC-TKP-1D4d-PRE-1-po-entscheidung-2026-11-09.md`

Lokaler Datenlauf auf `dispo_mat_core`. **Keine Aktivierung.** Kein Registry-Flip.
Kein D4d-WIRE / REL / E2E. Kein OA-004-/OA-003-Code. Kein Deploy.
**D4d bleibt NO-GO.** Registry `oa_tkp` × `tkp` bleibt `planned`.

---

## Ziel

Fachlich freigegebene Draft-`2027.1` validieren und daraus eine **separate**
Draft-Preisliste **2026.1** mit exakt denselben 41 Preiswerten anlegen.
Beide Jahresstände vollständig vergleichen; späteren Aktivierungsschritt nur
analysieren.

---

## Ausgangszustand (Pre-State)

| Prüfung | Ist |
|---|---|
| Registry `oa_tkp`/`tkp` | `pair_status=planned`, `current_released_version=null`, `v1=implemented` |
| Preisliste 2027 | id=1, `2027.1`, Revision 1, `draft`, `published_at=NULL`, 41 Items |
| Preisliste 2026 | keine |
| Active gesamt | 0 |
| Targetings | `dmp` 25.0000/5.0000 aktiv; `geo` 15.0000/3.0000 aktiv |
| `oa_adserver_surcharges` | 0 |
| Midroll | `list_tkp=80.0000`, `minimum_tkp=20.0000` (kein +15) |

---

## 2027.1 Validierung

- 41 Items, keine Duplikate, kein `native_ad`
- Medien: Pre-/In-Stream je 10, Influencer je 10, Midroll 1
- alle `list_tkp > 0`, `minimum_tkp > 0`, `minimum_tkp ≤ list_tkp`
- Decimal-Werte exakt (`decimal(16,4)`), keine Float-Arithmetik

---

## Lieferdatei

`docs/readiness/import-templates/oa-tkp-price-list.2026.1-pre2.import.json`

- aus Live-`2027.1` (DB id=1) erzeugt; nur Jahr/Versionskontext auf 2026
- Version `2026.1`, Revision 1, `status=draft`, `valid_from=2026-01-01`
- Preiswerte byte-/decimal-identisch zu 2027.1
- Vor-Import-Parität: **41 / 41 identisch** (auch vs. D4c-4e-Prepared-File)

Importvertrag unverändert: D4c-2 `oa:import-price-list` (nur Draft).

---

## Backup

Pfad (nicht in Git):

`local-backups/po-calc-tkp-1d4d-pre2-20261109-173428/`

| Datei | Inhalt |
|---|---|
| `dispo_mat_core-full.sql` | vollständiger mysqldump |
| `oa-tkp-relevant-tables.sql` | Preislisten/Items, Targetings, Adserver |
| `registry-snapshot.txt` | Code-Registry-Stand |
| `pre-state.txt` | Pre-State-Zusammenfassung |
| `RESTORE.md` | Restore-Hinweis |

SHA-256 Full-Dump:
`c27af8b0e045c1d9b72386f04d67e757d05e66bf1aef42256849191fa0ece139`

Keine Zugangsdaten im Backup.

---

## Dry-Run

```bash
php artisan oa:import-price-list \
  docs/readiness/import-templates/oa-tkp-price-list.2026.1-pre2.import.json \
  --dry-run
```

Ergebnis: `Dry-Run OK`, `year=2026`, `revision=1`, `items_written=41`,
`price_list_id=null`. DB unverändert (1 Liste 2027.1, 41 Items, Targetings 2,
Adserver 0, Registry planned).

---

## Write

```bash
php artisan oa:import-price-list \
  docs/readiness/import-templates/oa-tkp-price-list.2026.1-pre2.import.json
```

Ergebnis: `Draft-Import OK`, `price_list_id=2`, `items_written=41`.

| Jahr | ID | Version | Revision | Status | Items | published_at |
|---|---:|---|---:|---|---:|---|
| 2026 | 2 | `2026.1` | 1 | `draft` | 41 | NULL |
| 2027 | 1 | `2027.1` | 1 | `draft` | 41 | NULL |

Active: **0** für beide Jahre. 2027.1 unverändert. Targetings unverändert.
Adserver 0. Registry `planned`. Midroll beider Jahre 80.0000 / 20.0000.

---

## 2026 ↔ 2027 Parität (Post-Write)

| Prüfung | 2026 | 2027 | Ergebnis |
|---|---:|---:|---|
| Preislisten | 1 Draft | 1 Draft | OK |
| Items | 41 | 41 | OK |
| Pre-Stream | 10 | 10 | OK |
| In-Stream | 10 | 10 | OK |
| Pre-Stream Influencer | 10 | 10 | OK |
| In-Stream Influencer | 10 | 10 | OK |
| Midroll | 1 | 1 | OK |
| Preiswert-Parität | 41/41 | 41/41 | OK |

Schlüssel `medium_code + inventory_code + list_tkp + minimum_tkp`:
**41 / 41 identisch**.

---

## Aktivierungs-Readiness (nur Analyse)

**Nicht ausgeführt als Write.**

Dry-Run:

```bash
php artisan oa:activate-price-list --year=2026 --revision=1 --dry-run
php artisan oa:activate-price-list --year=2027 --revision=1 --dry-run
```

| Jahr | Active heute | Kandidat | Dry-Run |
|---|---|---|---|
| 2026 | keine | `2026.1` (id=2, rev 1) | OK, `archived_previous=0`, items=41 |
| 2027 | keine | `2027.1` (id=1, rev 1) | OK, `archived_previous=0`, items=41 |

Vertrag: höchstens eine aktive OA-TKP-Liste je Jahr. Aktivierung bleibt
Folgeslice.

### Wizard / Resolver

`OaTkpPriceResolver::resolveActive` ist jahrbezogen und fail-closed ohne
`status=active`. Read-only bestätigt: 2026 und 2027 liefern aktuell
„Keine aktive OA-TKP-Preislistenrevision“. Nach späterer Aktivierung von
`2026.1` bzw. `2027.1` kann der Wizard das jeweilige Jahr auflösen
(unabhängig voneinander). Kein Registry-Release in PRE-2.

---

## OA-004 / OA-003

- OA-004 weiter **offen**; keine Surcharge-Daten; kein +15; Midroll 80.0000
- OA-003 außerhalb; erster Release-Scope bleibt Normal-TKP

---

## Repo-Diff (erwartet)

- Lieferdatei `oa-tkp-price-list.2026.1-pre2.import.json`
- Readiness-/Statusdokumentation
- Keine Runtime-Änderung

---

## STOP

PRE-2 endet **vor** jeder Preislisten-Aktivierung.

Nicht ausgeführt: `oa:activate-price-list` (Write), Registry-Flip, D4d-WIRE,
D4d-REL, D4d-E2E, OA-004, OA-003, Deploy.

**D4d bleibt NO-GO.** Nächster empfohlener Slice: Aktivierung 2026/2027
(separat, mit ausdrücklichem GO) – hier nicht gestartet.
