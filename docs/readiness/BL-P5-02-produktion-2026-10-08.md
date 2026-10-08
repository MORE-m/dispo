# Readiness: BL-P5-02 – Produktionsleistungen in Kalkulation und Dispo

Status: **READY** (Teilscope `BL-P5-02a` umgesetzt; Draft-PR inkl. Review-Nachzug)
Stand: 8. Oktober 2026
Audit-/Implementierungsbasis: Feature-Branch auf `origin/main` @ `7583a3edfbb422b7b9580b4713747201d876b8d1`
IDs: `ADV-003`, `PRO-001`–`PRO-007`, `AT-11` (teilweise), `COM-*`, `ADM-*`,
`PO-BLP502-1`, Auflösungsvertrag
Entscheidung: [`docs/entscheidungen/PO-BLP502-1-produktion-sonstiges.md`](../entscheidungen/PO-BLP502-1-produktion-sonstiges.md)
(**A1/B1/C1/D1/E1/F/G1/H1a akzeptiert**)
Auflösung: [`PO-BLP502-1-aufloesungsvertrag.md`](../entscheidungen/PO-BLP502-1-aufloesungsvertrag.md)

> Technischer Slice fertig ≠ operative Preise gepflegt. **Kein** Deploy.
> `BL-P5-02` / `AT-11` nur **teilweise**. Überschreibungs-Sonderfreigabe und
> Sonstiges-FreiPreis offen. Andere Träger/Methoden offen.

## 0. Urteil

| Voraussetzung | Status |
|---|---|
| PO-BLP502-1 A1…H1a | **akzeptiert** |
| UX-GATE-D Teilfreigabe Admin + Calc-Zusatzzeile + Dispo-S | **akzeptiert** (A1) |
| Admin-Produktionspreise inventar×Typ×Jahr | **umgesetzt** |
| Calc Spot Classic × Average Zusatzzeilen | **umgesetzt** |
| Preview/Save/Reload/Dispo | **Feature-Tests + Browser-Smoke** |
| Freeze / Pin / Inventarwechsel H1a | **umgesetzt** |
| Preisüberschreibung | **serverseitig ausgeschlossen** (F) |
| Operative Produktionspreise | **nicht** behauptet; synthetische Fixtures |
| Elternpaket `BL-P5-02` / `AT-11` vollständig | **nein** (teilweise) |
| Deploy | **kein** Deploy |

**Readiness-Urteil: READY** für Teilscope `BL-P5-02a` auf dem Feature-Branch.
Keine Deployment-Freigabe. Keine operative Datenlieferung.

## 1. Scope `BL-P5-02a`

In Scope: Spotproduktion, Träger Spot Classic Average, generisches Zusatzzeilenmodell
(ohne weitere Typen freizuschalten), Admin-Modul, Calc/Dispo, Snapshot/Rechte/Regression.

Außerhalb: Calendar/Tandem/Tridem/Trailer als Träger, weitere Typen, freie Preise,
Überschreibungen, CRM/OA/SWF-Rest, Standardangebote/Budget, Deploy.

## 2. Auflösungsvertrag (Kurz)

Jahr = Träger-`price_year` (PO-PRI-YEAR-1). Eine Active je Inventar×Typ×Jahr.
Pin bei Mengenänderung; Rebind bei Inventar-/Jahrwechsel. Fail-closed mit Zeile;
ohne Zeile blockiert fehlende Produktionskonfiguration Spot nicht.

## 3. Abnahmebelege

- Feature: `ProductionPriceListAdminLifecycleTest`, `SpotProductionBlP502aTest`
  (u. a. 150×2 + 80×3 = 540 Produktion; Pin; Inventarwechsel; Sales-Manipulation;
  Flags; Dispo-S; Regression)
- Browser isoliert Port **8057**: `playwright.blp502a.config.ts` / `E2ESpotProductionSeeder`
  – Admin aktivieren, Wizard SPA+SPB, Preview/Save/Reload/Dispo S-Zeile,
  Inventarwechsel SPC fail-closed, Calendar-Sperre + Entfernung,
  divergente Träger-/Produktionsflags inkl. Order-Rabatt+AE; lokal `--retries=0`
- Review-Nachzug + Restbefunde P1/P2: Sonderfreigabe Produktion (inkl. nur
  rabattfähige Zeilen für effektiv), Positionsrabatte unabhängig vom Trägerflag,
  eingefrorener Produktions-AE-Satz und Positionsrabatt-Staffel, From-Calc-Ablehnung
  (siehe [`docs/reviews/production-readiness/README.md`](../reviews/production-readiness/README.md))

## 4. Bewusst offen

Operative Preispflege, Sonstiges-FreiPreis, Überschreibungs-Sonderfreigabe,
weitere Träger/Methoden, Produktion in Standardangeboten, vollständiges `AT-11`, Deploy.
