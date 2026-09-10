# Umsetzungsplan V1

## Grundsatz

V1 wird in vertikalen, jeweils test- und vorführbaren Funktionspaketen umgesetzt.
Ein Paket umfasst Datenmodell, Backend, Oberfläche, Berechtigungen, Audit und Tests.
Es werden nicht zuerst alle Tabellen und danach alle Oberflächen gebaut.

## Phase 0 – Entscheidungen und Projektbasis

Ergebnisse:

- Architektur- und Technologie-ADR bestätigt,
- Repository und lokale Entwicklungsumgebung lauffähig,
- CI mit Formatierung, statischer Analyse und Tests,
- Umgebungsvariablen dokumentiert, keine Secrets im Repository.

Abnahme: Ein neuer Entwickler kann das Projekt anhand der README starten und einen
Smoke-Test ausführen. Technisch endgültig abgenommen, sobald GitHub Actions
`ci` und `mysql` grün sind (29.08.2026, BLK-004 erledigt).

Visuelles Designsystem, App-Shell und UI-Muster liegen in den gestuften
UX/UI-Gates ([`ux-ui-gate.md`](ux-ui-gate.md)). `UX-GATE-A` und `UX-GATE-B`
sind freigegeben. `UX-GATE-C` und `UX-GATE-D` bleiben blockiert.

## UX/UI-Gates

Vier Teil-Gates ersetzen das frühere `BL-GATE-UXUI`. Details:
[`ux-ui-gate.md`](ux-ui-gate.md).

| Gate | Inhalt | Status |
|---|---|---|
| UX-GATE-A | Designsystem, App-Shell, Navigation, gemeinsame Komponenten | freigegeben |
| UX-GATE-B | Kalkulations-Wizard, Mehrsenderplanung, Spot Classic | freigegeben |
| UX-GATE-C | Trailer/SWF, Influencer, Social, weitere Werbeelemente | blockiert |
| UX-GATE-D | Dispo, Freigaben, Standardangebote, Administration, Abschluss-UI | blockiert |

Freigegebene Gates werden als vertikale Pakete umgesetzt, nicht als komplette
Phasen 1–11 auf einmal. Blockierte Gates erzeugen nur Sperrzustände.

## Phase 1 – Anmeldung, Benutzer und Auditfundament

Umfang:

- lokaler Login und Passwort-Reset,
- Rollen Admin, Vertrieb, Disposition, Geschäftsführung, Produktmanagement,
- nutzerbezogene Rabattgrenze und Sonderfreigaberechte,
- Policy-/Autorisierungsgrundlage,
- append-only Auditereignisse,
- In-App-Benachrichtigungsgrundlage.

Relevante Anforderungen: `AUTH-*`, `AUD-*`, `GEN-001`, `GEN-003`.

Rolle Produktmanagement (`AUTH-006`, `AUTH-007`) gehört zur Policy-Grundlage und
erhält nicht automatisch Kundenkalkulations- oder Disporechte.

## Phase 2 – Stammdaten und Kombinationstabelle

Umfang:

- Organisation, Inventare und Kombi-Mitgliedschaften,
- Oberkategorien und Werbemittel,
- vollständige Einzelbearbeitung der Kombinationstabelle,
- Buchungskennzeichen, Einplanung, Hinweise und Filter,
- Kunden, Agenturen und Ansprechpartner.

Relevante Anforderungen: `ORG-*`, `ADV-*`, `MAT-*`, `CRM-*`.

## Phase 3 – Versionen, dynamische Felder und Snapshots

Umfang:

- Felddefinitionen und Optionen,
- Feldsets mit Entwurf/Aktiv/Archiviert,
- Pflicht-/Sichtbarkeitsregeln und Validierung,
- Admin-Vorschau und Versionsaktivierung,
- Kalkulations- und Positionssnapshot.

Relevante Anforderungen: `DYN-*`, `VER-*`, `ADM-001`, `ADM-002`.

