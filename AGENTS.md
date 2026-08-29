# Projektanweisungen für KI-Agenten

## Auftrag

Dieses Repository implementiert das interne Kalkulations- und Dispositionssystem
von more Marketing. Die Fachlichkeit ist komplex und kaufmännisch relevant.
Geschwindigkeit ist wichtig, fachliche Nachvollziehbarkeit und historische
Stabilität haben jedoch Vorrang.

## Vor jeder Änderung

1. Lies `docs/README.md` und die für die Aufgabe relevanten Fachdokumente.
2. Nenne die betroffenen Anforderungs-IDs, zum Beispiel `SPT-004` oder `APR-003`.
3. Prüfe bestehende Implementierung, Migrationen und Tests, bevor du Änderungen vorschlägst.
4. Frage nach, wenn eine fachliche Entscheidung weder dokumentiert noch eindeutig ableitbar ist.

## Verbindliche Regeln

- Erfinde keine Preise, Statusübergänge, Pflichtfelder oder Freigaberechte.
- Implementiere keine V2-Funktion, wenn sie nicht ausdrücklich beauftragt wurde.
- Jede Geld-, Rabatt-, AE-, TKP- und Payfaktorberechnung läuft autoritativ auf dem Server.
- Nutze Dezimaltypen für kaufmännische Werte; keine binären Floating-Point-Typen.
- Snapshots und Versionen dürfen durch spätere Adminänderungen nicht mutiert werden.
- Historien-, Kommentar- und Freigabeereignisse sind append-only.
- Ersteller und Freigeber dürfen niemals dieselbe Person sein.
- Automatisch ermittelte Buchungskennzeichen und Zuständigkeiten dürfen nicht manuell überschrieben werden.
- Keine Secrets, personenbezogenen Echtdaten oder produktiven Zugangsdaten committen.
- Keine destruktiven Migrationen ohne dokumentierten Migrations- und Rücksetzplan.

## Definition of Done

Eine Änderung ist erst fertig, wenn:

- die betroffenen Anforderungen erfüllt sind,
- positive und negative Tests vorhanden sind,
- Berechtigungen und Validierung serverseitig geprüft werden,
- Audit-/Snapshot-Auswirkungen berücksichtigt wurden,
- relevante Dokumentation aktualisiert wurde,
- Formatierung, statische Analyse und Tests erfolgreich durchlaufen,
- keine unabhängigen Nutzeränderungen überschrieben wurden.

## Arbeitsweise

- Kleine, abgeschlossene vertikale Schritte bevorzugen.
- Vor größeren Änderungen einen Plan mit betroffenen Dateien und Risiken erstellen.
- Bestehende Konventionen verwenden; neue Abstraktionen nur mit konkretem Nutzen einführen.
- Fehler klar und fachlich verständlich ausgeben.
- In Code, Tests und Pull Requests Anforderungs-IDs nennen, wenn Verhalten fachlich relevant ist.
- Bei Formeländerungen mindestens einen nachvollziehbaren Zahlenbeispieltest ergänzen.

## Sprache und Benennung

- Benutzeroberfläche und fachliche Dokumentation: Deutsch.
- Technische Klassen, Methoden, Tabellen und Spalten: konsistentes Englisch.
- Fachbegriffe mit feststehender Bedeutung dürfen deutsch bleiben, wenn eine Übersetzung missverständlich wäre.
- Datenbankspalten: `snake_case`; unveränderbare IDs getrennt von lesbaren Vorgangsnummern führen.

