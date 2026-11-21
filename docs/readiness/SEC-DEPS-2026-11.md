# SEC-DEPS-2026-11 – Security-Ausnahme und Dependency-PR-Triage

Datum: **20.11.2026**  
Main-Stand: `32852af12b162d17ee7416dc3ec7042cf1a0ad4f`  
Repo: `MORE-m/dispo`  
Auftrag: SEC-DEPS-2026-11

---

## Schutzbestätigungen

- Kein automatischer Merge.
- Keine Mutation von `dispo_mat_core`.
- Keine produktiven Migrationen / kein Deployment.
- Kein Eingriff in parallele Docs-/Roadmap-Worktrees.
- Docs-Overlap mit PR #259: Decision-/Statusdateien aus #259 **nicht** parallel mutiert
  (`docs/entscheidungen/SEC-NPM-AUDIT-PICOMATCH-…`, `docs/fortschritt.md`,
  `docs/blocker-und-entscheidungslog.md`, …). Nachtrag dort nach Merge von #259.

---

## A – Security: GHSA-fc5q-39xc-vjpf / picomatch

### Aktuelle Advisory-Lage (erneut geprüft 20.11.2026)

| Feld | Stand |
|---|---|
| Advisory | [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf) / CVE-2026-112366 |
| Status | **Withdrawn** (`withdrawnAt`: 2026-11-18T15:38:56Z) |
| Begründung Upstream | Nicht als Security-Issue bewertet (GitHub + VulnCheck); siehe micromatch/picomatch#235 |
| Patched Version | **keine** (nie veröffentlicht; `picomatch@4.0.7` bleibt `latest`) |
| npm audit `--omit=dev` | **0 vulnerabilities** |
| npm audit (full, inkl. Dev) | weiterhin Dev-Findings unter vitest/vite-plus (nicht Scope dieser Ausnahme) |

### Dependency-Pfad (unverändert im Lockfile)

Installiert:

- `picomatch@4.0.7` – u. a. über `@inertiajs/vite` → `tinyglobby`/`fdir`, `laravel-vite-plugin` (teilweise), `@rolldown/plugin-babel`, `vite`/`vitest`
- `picomatch@2.3.2` – über `laravel-vite-plugin` → `vite-plugin-full-reload`

`laravel-vite-plugin@3.2.0` bleibt Teil des Production-Audit-Trees (`--omit=dev`).  
Kein Breaking-Downgrade, kein Override, kein `npm audit fix --force`.

### Entscheidung

**Ausnahme entfernen.** Die Advisory ist zurückgezogen; Production-Audit ist ohne Allow-List grün.

Umsetzung (dieser Branch / Draft-PR):

