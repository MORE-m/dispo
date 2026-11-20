# Readiness – SEC-NPM-AUDIT-PICOMATCH (18.11.2026)

## Ausgangslage

- `origin/main` @ `366c535`
- PR #254 CI `44162317657`: `mysql`/`e2e-spt008` grün; `ci` fail nur bei `npm audit --omit=dev`
- Advisory: GHSA-fc5q-39xc-vjpf / CVE-2026-112366 – `picomatch <= 4.0.7`, **keine Patch-Version**

## Entscheidungspfad

| Option | Ergebnis |
|---|---|
| A Patch-Release | nicht verfügbar (`4.0.7` = latest) |
| B Dependency entfernen / umdeklarieren | abgelehnt (Build bricht / künstliche devDependency) |
| C Befristete Ausnahme nur GHSA | **gewählt**, Ablauf 2026-12-18 |

## Umsetzung (dieser Chore)

- `scripts/npm-audit-production.mjs`
- `tests/security/npm-audit-production.test.mjs`
- Workflow-Step ersetzt `npm audit --omit=dev`
- Entscheidung: `docs/entscheidungen/SEC-NPM-AUDIT-PICOMATCH-GHSA-fc5q-39xc-vjpf.md`

## GO/STOP

- **GO** für Draft-Chore-PR und CI-Prüfung
- **STOP** für Merge ohne explizite PO-/Security-Freigabe der Ausnahme
- PR #254 unverändert Draft (Stand Readiness 18.11.2026)

## Nachzug (20.11.2026) – historischer Snapshot

Readiness-Snapshot vom 18.11. bleibt erhalten. **Aktueller Status:** PO-/Security-
Freigabe erteilt; PR #255 gemergt (`ec26e42`, CI `44368007218`); PR #254 danach
gemergt (`bc59dcd`). Ausnahme aktiv bis **2026-12-18**. Details:
`docs/entscheidungen/SEC-NPM-AUDIT-PICOMATCH-GHSA-fc5q-39xc-vjpf.md`.
