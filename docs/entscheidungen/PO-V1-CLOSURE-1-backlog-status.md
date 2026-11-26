# PO-V1-CLOSURE-1 – V1-Backlogstatus konsolidieren

Status: **Historisch akzeptiert / Docs-only** (15. November 2026). Ab 26.11.2026 gelten für den weiteren Go-live-Verlauf die verbindlichen Entscheidungen in [`../roadmap/GO-LIVE-MASTERPLAN.md`](../roadmap/GO-LIVE-MASTERPLAN.md) (PO-MASTER-AUDIT). Widersprechende Scope-Abgrenzungen hierin – insbesondere Social/Events als Stufe 3 / kein Tag-1 sowie Contest nicht als Go-live-Pflicht – sind für die weitere Arbeit abgelöst. Der Closure-Stand vom 15.11.2026 bleibt als historische Statuskonsolidierung erhalten.  
Stand: 15. November 2026  
Basis: `origin/main` @ `f193c4b092c8ee1a56a7792b34894182d0a3dc99`  
IDs: `PO-V1-CLOSURE-1`  
Grundlage: Read-only `V1-Closure-Audit` (gleiche Base)  
Bezug: `PO-GOLIVE-CUT-1-DEC`, `PO-STD-V2`, `PO-OA-BUDGET-V2`, `PO-AT13-CC-1`

---

## 1. Zweck

Statuskonsolidierung des V1-Backlogs gegen den tatsächlichen Code-/Test-/PO-Stand.

Dieses Dokument:

- **erfindet keine neue Fachscope-Entscheidung**
- konsolidiert nur bereits bestehende PO-Entscheidungen und Audit-Belege
- trennt Tag-1-Blocker von historischen Status-Schulden

Keine Runtime-Änderung. Keine Migration. Kein DB-Write. Kein Deploy.

---

## 2. Tag-1 harte Blocker

```text
BL-P11-01 Betriebs-/Go-live-Readiness (ADR-003, Kap. 27)
Operative Katalog-/Preisdaten-Validierung (CUT Stufe 2)
Deploy / Produktivsetzung
```

Kein normaler Feature-Slice.

---

## 3. V1 erledigt / V1-Kern abgeschlossen (Statusbereinigung)

| Thema | Einordnung |
|---|---|
| BL-P6-01 Online Audio V1-Scope | V1 abgeschlossen (Normal-TKP, OA-003, Native/Free Position); Future/V2 separat |
| BL-P7-01 COM-001–009 | umgesetzt |
| BL-P7-03 Spot-Budget | V1 erledigt |
| BL-P5-01 Stufe-1-Kern | Trailer, Allonge, Promo umgesetzt |
| BL-P4-02 Spot-Kern | Average/Calendar/Festpreis/Tandem/Tridem/Hauptspot+Allonge/Erst/Letzt/Single/SPT-008 |
| BL-P3-02 Snapshot-Kern VER | DF-1…DF-3-RULE-C; ADV-002 außerhalb |
| BL-P8-01c / DSP-DCP-001 | Runtime umgesetzt; manuelle Abnahme separat |

---

## 4. V1-Rest / nicht Tag-1-kritisch

```text
COM-010 aggregierte Payfaktoren (ohne OA / OA / Gesamt)
COM-011 Fachvertrag „nicht berechenbar“ / Übergabe-Prüfung (Guard vorhanden)
AT-13-Rest (weitere Invalidierungsauslöser) – PO-Klärung erforderlich
AUTH-005 Dual-Protokoll (CUT: kein Ausbau in Stufe 2)
SPT-013 Abbinder
operative Blockplanung
BL-P5-02 Produktion-Rest (Sonstiges / Override)
CRM-004 Kontakte (+ API/E-Mail-Ingest/XLSX)
ADV-001 Defaults / Hinweistexte / MAT-003-Vollabnahme (Abnahme-Track)
```

---

## 5. Stufe 3

```text
BL-P6-02 Social Media und Events (fachlich offen; kein Tag-1-Blocker)
weitere SWF jenseits Stufe-1-Kern (separate Readiness)
```

Contest ist im Code vorhanden (#165), wird hier **nicht** nachträglich als Stufe-1-Pflicht umklassifiziert.

---

## 6. Stufe 4

```text
BL-P10 Reports / PDF / Excel / CSV (inkl. REP-007)
Notification-Rest: Submit-Mail, Invalidierungs-Mail, weitere Status-Mails, In-App
```

Tag-1-Interim bleibt: Dispo-Detailseite + SPT-008 Spotverteilung-XLSX.

---

## 7. V2

```text
Standardangebote vollständiger Produktumfang (PO-STD-V2)
OA-TKP Budget
Free Position / Native-Ad Budget
Mixed Spot/OA Budget (PO-OA-BUDGET-V2)
```

---

## 8. Future

```text
OA-005 / OA-006 Plattformwahl
interne OA-Kosten-/Margenabbildung Midroll +15 EUR
```

---

## 9. Abnahme / Betrieb

```text
DSP-DCP-001 manueller Abnahmebeleg
MAT-003-Vollabnahme / operative Preisdaten
Kapitel-27-Liefergegenstände
BL-P11-01 unverifizierte Betriebsparameter
Deploy
```

---

## 10. AT-13 Hinweis

Umgesetzt: CC-Archiv @ `at_disposition` → draft → voller Freigabezyklus (PO-AT13-CC-1).

Weiterer Katalog-Text zu Preis/Rabatt/AE vs. enge PO-Freigabe = **Scope-Konflikt**.

```text
AT-13-Rest = PO-Klärung erforderlich
kein automatisch freigegebener Implementierungsauftrag
```

Dieses Closure-Dokument legt **keine** neue Invalidierungsregel für Preis-/Rabatt-/AE-Änderungen fest.

---

## 11. Arbeitsreihenfolge nach Closure

```text
P1  BL-P11-01 Betriebs-/Go-live-Readiness
P2  operative Katalog-/Preisdaten-Abnahme
P3  COM-010 / COM-011 Readiness  und separat  AT-13 PO-Klärung
P4  Stufe-3-Produktentscheidungen (Social/Events/weitere SWF/…)
```

---

## 12. Docs dieses Slices

- `docs/entscheidungen/PO-V1-CLOSURE-1-backlog-status.md` (diese Datei)
- `docs/backlog-v1.md`
- `docs/fortschritt.md`
- `docs/blocker-und-entscheidungslog.md`
- `docs/test-und-abnahmekatalog.md`
- `docs/entscheidungen/README.md`