1. `scripts/npm-audit-production.mjs` – fail-closed, **keine** GHSA-Allow-List mehr.
2. `tests/security/npm-audit-production.test.mjs` – an fail-closed-only angepasst.
3. CI-Kommentar in `.github/workflows/tests.yml` aktualisiert.
4. Hinweis in `docs/entwicklung-lokal.md` (nicht in #259-Overlap).

**Bewusst nicht hier:** Update der Decision-Datei
`docs/entscheidungen/SEC-NPM-AUDIT-PICOMATCH-GHSA-fc5q-39xc-vjpf.md`
(Overlap mit Docs-PR #259 → Nachtrag nach #259).

### Verbleibendes Risiko / Wiedervorlage

- Kein aktives GHSA mehr für diesen ReDoS-Fall.
- Datum **18.12.2026** als Ausnahme-Ablauf entfällt mit Entfernung der Ausnahme.
- Nächster Trigger: neues `picomatch`-Advisory oder Production-Audit ≠ 0.
- Offizielle Decision-Dok nach #259-Merge auf „geschlossen / withdrawn“ nachziehen.

### Lokale Security-Nachweise (Worktree)

- `node --test tests/security/npm-audit-production.test.mjs` → pass
- `node scripts/npm-audit-production.mjs` → `ok: true`, remaining `[]`
- `npm audit --omit=dev` → found 0 vulnerabilities

---

## B – Offene Dependency-PRs (Stand 20.11.2026)

PR #259 (Docs) ist **nicht** Teil dieses Auftrags.

| PR | Paket | Änderung | Risiko | CI (letzter Lauf) | Mergeability | Behind main | Maßnahme | Reihenfolge |
|---|---|---|---|---|---|---|---|---|
| Security-Draft | npm-audit Helper | Ausnahme entfernen | niedrig (Gate härter) | neu | n/a | 0 | Draft-PR, CI grün abwarten | **1** |
| #241 | `laravel/fortify` | 1.41.0 → 1.42.0 | niedrig–mittel (Auth; enthält Password-Confirm-Validierung) | ci/mysql/e2e grün (alter Base) | MERGEABLE / CLEAN / rebaseable | 59 | Dependabot-Rebase → CI neu → Review | **2** |
| #240 | `inertiajs/inertia-laravel` | 3.5.1 → 3.7.0 | erhöht (Live props) | grün (alter Base) | MERGEABLE / CLEAN / rebaseable | 59 | Rebase → CI + Inertia-Regression | **3** |
| #183 | `@laravel/multiplex` | 0.4.3 → 0.4.5 | niedrig | grün (alter Base) | MERGEABLE / CLEAN / rebaseable | 195 | Rebase → CI | **4** |
| #181 | `@testing-library/dom` | 10.4.1 → 10.4.2 | niedrig | grün (alter Base) | MERGEABLE / CLEAN / rebaseable | 195 | Rebase → CI | **4** |
| #182 | `@radix-ui/react-checkbox` | 1.3.11 → 1.4.1 | mittel (UI) | grün (alter Base) | MERGEABLE / CLEAN / rebaseable | 195 | Rebase → CI + Checkbox-Smoke | **5** |
| #180 | `@radix-ui/react-navigation-menu` | 1.2.22 → 1.4.1 | mittel (Nav) | grün (alter Base) | MERGEABLE / CLEAN / rebaseable | 195 | Rebase → CI + Nav-Smoke | **5** |
| #184 | `@playwright/test` | 1.62.1 → 1.64.1 | mittel (Testinfra) | grün (alter Base) | MERGEABLE / CLEAN / rebaseable | 195 | Rebase → CI + Browser-Install prüfen | **6** |
| #242 | `larastan/larastan` | 3.13.0 → 3.14.0 | blockiert / CI rot | **ci FAIL** (TS), mysql/e2e grün | MERGEABLE / UNSTABLE / rebaseable | 62 | Rebase; TS-Fehler war Base-Drift (`RedirectController.ts`), nicht Larastan | **7** |

### Feature-/Base-HEMDs

| PR | Base (bei CI) | Feature-HEAD |
|---|---|---|
| #241 | `89cda0314c20` | `1c025f9b8004` |
| #240 | `89cda0314c20` | `4c284396ed64` |
| #242 | `fe80724f2b6e` | `f961fe2ff0bd` |
| #183/#182/#180/#184/#181 | `6e4f9bbfbc0e` | je 1 Commit |

### CI-Fehler #242 (Befund)

- Fehlgeschlagen: Job `ci` Step **TypeScript and Vitest**
- Fehler: `resources/js/actions/Illuminate/Routing/RedirectController.ts` (`methods` vs `method`, `"query"` constraint)
- **Nicht** durch Larastan verursacht (composer-dev-Tool); TypeScript-Base-Drift zum Zeitpunkt des PR-Bases.
- Auf aktuellem `main` ist der gleiche File-Inhalt vorhanden; CI auf aktuellem main ist unabhängig zu bewerten / nach Rebase neu laufen lassen.

### Gruppierung

**Gruppe A – Security-kritisch**

- Security-Draft (Ausnahme-Ablösung nach Withdrawal) → **GO Draft**, Merge nur mit PO.

**Gruppe B – Niedriges Risiko**

- #241 Fortify (nach Rebase/CI; Auth-Smoke empfohlen)
- #183 Multiplex, #181 testing-library/dom

**Gruppe C – Erhöhtes Risiko**

- #240 Inertia (Live props) – Integrations-/Form-/Header-Regression
- #182 / #180 Radix UI – Checkbox/Navigation
- #184 Playwright – Browserversionen / Flakiness

**Gruppe D – Blockiert / nachziehen**

- #242 Larastan – erst nach Rebase + grünem CI; vorheriger Fail war TS-Drift

### Merge-Kandidaten (nach Rebase + frischem CI, **kein Auto-Merge**)

1. Security-Draft (dieser Auftrag)
2. #241 Fortify
3. #183 / #181 (kleine npm)
4. #182 / #180 (UI, nach Smoke)
5. #240 Inertia (nach gezielter Regression)
6. #184 Playwright
7. #242 Larastan

Hinweis: Upstream Fortify steht bereits bei **1.43.0**; PR #241 bleibt bewusst bei 1.42.0 (kein Scope-Ausweiten).

---

## C – Nächste Schritte

| Thema | Aktion |
|---|---|
| Security-Draft | CI `ci` / `mysql` / `e2e-spt008` auf Feature-HEAD; PO-Review; **kein Merge ohne Freigabe** |
| Decision-Dok | Nach #259: Status auf withdrawn / Ausnahme entfernt nachziehen |
| Dependabot-PRs | `@dependabot rebase` bzw. Update-Branch; CI neu; einzeln reviewen |
| Vor erstem Staging | Security-Gate ohne Ausnahme empfohlen; Fortify (#241) sinnvoll; Inertia (#240) nur nach Regression |
| Nach Staging | Radix/Playwright/Larastan nachziehen |

---

## D – Was bewusst nicht gemacht wurde

- Kein Merge.
- Kein `npm audit fix --force`, kein Override, kein Lockfile-Hand-Edit für picomatch.
- Keine pauschalen Dependency-Upgrades.
- Keine parallele Mutation der #259-Dateien.
- Keine Ersatz-PRs für bestehende Dependabot-PRs (Branches bleiben).
