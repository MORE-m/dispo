# Readiness: BL-P4-03k – Calendar × Tandem/Tridem in Standardangeboten

Status: **READY** (PO-BLP403K-1 akzeptiert A1; Feature-Branch / Draft-PR)
Stand: 7. Oktober 2026
Audit-/Implementierungsbasis: `origin/main` @ `e44c1cf436c6e1a63781910c7c1cd46ae019903a` (PR #127)
IDs: `STD-001`–`STD-009`, `VER-004`, `SPT-005`–`SPT-008`, `SPT-012`, `COM-009`,
`PRI-002`/`PO-PRI-YEAR-1`, `AUTH-006`/`AUTH-007`, **PO-BLP403K-1**, Slice **BL-P4-03k**
Entscheidung: [`docs/entscheidungen/PO-BLP403K-1-calendar-tandem-tridem.md`](../entscheidungen/PO-BLP403K-1-calendar-tandem-tridem.md)

## 0. Gate / Ergebnis

| Voraussetzung | Status |
|---|---|
| UX-GATE-D Teilfreigabe Calendar×Tandem/Tridem | **akzeptiert** (A1; B1+C1 übernommen) |
| Calendar 03g–03j / Average×Tandem 03f | auf Main |
| Calc Calendar × Tandem/Tridem × Settlement | Feature-Tests ergänzt |
| Materialisierung v4 Vertragserweiterung | umgesetzt (kein v5, keine Schema-Migration) |
| Budget / Abbinder | bewusst außerhalb |
| Merge / Deploy | **kein** Merge, **kein** Deploy |

**Readiness-Urteil: READY** – Slice auf Feature-Branch / Draft-PR. Reader vor dieser
Erweiterung (PR #127) weist Calendar×`component_profile` ab; neue Snapshots brauchen den
erweiterten Reader. **Keine** Deployment-Freigabe.

## 1. Akzeptierter Adoption-Vertrag

- **A1:** Calendar × Spot Classic × Tandem und Tridem × `normal` und Festpreis; verbindlich `shared_total_length`.
- **B1:** Konkrete Termine unverändert; kein Auto-Shift.
- **C1:** Adopt = Frozen-Parity inkl. Profil/Slots/`fixed_price_nn`/Pins/Zellen; kein Live-Rebind.
- **v4-Erweiterung:** kein v5; 03g–03j- und Average-Varianten bleiben lesbar.
- Shared-Total: Komponenten-`media_gross` = `''`; keine Preis-×2/×3.

Calc-Edit folgt 02a/`PO-PRI-YEAR-1`: Pin ohne Inventar-/Jahr-/Mediumwechsel;
Zellpreise aus gepinnter Liste; Listen-Rebind nur bei Jahr-/Inventar-/Mediumwechsel.

## 2. Umgesetzte Schichten

| Schicht | Inhalt |
|---|---|
| Draft-Contract | Calendar × Profil tandem\|tridem + Settlement; `individual` fail-closed |
| From-Calc | reine Calendar-Profil-Quellen; Mix abgewiesen |
| Freeze | v4; Profil + Settlement + Planner aus Writer-Totals |
| Hydrate | Calendar mit Profil/Slot-Asserts; Reader #127-Stand abweisend dokumentiert |
| UI | Forced-Profile auch bei Calendar-Vorlage; Scope-Note 03k |

## 3. Abnahme (Kurz)

Synthetisch: `StandardOfferBlP403kTest`, `SpotClassicCalendarTandemTridemTest`
(+ Regression 03e–03j / 03a Negativanpassungen). From-Calc-Viererkombination,
fehlende Preiszelle inkl. Publish, Profil-Negativ Create/Preview/Publish,
Planungsregel- und Inventar-Deaktivierung, Frozen-Planner-Parität,
Normal-Mengen-Pin und Rebind Jahr/Inventar/Medium sowie Medium-Switch
Tandem→Tridem sind in `StandardOfferBlP403kTest` belegt.

Browser-Smoke isoliert: `playwright.blp403k.config.ts` / Port **8054** —
Tandem×normal und Tridem×Festpreis jeweils Save → Reload → Publish → Adopt → Calc-Reload.
Publish synchronisiert über Response `/veroeffentlichen` und Published-Show-Zustand
(nicht nur URL). Lokal mit `--retries=0 --repeat-each=3` geprüft.
Zusätzlich UI-Inventarwechsel Tandem→Tridem: automatischer Slot-Rebuild ohne
API-Payload, Calendar-Timing erhalten, Preview/Save/Reload.

Fixture: Tandem 600,00 / N/N 888,50; Tridem 760,00 / N/N 1200,00 (`''` Komponentenbruttos).

Getrennt: Adopt-Freeze nach Live-Preisänderung (+ DB-Reload); Nachfolgerlisten-Pin
(Festpreis und Normal-Mengenänderung).

## 4. Bewusst offen

Budget-Vorlagen, Abbinder/SPT-013, Merge/Deploy.
