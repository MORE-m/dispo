# BL-P4-03f – Tandem/Tridem in Spot-Classic-Average-Standardangeboten

Status: **Vorgeschlagen** (Feature-PR)  
Stand: 28. September 2026  
IDs: `STD-001`–`STD-009`, `AUTH-006`/`AUTH-007`, `VER-004`, `SPT-012`, `COM-009`, **PO-BLP403F-1**  
Basis: `main` nach PR #98 (`f6daf52…`); baut auf BL-P4-02e (Tandem/Tridem) und 03a–03e

## Entscheidung / UX-GATE-D Teilfreigabe PO-BLP403F-1

Schmale Teilfreigabe **nur** für Tandem/Tridem in Spot-Classic-**Average**-Standardangeboten
(inkl. N/N-Festpreis). Ablauf unverändert zu 03b:

1. Vertrieb speichert eine zugängliche Kalkulation als kundenlosen `SA-`-Vorschlags-Draft.
2. PM prüft (Freitext-Prüfstufe) und veröffentlicht – ohne Zugriff auf die Quellkalkulation.
3. Vertrieb übernimmt die veröffentlichte Vorlage mit Kunde als unabhängige Calc.

**In Scope:** Tandem und Tridem × `normal` und `fixed_price`; Mischung mit bisherigen
Average-/Hauptspot+Allonge-Positionen in derselben Vorlage.

**Außerhalb:** Calendar-Vorlagen, Budget-Vorlagen, Abbinder. Eine Quellkalkulation mit
Average- und Calendar-Positionen wird als Ganzes abgewiesen (keine stille Teilübernahme).

## Materialisierungsversion 3

| Version | Bedeutung |
|---------|-----------|
| **1** (Legacy, fehlendes Feld) | Average + optionale Komponenten; Settlement nur `normal`; kein Profil |
| **2** | wie v1, plus `normal`\|`fixed_price` |
| **3** (aktueller Freeze) | wie v2, plus optional `component_profile` `tandem`\|`tridem` |

Neue Freezes schreiben immer **Version 3**. Legacy **v1/v2** bleiben lesbar/übernehmbar.
Unbekannte Versionen und unvollständige/widersprüchliche v3-Daten scheitern fail-closed
ohne Teilanlage.

**Freeze** (`StandardOfferMaterializer`) und **Hydrate**
(`FrozenCalculationPersistenceContract`) bleiben zwei gepflegte Seiten (ADR 03d).

## Vertrag / Verhalten

- 02e-Semantik: Profil am Medium, Reminder-Rollen, verbindlich `shared_total_length`,
  Mengen = Tandem-/Tridem-Einheiten; ×2/×3 nur Anzeige. Keine Vorlagenformel.
- `individual`, falsche Rollen, widersprüchliche Profile → kontrolliert 422.
- Kein stilles Entfernen von Profil, Komponenten oder Festpreisfeldern.
- Nach Publish keine Live-Neuberechnung; nach Adopt keine Sync zu Vorlage/Quelle.
- Rechte unverändert zu 03b (Vertrieb Vorschlag; PM Prüfung/Publish; Adopt ohne PM).

## Nicht-Ziele

- Calendar-/Budget-Vorlagen, Abbinder
- Änderung der 02e-Kalkulationsformel
- Auto-Support weiterer Methoden ohne neue Version/Contract-Pflege
