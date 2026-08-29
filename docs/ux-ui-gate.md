# UX/UI-Gate (verbindlich vor Phase-1-Oberflächen)

- **Backlog-ID:** `BL-GATE-UXUI`
- **Status:** blockiert – Freigabe durch Product Owner ausstehend
- **Stand:** 29. August 2026

Dieses Gate liegt **zwischen Phase 0 und Phase 1**. Es ist keine V2-Idee und keine
offene Grundsatzfrage der Fachlogik. Ohne Freigabe werden **keine endgültigen
Fachseiten** gestaltet.

[`ui-ux-konzept.md`](ui-ux-konzept.md) bleibt die fachliche Navigations- und
Interaktionsbasis. Dieses Gate konkretisiert visuelles System, Muster und
Abnahmekriterien gemeinsam mit dem Product Owner.

## Erlaubt bis zur Freigabe

- technische Grundlagen (Auth-Flow serverseitig, Rollen, Audit, Schema)
- Headless-Komponenten ohne festgelegtes Enddesign
- Starter-Kit-Seiten nur als provisorische Hülle (Login/Health), nicht als
  abgenommenes Fach-UI

## Nicht erlaubt bis zur Freigabe

- endgültige Gestaltung von Kalkulation, Dispo, Admin, Listen, Wizard, Assistenten
- Festlegen von Farben, Typografie und App-Shell als verbindliches Design

## Mit dem Product Owner festzulegen

1. visuelles Designsystem
2. Farben, Typografie, Abstände und Oberflächen
3. Navigation und App-Shell
4. Tabellen-, Formular- und Filtermuster
5. Statusdarstellung
6. Kalkulations-Wizard
7. kontextbezogene Assistenten-/Pop-up-Logik
8. Desktop- und Tabletverhalten
9. Lade-, Leer-, Fehler- und Erfolgszustände
10. UX-Abnahmekriterien

## Erwartete Artefakte zur Freigabe

| Artefakt | Zweck |
|---|---|
| Designsystem (Token: Farbe, Typo, Abstand, Radius, Schatten) | verbindliche visuelle Quelle |
| App-Shell-Skizze (Desktop + Tablet) | Navigation, Kopfzeile, Arbeitsfläche |
| Musterkatalog Tabelle / Formular / Filter | wiederkehrende Listen und Eingaben |
| Status- und Pflichtanzeige | Prozessklarheit (`STA-*`, Pflichtfelder) |
| Wizard- und Assistentenfluss Kalkulation | geführte Positionserfassung |
| Pop-up-/Kontext-Assistent-Regeln | wann overlay, was blockiert, was informiert |
| Zustände: Laden, Leer, Fehler, Erfolg | konsistente Rückmeldung |
| UX-Abnahmekriterien (checkliste) | Freigabe und spätere UI-Tests |

Die bestehende Datei `ui-ux-konzept.md` darf referenziert und nach Freigabe
ergänzt werden; sie ersetzt dieses Gate nicht.