Ist (Teil): DF-1/DF-2 Runtime, DF-3.1–3.2b Admin/Custom-Text, DF-3.3-fs freie
Feldset-Container, DF-3.3a1 Assignments/Resolver/Preview, DF-3.3a2α Gen2-Freeze,
DF-3.3a2β Gen3/VER-003 und DF-3.3b Assignment-Admin-UI (alles auf `main`; β via
PR #25, b via Feature-Branch `feat/df3-3b-assignment-admin-ui`).
Offen: Optionen, Regel-Editor; ADV-001c3b/c3c Katalog-Methoden-Admin / c4
Positionsauswahl; Inventar-/Preislisten-Admin.

Diese Phase muss vor der produktiven Kalkulation abgeschlossen sein; Snapshots
dürfen nicht nachträglich „angeflanscht“ werden.

## Phase 4 – Preislisten und klassische Spotkalkulation

Umfang:

- Preislisten-/Versionsmodell,
- Excel-Import mit Vorschau und atomarer Aktivierung,
- Tagesgruppenableitung,
- Durchschnittskalkulation mit mehreren Zeitfenstern,
- Kalenderplaner mit einzelnen Preisstunden (keine gruppierten Zeitschienen),
- frei editierbare tatsächliche Spotlänge je Sender-/Kombinationsposition,
- Mehrsender-/Kombipositionen und Live-Gesamtsumme,
- Spotlängenindex, Aufschläge und Komponenten,
- nachvollziehbare Rechenerklärung,
- Standardangebote als versionierte Vorlagen mit Übernahme in Kundenkalkulationen.

Relevante Anforderungen: `PRI-*`, `CAL-*`, `SPT-*`, `STD-*`.

Abnahme: `AT-01` bis `AT-04`, `AT-21`, `AT-23`, `AT-24`, `AT-28` bis `AT-31`.

## Phase 5 – SWF, Produktion und freie Preisbestandteile

Umfang:

- SWF-Durchschnitt, Planer und Festpreis ohne Spotlängenindex,
- gruppierte Zeitschienen und Standardlängen für Trailer/Allongen/weitere SWF zulässig,
- RHH-Initialregeln und CityLife-Variante,
- Produktionspreislisten,
- Produktion/Sonstiges und Überschreibungsfreigabe.

Relevante Anforderungen: `SWF-*`, `PRO-*`, `ADM-003`.

Abnahme: `AT-05`, `AT-11`.

## Phase 6 – Online Audio, Social Media und Events

Umfang:

- TKP-/Mindest-TKP-Modell,
- Plattform- und Mengenverteilung,
- technische und DMP-Targetings,
- Social-Pakete, Influencer-Unterpositionen und Booster,
- Event-/Promotionfelder und freie Preispositionen.

Relevante Anforderungen: `OA-*`, `SOC-*`, `EVT-*`.

Abnahme: `AT-08` bis `AT-10`.

## Phase 7 – Rabatt, AE, Festpreis und Sonderfreigaben

Umfang:

- konsekutive Rabattlogik,
- AE-Hierarchie und AE-fähige Basen,
- Festpreisrückrechnung,
- drei Payfaktoren,
- Freigabeauslöser und Freigabeinvalidierung,
- Budget-Assistent mit expliziter Übernahme.

Relevante Anforderungen: `COM-*`, `APR-*`, `AUTH-004`, `AUTH-005`, `BUD-*`.

Abnahme: `AT-06`, `AT-07`, `AT-09`, `AT-12`, `AT-13`, `AT-25` bis `AT-27`.

## Phase 8 – Dispoauftrag und Statusworkflow

Umfang:

- Positionsauswahl aus Kalkulation,
- unabhängiger Dispo-Snapshot und Nummerierung,
- Kundenbestätigung/Ausnahme,
- Vier-Augen-Prozess,
- vollständiges Statusmodell, Rückfrage und Sperren,
- Priorität und Bearbeitungsdatum.

Relevante Anforderungen: `DSP-*`, `STA-*`, `UPL-001` bis `UPL-003`, `TEC-*`.

Abnahme: `AT-12` bis `AT-19`, `AT-29`. Dispoaufträge nur aus Kundenkalkulationen.

## Phase 9 – Dateien, Kommentare und Benachrichtigungen

Umfang:

- zentrale Uploadliste und dynamische Dateifelder,
- Audio-Wiedergabe,
- Archivierung statt Löschung,
- append-only Kommentare,
- strukturierte Rückfragen/Antworten,
- E-Mail- und In-App-Ereignisse mit Wiederholung.

Relevante Anforderungen: `UPL-004` bis `UPL-007`, `CMT-*`, `NOT-*`.

## Phase 10 – Listen, Reports und Exporte

Umfang:

- Suche, Filter, Sortierung und persönliche Spalten,
- Umsatzreports nach den geforderten Dimensionen,
- reportfähige dynamische Felder,
- interne PDFs sowie Excel-/CSV-Exporte,
- Download-/Exportaudit.

Relevante Anforderungen: `REP-*`, `AUD-002`.

## Phase 11 – Härtung und Produktivsetzung

Umfang:

- vollständige Berechtigungs- und Negativtests,
- Performanceprüfung mit realistischem Datenvolumen,
- Backup-/Restore-Test,
- Browser- und Barrierearmutsprüfung,
- Initialdatenimport und fachliche Abnahme,
- Betriebsdokumentation und Monitoring.

Abnahme: gesamter `AT-01` bis `AT-31`-Katalog und nichtfunktionale Anforderungen.

Die ausführbare Aufgabenliste mit Status steht in [`backlog-v1.md`](backlog-v1.md).
Der aktuelle Stand steht in [`fortschritt.md`](fortschritt.md).

## Arbeitsregel für Cursor

Für jedes Paket:

1. Anforderungen und betroffene Dateien nennen.
2. Umsetzung in kleinen Commits planen.
3. Schema und Tests vor oder gemeinsam mit der Fachlogik erstellen.
4. Oberfläche erst an getestete serverseitige Regeln anbinden.
5. Dokumentation und Traceability aktualisieren.

