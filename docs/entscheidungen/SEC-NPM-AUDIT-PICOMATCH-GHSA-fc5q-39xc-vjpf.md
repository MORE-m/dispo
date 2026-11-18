# SEC-NPM-AUDIT-PICOMATCH – GHSA-fc5q-39xc-vjpf

Status: **PO-/Security-Entscheidung – Draft zur Freigabe**  
Datum: **18.11.2026**  
Kennung: `SEC-NPM-AUDIT-PICOMATCH-1`  
Advisory: [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf) / CVE-2026-112366  
Bezug: CI-Blocker PR #254 / Run `44162317657`, `origin/main` @ `366c535`

---

## Kurzfazit

Es gibt derzeit **kein veröffentlichtes gepatchtes `picomatch`-Release**.  
npm schlägt als „Fix“ ein **Breaking-Downgrade** von `laravel-vite-plugin` auf `2.1.0` vor – das wird **nicht** übernommen.

Für den Production-Audit (`npm audit --omit=dev`) wird eine **befristete, eng begrenzte Ausnahme nur für GHSA-fc5q-39xc-vjpf** eingeführt. Alle anderen Advisories bleiben fail-closed.

**Dies ist eine bewusste PO-/Security-Entscheidung und kein stillschweigendes CI-Umgehen.**

---

## Betroffenheit

Installierte Versionen:

- `picomatch@4.0.7` (Root / Vite / tinyglobby / fdir / @rolldown/plugin-babel)
- `picomatch@2.3.2` (unter `vite-plugin-full-reload`)

Transitive Production-Pfade (`--omit=dev`):

1. `laravel-vite-plugin` → `tinyglobby` / `vite-plugin-full-reload` → `picomatch`
2. `@inertiajs/vite` → `tinyglobby` → `picomatch`
3. `@vitejs/plugin-react` → `@rolldown/plugin-babel` → `picomatch` (Metakette laut Audit)
4. `vite` (vite-plus-core Alias) → `tinyglobby` → `picomatch`

Advisory-Range: `<= 4.0.7`. Registry-Latest: `4.0.7`. **Patched versions: none** (Stand 18.11.2026).  
Offene Upstream-PRs existieren, sind aber nicht released.

---

## Erreichbarkeit

| Kontext | picomatch aktiv? | Nutzerkontrollierte Glob-Patterns? |
|---|---|---|
| Laravel/PHP Runtime / HTTP | Nein | – |
| Browser-Bundle der App | Nein (Build-Tooling) | – |
| `npm run build` / Vite CI-Build | Ja | Nein – Globs aus Projekt-/Plugin-Config |
| `npm run dev` lokal | Ja (Watcher) | Nein – Entwickler-kontrollierte Config |

Die Schwachstelle ist ein **ReDoS bei adversen Extglob-Patterns**. Im Dispo-Kontext werden solche Patterns nicht aus Anfragen, Uploads oder CRM-Daten an `picomatch` durchgereicht.

### Risikoeinstufung

- **Theoretisch:** high (CVSS 7.5, CWE-1333) laut Advisory.
- **Praktisch im Produktbetrieb:** **niedrig** – kein Runtime-Angriffspfad über die ausgelieferte Anwendung.
- **Build-/Dev-Hosts:** gering, solange Vite-Konfiguration und Scripts repo-kontrolliert bleiben.
- **Bestehende Sicherheitslücke im Sinne „offen, ungepatcht im Tree“:** ja, aber **nicht produktiv erreichbar** über App-Traffic.

---

## Geprüfte Optionen

### A – Gepatchtes Release (bevorzugt)

**Nicht verfügbar.** Kein `picomatch > 4.0.7` in der npm-Registry; Advisory listet keine Patch-Version.

### B – Dependency-Pfad entfernen / korrekt klassifizieren

- Entfernen von `laravel-vite-plugin` / `@inertiajs/vite` / `vite` aus Production bricht den Frontend-Build.
- Umsortieren in `devDependencies` nur für den Audit wäre eine **künstliche Umdeklaration** und widerspricht dem Deploy-/CI-Modell (`npm ci` mit Production-Tree + Build).
- **Abgelehnt.**

### C – Befristete Ausnahme (gewählt)

Eng begrenzte Auswertung von `npm audit --omit=dev --json`:

- Nur `GHSA-fc5q-39xc-vjpf` (inkl. reiner Metaketten darüber) darf bestehen.
- Jede andere Advisory → CI-Fehler.
- Keine globale Severity-Absenkung, kein `--audit-level`, kein Abschalten des Steps.
- Ablaufdatum: **2026-12-18** (UTC). Danach schlägt der Check fehl, bis neu bewertet.

Implementierung: `scripts/npm-audit-production.mjs`  
CI: `.github/workflows/tests.yml` Step `npm audit (production)`  
Tests: `tests/security/npm-audit-production.test.mjs`

---

## Follow-up (verbindlich)

1. Wöchentlich bzw. spätestens vor Ablauf prüfen, ob `picomatch` eine Patch-Version veröffentlicht hat.
2. Sobald Patch verfügbar: Ausnahme entfernen, Lockfile auf gepatchte Version bringen, Audit wieder ohne Exception grün.
3. Breaking-Downgrade von `laravel-vite-plugin` bleibt ohne separate technische Freigabe verboten.
4. PR #254 (PO-CALC-DISPO-UX-1 PR2) bleibt Draft, bis dieser Security-Chore auf `main` ist und die Gates wieder grün sind.

---

## Nicht-Scope

- Keine Änderungen an PR #254 / Dispo-Layout.
- Kein `npm audit fix --force`.
- Keine Mutation von `dispo_mat_core`.
- Kein Merge ohne PO-/Security-Freigabe dieser Entscheidung.
