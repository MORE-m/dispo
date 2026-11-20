# SEC-NPM-AUDIT-PICOMATCH – GHSA-fc5q-39xc-vjpf

Status: **PO-/Security-Freigabe erteilt**  
Datum Entscheidung: **18.11.2026**  
Freigabedatum: **18.11.2026**  
Ablaufdatum: **18.12.2026 einschließlich (UTC)**  
Kennung: `SEC-NPM-AUDIT-PICOMATCH-1`  
Advisory: [GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf) / CVE-2026-112366  
Bezug: CI-Blocker PR #254 / Run `44162317657`, `origin/main` @ `366c535`  
Security-Fix + Tests: PR #255 / Feature-HEAD vor Freigabe-Doku `d81bb31c3bfeef06687abc6c8f99c7cc1b95d03b` / CI `44307557326`

---

## PO-/Security-Freigabe (verbindlich)

Am **18.11.2026** hat der Product Owner die befristete Security-Ausnahme **ausdrücklich freigegeben**.

Freigabeumfang:

- Advisory `GHSA-fc5q-39xc-vjpf` / CVE-2026-112366 / Paket `picomatch`
- Ablauf **18.12.2026 einschließlich (UTC)**
- ausschließlich diese Advisory sowie nachweislich daraus abgeleitete Metavulnerabilities
- nur unter den Bedingungen von PR #255 (fail-closed Audit-Helper, Unit-Tests, unveränderte Ausnahmebreite)

Begründung der Freigabe:

1. Zum Entscheidungszeitpunkt kein veröffentlichtes gepatchtes `picomatch`-Release.
2. Kein nachgewiesener Angriffspfad über den Laravel-/HTTP-Produktbetrieb.
3. Betroffenheit liegt im Build-/Entwicklungskontext (Vite/Tooling), nicht im ausgelieferten App-Runtime-Pfad.

Die Freigabe autorisiert die **befristete Ausnahme**, nicht die Behauptung, dass die Schwachstelle behoben sei.  
**Keine stillschweigende Verlängerung** – jede Verlängerung oder Erweiterung erfordert eine neue PO-/Security-Freigabe.

Überprüfter Security-Fix (Findings 1/2): unbekannte/zyklische Metaketten und ungültige Audit-Berichte fail-closed; Tests in `tests/security/npm-audit-production.test.mjs`.

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

## Verbleibendes Restrisiko und Neubewertung

- `picomatch` bleibt **ungepatcht** im Dependency-Tree (`<= 4.0.7`).
- Restrisiko: ReDoS bei adversen Extglob-Patterns auf Build-/Dev-Hosts.
- Produktbetrieb (Laravel/HTTP, ausgeliefertes Frontend-Bundle): kein bekannter Angriffspfad.
- **Neubewertung spätestens am/vor 18.12.2026** bzw. sofort bei gepatchtem Upstream-Release.
- Nach Ablauf ohne neue Freigabe muss der CI-Check fehlschlagen.

---

## Follow-up (verbindlich)

1. Wöchentlich bzw. spätestens vor Ablauf prüfen, ob `picomatch` eine Patch-Version veröffentlicht hat.
2. Sobald Patch verfügbar: Ausnahme entfernen, Lockfile auf gepatchte Version bringen, Audit wieder ohne Exception grün.
3. Breaking-Downgrade von `laravel-vite-plugin` bleibt ohne separate technische Freigabe verboten.
4. **Nachzug 20.11.2026:** Security-Chore PR #255 auf `main` (`ec26e42`, CI `44368007218`); PR #254 (PO-CALC-DISPO-UX-1 PR2) danach gemergt (`bc59dcd`). Historischer Draft-Blocker erledigt.
5. Keine Verlängerung der Ausnahme ohne erneute PO-/Security-Freigabe. Wiedervorlage vor **2026-12-18**.

---

## Nicht-Scope

- Keine Änderungen an PR #254 / Dispo-Layout im Rahmen dieser Entscheidung.
- Kein `npm audit fix --force`.
- Keine Mutation von `dispo_mat_core`.
- Keine pauschale Severity-Absenkung und kein Abschalten von `npm audit --omit=dev`.
