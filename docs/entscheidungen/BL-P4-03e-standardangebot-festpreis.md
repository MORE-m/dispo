# BL-P4-03e – Festpreis in Spot-Classic-Average-Standardangeboten

Status: **Akzeptiert** (`main`, PR #97)  
Stand: 28. September 2026  
IDs: `STD-001`–`STD-009`, `AUTH-006`/`AUTH-007`, `VER-004`, `SPT-014`, `COM-009`  
Basis: `main` nach PR #95 (`6af849a…`); baut auf BL-P4-02d (N/N-Festpreis) und 03a–03d  
Merge: `4eddc94be010f0887e6dfaf1459fe7d5f6a8f1bf`; Post-Merge-CI
[`36435981330`](https://github.com/MORE-m/dispo/actions/runs/36435981330) grün
(`ci`/`mysql`/`e2e-spt008`)

## Entscheidung

Spot-Classic-**Average**-Standardangebote unterstützen denselben N/N-Festpreis-
Abschluss wie Kundenkalkulationen (`pricing_settlement_mode` /
`fixed_price_nn`, Semantik BL-P4-02d). Es gibt **keine** vereinfachte
Vorlagenformel: Preview, Publish-Freeze und Adopt nutzen die bestehende
`CalculationWriter`-/Engine-Logik (AE-Rückrechnung, Rabatte, Payfaktor,
Sonderfreigabe).

## Materialisierungsversion 2

| Version | Bedeutung |
|---------|-----------|
| **1** (Legacy, fehlendes Feld) | Average + optionale Komponenten; Settlement nur `normal`; kein `fixed_price_nn` |
| **2** (aktueller Freeze) | wie v1, plus `normal`\|`fixed_price` mit konsistentem `fixed_price_nn` |

Neue Freezes schreiben immer **Version 2**. Unbekannte Versionen und
widersprüchliche Settlement-Werte scheitern fail-closed ohne Teilanlage.
Legacy **v1** (inkl. fehlendem Versionsfeld) bleibt lesbar/übernehmbar.

**Freeze** (`StandardOfferMaterializer`) und **Hydrate**
(`FrozenCalculationPersistenceContract`) bleiben zwei gepflegte Seiten
(ADR 03d); Festpreis ist ausdrücklich in beiden erweitert. Weitere Methoden
werden nicht automatisch übernommen.

## Vertrag / Verhalten

- Draft-Contract und HTTP-Validierung erlauben `fixed_price` mit N/N > 0.
- Kein stilles Zurücksetzen auf `normal` bei Vorschlag, Speichern, Publish oder Adopt.
- Nach Publish keine Live-Neuberechnung des Festpreises aus der Preisliste
  (Frozen-Parity, Snapshot-Isolation).
- Calc→Vorlage: Festpreis bleibt erhalten; Kunde/Agentur/kundenbezogene Freitexte
  weiterhin nicht im Draft.
- Rechte unverändert zu 03b (Vertrieb Vorschlag; PM Prüfung/Publish; Adopt Vertrieb/Admin/GF).

## UI

Im Vorlagenmodus ist Festpreis wählbar; Calendar, Tandem/Tridem, Budget und
Abbinder bleiben ausgeblendet und serverseitig abgewiesen.

## Nicht-Ziele

- Calendar-Vorlagen, Tandem/Tridem, Budgetplanung auf Vorlagen, Abbinder
- Änderung der 02d-Kalkulationsformel
- Auto-Support weiterer Methoden ohne neue Version/Contract-Pflege
