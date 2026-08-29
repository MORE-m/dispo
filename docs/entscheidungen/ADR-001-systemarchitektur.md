# ADR-001 – Modularer Monolith

- **Status:** Akzeptiert
- **Datum:** 29. August 2026
- **Entscheider:** bestätigt

## Änderungsprotokoll

| Datum | Art | Inhalt |
|---|---|---|
| 29. August 2026 | Erstfassung akzeptiert | Modularer Monolith, eine Webanwendung, relationale DB, Fachmodule |
| 29. August 2026 | Konkretisierung | Dateien über privaten Laravel-Filesystem-Speicher; kein S3 als V1-Voraussetzung; Adapter austauschbar |

Diese Konkretisierung füllt den zuvor offenen Dateispeicher; sie ersetzt ADR-001 nicht.

## Kontext

V1 wird von ungefähr 20 internen Nutzern verwendet und verarbeitet ungefähr 3.000
Kalkulationen/Dispoaufträge pro Jahr. Die fachliche Komplexität liegt in Regeln,
Versionen, Berechnungen, Freigaben und Reports, nicht in extremem Datenvolumen.

## Entscheidung

Das System wird als **modularer Laravel-Monolith** entwickelt:

- eine deploybare Webanwendung,
- eine relationale MySQL-Hauptdatenbank,
- klar getrennte Fachmodule innerhalb der Anwendung,
- asynchrone Jobs für Import, Export und E-Mail,
- privater Dateispeicher über Laravel Filesystem (lokaler Disk in V1).

Module:

- Identity & Access
- Stammdaten
- Produktkatalog & Kombinationen
- Pricing
- Dynamic Fields & Configuration
- Calculations
- Commercial Rules & Approvals
- Disposition
- Files & Communication
- Reporting & Audit

Module kommunizieren über definierte Services/Events und greifen nicht beliebig in
die internen Tabellen anderer Module.

Dateizugriff erfolgt ausschließlich über autorisierte Controller oder temporär
autorisierte Downloads. Es gibt keine direkt öffentlichen Datei-URLs. Der
Speicherzugriff ist hinter einem austauschbaren Adapter gekapselt, sodass später
ohne Änderung der Fachlogik auf S3 gewechselt werden kann.

## Begründung

- Transaktionen über Kalkulation, Freigabe und Snapshot bleiben beherrschbar.
- Betrieb, Deployment und lokale Entwicklung bleiben einfach.
- Das erwartete Volumen rechtfertigt keine Microservices.
- Fachmodule können trotzdem separat getestet und später gezielt extrahiert werden.

## Konsequenzen

- Modulgrenzen müssen im Code konsequent eingehalten werden.
- Lange Arbeiten laufen als Jobs, nicht im HTTP-Request.
- Spätere Salesforce-/Meridian-Schnittstellen werden als Adapter ergänzt.
- Eine eigenständige Rechen-/Regelkomponente bleibt innerhalb des Monolithen die
  autoritative fachliche Schicht.
- V1 setzt keinen Redis- und keinen S3-Betrieb voraus.
- Hosting und Deployment sind in ADR-003 beschrieben.

## Verworfene Alternative

Microservices in V1: höhere Betriebs-, Transaktions- und Entwicklungsaufwände ohne
erkennbaren Nutzen bei Nutzerzahl und Last.
