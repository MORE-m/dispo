# PO-CALC-TKP-1D4d-PRE-3 – Aktivierungs-Ausführung 2026/2027

Stand: 9. November 2026  
Basis `main`: `582ad587f442d01550ace0e52df86d4e9bb78931` (#222 Merge)  
Gate: Post-Merge-CI #220 Run [`42695376594`](https://github.com/MORE-m/dispo/actions/runs/42695376594)
(`ci` / `mysql` / `e2e-spt008` success)  
Vorgänger-Readiness: `docs/readiness/PO-CALC-TKP-1D4d-PRE-3-activation-readiness-2026-11-09.md`  
OA004-DEC: 15 EUR = interne Kosten; Midroll 80/20; kein Verkaufsaufschlag.

**Lokal auf `dispo_mat_core` ausgeführt.** Kein Registry-Flip. Kein D4d-REL.
Kein Deploy. Registry bleibt `planned`.

---

## Backup

Pfad:

`local-backups/po-calc-tkp-1d4d-pre3-20261109-214406/` (nicht in Git)

| Artefakt | Inhalt |
|---|---|
| `dispo_mat_core-full.sql` | Full-Dump |
| `oa-tkp-relevant-tables.sql` | OA-Preislisten/Items/Targetings/Adserver/Platforms |
| `registry-snapshot.txt` | `planned` / `null` / `v1=implemented` |
| `pre-state.txt` | beide Jahre Draft, Active=0, Midroll 80/20 |
| `RESTORE.md` | Restore-Hinweise |
| `SHA256SUMS.txt` | Verifiziert OK |

Full-Dump SHA-256:

`c623d6ee3e32ffc50c7bbc0f853217d218b1c916f108c10fa511b2fafa8b14fa`

---

## Pre-State (vor Write, bestätigt)

| Prüfung | Ist |
|---|---|
| Registry `oa_tkp`×`tkp` | `planned` / `null` / `v1=implemented` |
| 2026 id=2 | `2026.1` rev=1 **draft** 41 Items `published_at=NULL` lock=1 |
| 2027 id=1 | `2027.1` rev=1 **draft** 41 Items `published_at=NULL` lock=1 |
| Active 2026/2027 | 0 / 0 |
| Parität | 41/41 |
| Midroll | 80.0000 / 20.0000 beide Jahre |
| Targetings | `dmp` 25/5, `geo` 15/3 aktiv |
| Adserver-Zeilen | 0 |

---

## 2026

### Dry-Run

```text
OA-TKP-Preisliste Activate Dry-Run OK: year=2026 revision=1 id=2 archived_previous=0 items=41 dry_run=yes
```

### Write

```text
OA-TKP-Preisliste Activate OK: year=2026 revision=1 id=2 archived_previous=0 items=41 dry_run=no
```

### Readback (final, nach Wiederherstellung)

| Feld | Wert |
|---|---|
| version | `2026.1` |
| revision | 1 |
| status | **active** |
| items | 41 (unverändert) |
| published_at | `2026-11-09 21:28:31` |
| lock_version | 2 |
| active count Jahr 2026 | **1** |
| Midroll | 80.0000 / 20.0000 |
| Targetings | unverändert |
| Registry | weiterhin `planned` |
| Resolver 2026 | → `2026.1` |

---

## 2027

### Dry-Run

```text
OA-TKP-Preisliste Activate Dry-Run OK: year=2027 revision=1 id=1 archived_previous=0 items=41 dry_run=yes
```

### Write

```text
OA-TKP-Preisliste Activate OK: year=2027 revision=1 id=1 archived_previous=0 items=41 dry_run=no
```

### Readback

| Feld | Wert |
|---|---|
| version | `2027.1` |
| revision | 1 |
| status | **active** |
| items | 41 |
| published_at | `2026-11-09 21:28:32` |
| lock_version | 2 |
| active count Jahr 2027 | **1** |
| Resolver 2027 | → `2027.1` |

---

## Zwischenfall / Wiederherstellung

Nach dem ersten erfolgreichen Activate-Lauf (published_at 21:25:18 / 21:25:29)
waren `oa_tkp_price_lists` und `oa_tkp_price_list_items` kurzzeitig **leer**
(0 Zeilen). Ursache: versehentlicher Pest-Lauf ohne `DB_DATABASE=dispo_test`
gegen die `.env`-DB `dispo_mat_core` (Guard hat abgebrochen; dennoch Datenverlust
an den Preislisten-Tabellen). Platforms/Targetings/Media blieben erhalten.

Maßnahme:

1. Verifiziertes PRE-3-Backup `…-214406/` → `oa-tkp-relevant-tables.sql` restored
   (beide Jahre wieder Draft, 41+41 Items)
2. Activate 2026 + 2027 erneut (Dry-Run → Write)
3. Tests ausschließlich mit `DB_DATABASE=dispo_test` (49/49)

Finaler Endzustand = zweite Activate-Runde (published_at 21:28:31 / 21:28:32).

---

## Endzustand

| Jahr | Version | Status | Items | Active |
|---|---|---|---:|---:|
| 2026 | 2026.1 | active | 41 | 1 |
| 2027 | 2027.1 | active | 41 | 1 |

Zusätzlich:

- Parität 41/41
- Midroll 80/20, kein +15
- Targetings unverändert
- Adserver 0
- Registry `planned` / `null` / `v1=implemented`
- OA-003 OUT
- kein Plattformauswahl-Flip

Resolver:

```text
2026 → 2026.1
2027 → 2027.1
```

Kein Cross-Year-Fallback.

---

## Tests (nach Write)

Gegen `DB_DATABASE=dispo_test` (nicht `dispo_mat_core`):

| Suite | Ergebnis |
|---|---|
| `PoCalcTkp1d4c2ImportCliTest` | Teil der 49 |
| `PoCalcTkp1d4c2ActivateConcurrencyMysqlTest` | Teil der 49 |
| `PoCalcTkp1bEnginePersistenceTest` | Teil der 49 |
| `PoCalcTkp1d1WriterIntegrationTest` | Teil der 49 |
| `PriceListCalendarTest` | Teil der 49 |
| `PriceListYearSelectionTest` | Teil der 49 |

**49 passed / 377 assertions.**

Lokaler DB-Readback auf `dispo_mat_core` siehe Endzustand oben.

Kein D4d-E2E in diesem Slice.

---

## STOP

Kein Registry-Release. Kein D4d-REL. Kein Deploy.
