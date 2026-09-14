# Anforderungskatalog – Kalkulation und Disposition

> **Status:** Verbindlicher V1-Fachstand  
> **Stand:** 29. August 2026  
> **Pflege:** Änderungen nur mit betroffenen Anforderungs-IDs und angepassten Akzeptanztests.

Fachliches Lastenheft für das interne Websystem von more Marketing.

*Radiowerbezeiten, Sonderwerbeformen, Online Audio, Social Media, Events und weitere Inventare*

| **Dokument**    | **Festlegung**                                                                                               |
|-----------------|--------------------------------------------------------------------------------------------------------------|
| Version         | 1.0 - konsolidierter V1-Fachstand                                                                            |
| Stand           | 29\. August 2026                                                                                             |
| Zweck           | Grundlage für Aufwandsschätzung, technische Konzeption, Umsetzung und Abnahme                                |
| Quellen         | Fachworkshop und Antworten 1-99; Dispositionsauftrag 2026; Spotkalkulation 2026; RHH Trailerkalkulation 2026 |
| Geltungsbereich | Eine Organisation mit mehreren Sendern, Kombis und Inventaren                                                |

| **Leitentscheidung** V1 ersetzt die verteilten Excel- und manuellen Zwischenschritte durch einen nachvollziehbaren, versionierten End-to-End-Prozess von der Kalkulation bis zur internen Dispo-Zusammenfassung. Meridian und Salesforce bleiben in V1 manuell. |
|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|

# Inhaltsübersicht

Die folgende Übersicht bildet die Hauptkapitel ab. Für die Arbeit in Cursor können die Überschriften direkt über die Markdown-Navigation angesprungen werden.

- 1\. Dokumentzweck und Verbindlichkeit

- 2\. Zielbild, Nutzer und Mengengerüst

- 3\. Abgrenzung V1 und V2

- 4\. Rollen, Rechte und Vier-Augen-Prinzip

- 5\. Organisation, Sender, Kombis und Inventare

- 6\. Werbemittel, Oberkategorien und Kombinationstabelle

- 7\. Preislisten und Preisversionen

- 8\. Kalkulation: Objekt, Lebenszyklus und Summen (inkl. Mehrsender, Budget-Assistent, Standardangebote)

- 9\. Spotkalkulation

- 10\. SWF- und Trailerkalkulation

- 11\. Online Audio und Podcast

- 12\. Social Media, Influencer, Events und freie Preispositionen

- 13\. Produktion und Sonstiges

- 14\. Rabatt, AE, Festpreis und Payfaktor

- 15\. Freigaben und Sperrlogik

- 16\. Dispoauftrag und Positionsübernahme

- 17\. Statusmodell und operative Bearbeitung

- 18\. Pflichtfelder, Rechnung, Uploads und Kommentare

- 19\. Dynamisches Feldsystem und Snapshots

- 20\. Administration und Initialdaten

- 21\. Suche, Listen, Reports und Exporte

- 22\. Historie, Benachrichtigungen und Nachvollziehbarkeit

- 23\. Fachliches Datenmodell und technische Leitplanken

- 24\. Nichtfunktionale Anforderungen

- 25\. Abnahme- und Testkatalog

- 26\. Initialkataloge

- 27\. Liefergegenstände vor Produktivsetzung

# 1. Dokumentzweck und Verbindlichkeit

Dieses Dokument beschreibt den verbindlichen fachlichen Sollzustand für V1. Es ist so formuliert, dass Produktverantwortliche, Entwicklung, UX, Testing und spätere Administratoren dieselbe Regelbasis verwenden. Die Excel-Dateien dienen als fachliche Referenz und Initialdatenquelle; bestehende Kalkulationen oder Dispoaufträge werden nicht migriert.

## 1.1 Anforderungssprache

| **Begriff** | **Bedeutung**                                                                                |
|-------------|----------------------------------------------------------------------------------------------|
| MUSS        | Für V1 zwingend umzusetzen und abnahmerelevant.                                              |
| SOLL        | Für V1 vorgesehen; eine Abweichung muss fachlich begründet und freigegeben werden.           |
| KANN        | Optionale Erweiterung ohne Abnahmeblocker.                                                   |
| V2          | Bewusst nicht Bestandteil von V1; Architektur darf die Erweiterung nicht unnötig verhindern. |

**GEN-001** Alle als MUSS formulierten Regeln gelten serverseitig. Eine reine Prüfung im Browser ist nicht ausreichend.

**GEN-002** Geld-, Rabatt-, AE- und TKP-Berechnungen müssen deterministisch, reproduzierbar und über gespeicherte Eingabewerte sowie Preis-/Regelversionen erklärbar sein.

**GEN-003** Die Benutzeroberfläche ist deutschsprachig; Datums-/Zeitdarstellung erfolgt deutsch und in der Zeitzone Europe/Berlin. Währung ist EUR.

# 2. Zielbild, Nutzer und Mengengerüst

## 2.1 Geschäftliches Ziel

- Kalkulation von Werbemitteln für Sender, Kombis, digitale Produkte und Events in einem System.

- Speichern, Suchen, Bearbeiten, Kopieren und historisch stabiles Wiederöffnen von Kalkulationen.

- Erzeugen eines oder mehrerer Dispoaufträge aus ausgewählten Kalkulationspositionen.

- Vollständige Übergabe kaufmännischer und operativer Informationen an die Disposition.

- Abbildung von Pflichtfeldern, Freigaben, Uploads, Kommentaren, Rückfragen und Status.

- Flexible Konfiguration von Werbemitteln, Feldern, Regeln, Preisen und Kombinationen durch Admin.

- Senderbezogene und senderübergreifende Auswertungen auf einer gemeinsamen Datenbasis.

## 2.2 Nutzer und Lastannahmen

| **Kenngröße**                   | **V1-Annahme**                                                                             |
|---------------------------------|--------------------------------------------------------------------------------------------|
| Interne Nutzer                  | ca. 20                                                                                     |
| Kalkulationen und Dispoaufträge | ca. 3.000 Vorgänge pro Jahr                                                                |
| Nutzung                         | intern, desktop-first; Tablet-Darstellung soll bedienbar bleiben                           |
| Parallelität                    | mehrere Nutzer können gleichzeitig in Listen und unterschiedlichen Vorgängen arbeiten      |
| Datenhaltedauer                 | langfristig; historische Vorgänge dürfen durch Stammdatenänderungen nicht verändert werden |

## 2.3 End-to-End-Prozess

1.  Vertrieb legt eine Kalkulation mit Kunde, optionaler Agentur und mindestens einer Werbemittelposition an. Optional entsteht die Kalkulation durch Übernahme eines veröffentlichten Standardangebots (`STD-004`, `STD-005`).

2.  Das System berechnet Listenpreise, Aufschläge, Rabatte, AE, N/N-Invest und Payfaktoren; Sonderfreigaben werden erkannt. Optional erzeugt der Pfad „Mit Budget planen“ Mengenvorschläge, die erst nach expliziter Übernahme gelten (`BUD-008`).

3.  Vertrieb wählt Kalkulationspositionen aus und erzeugt daraus einen nummerierten Dispoauftrag als Snapshot.

4.  Kundenbestätigung oder begründete Ausnahme wird ergänzt; erforderliche kaufmännische Sonderfreigabe erfolgt zuerst.

5.  Eine zweite berechtigte Person erteilt die Vertriebsfreigabe. Ersteller und Freigeber dürfen nicht identisch sein.

6.  Disposition übernimmt den Auftrag, koordiniert weitere Bereiche, bucht manuell in Meridian und pflegt Status/Rückfragen.

7.  Der Mediaberater überträgt die relevanten Informationen manuell nach Salesforce.

8.  Nach vollständiger Bearbeitung wird der Auftrag disponiert und abgeschlossen; Storno bleibt mit Begründung möglich.

# 3. Abgrenzung V1 und V2

| **Thema**           | **V1**                                                            | **Spätere Ausbaustufe**                                      |
|---------------------|-------------------------------------------------------------------|--------------------------------------------------------------|
| Kombinationstabelle | Vollständige Einzelbearbeitung, Filter, Aktivierung, Regeln       | Massenimport, Kopieren, komplexe Matrixwerkzeuge             |
| Feldsystem          | Dynamische Felder, Regelwerk, Snapshots und Feldset-Versionierung | Komfortfunktionen und erweiterte Regeltypen                  |
| Preislisten         | Excel-Import mit Vorschau/Prüfung und Einzelbearbeitung           | Saisonlogik, preislistenübergreifende Kampagnen-Mixes        |
| Schnittstellen      | Keine; Meridian/Salesforce und Stammdaten manuell                 | Meridian-/Salesforce-Schnittstellen                          |
| Login               | Lokale Konten mit E-Mail/Passwort, keine verpflichtende 2FA       | Microsoft-365-/Entra-ID-SSO, optional 2FA                    |
| Dokumente           | Interne Kalkulationsübersicht, Dispoauftrag und Reports           | Kundenangebot und Kundenportal bei späterem Bedarf           |
| Rechnung per Ende   | Monate mehrfach wählbar, keine Betragsaufteilung                  | Betragsaufteilung nach Monaten                               |
| Vertretung          | Keine Abwesenheits-/Vertreterregel                                | Delegation und Abwesenheitslogik                             |
| Budgetplanung       | Deterministische Mengenvorschläge ohne Reichweite/KI (`BUD-009`)  | Reichweiten- oder KI-Optimierung                             |
| Standardangebote    | Interne Vorlagen ohne Kundenbindung; Übernahme in Kundenkalkulation | Kein Kundenangebot; bleibt durch `SCP-001` ausgeschlossen    |
| Audio               | Abspielen/Download, Länge manuell prüfen                          | Automatische Audiolängenprüfung                              |
| Weitere Teams       | Disposition/Projektmanagement koordiniert zentral                 | Direkte Zugänge für OAP, PDM, Redaktion, Moderatoren, Events |

**SCP-001** V1 erzeugt kein Kundenangebot und keine Auftragsbestätigung. Der PDF-Dispoauftrag ist eine interne Zusammenfassung zur Einbuchung.

**SCP-002** Es findet keine Übernahme alter Kalkulationen oder Dispoaufträge statt.

# 4. Rollen, Rechte und Vier-Augen-Prinzip

## 4.1 Rollen

| **Rolle**         | **Kernrechte in V1**                                                                                                                                                                   |
|-------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Admin             | Gesamte Administration; alle Vorgänge sehen/bearbeiten; Freigaben; erzwingende Aktionen mit Begründung; Uploads archivieren; Standardangebote vollständig.                              |
| Vertrieb          | Alle Kalkulationen und Dispoaufträge sehen; Kalkulationen erstellen/bearbeiten; veröffentlichte Standardangebote ansehen und übernehmen; Dispoaufträge anlegen; Freigaben im Rahmen der Berechtigungen; Rückfragen beantworten. |
| Disposition       | Freigegebene Dispoaufträge operativ bearbeiten; operative Felder, Materialstatus, Rechnung-per-Ende, Kommentare und Status pflegen; keine eigenständige Änderung kaufmännischer Werte. |
| Geschäftsführung  | Alle Vorgänge sehen/bearbeiten; alle Freigaben erteilen; auswerten; abgeschlossene Vorgänge mit Begründung wieder öffnen; Standardangebote vollständig.                                |
| Produktmanagement | Standardangebote erstellen, bearbeiten, versionieren, veröffentlichen, archivieren und dafür Preis-/Produkt-Snapshots verwenden. Kein automatischer Zugriff auf Kundenkalkulationen oder Dispoaufträge. |

**AUTH-001** Berechtigungen werden rollenbasiert und für Sonderrechte zusätzlich nutzerbezogen geprüft.

**AUTH-002** Vertrieb sieht standardmäßig alle Kalkulationen und Dispoaufträge, nicht nur eigene Vorgänge.

**AUTH-003** Geschäftsführung darf alle fachlichen und administrativen Vorgänge bearbeiten.

**AUTH-004** Der Ersteller eines Dispoauftrags darf weder dessen Vier-Augen-Freigabe noch eine erforderliche Sonderfreigabe selbst erteilen.

## 4.2 Kaufmännische Sonderfreigabe

Eine Sonderfreigabe ist ein eigener Freigabetyp und nicht mit der allgemeinen Vier-Augen-Prüfung gleichzusetzen. Auslöser sind insbesondere Rabattüberschreitung, Online-Audio-Basis-TKP unter Mindest-TKP, Online-Audio-Festpreis sowie begründungspflichtige Produktionspreisüberschreibungen.

- Geschäftsführung ist immer berechtigt.

- Admin ist immer berechtigt.

- Einzelne Vertriebsmitarbeiter können ein explizites Sonderfreigaberecht erhalten.

- Gewöhnliche Vertriebsmitarbeiter ohne Sonderrecht sind nicht berechtigt.

**AUTH-005** Eine Person mit beiden Rechten darf Sonderfreigabe und Vier-Augen-Freigabe in einem Bedienvorgang erteilen, sofern sie nicht Ersteller ist. Das System speichert dennoch zwei getrennte Freigabeprotokolle.

**AUTH-006** Die Rolle Produktmanagement darf Standardangebote erstellen, bearbeiten, versionieren, veröffentlichen, archivieren und die dafür benötigten Preis-/Produkt-Snapshots verwenden. Admin und Geschäftsführung behalten umfassende Rechte einschließlich dieser Funktionen.

**AUTH-007** Produktmanagement erhält nicht automatisch Zugriff auf Kundenkalkulationen oder Dispoaufträge. Solche Rechte müssen gesondert über die vorhandene Berechtigungslogik erteilt werden.

# 5. Organisation, Sender, Kombis und Inventare

Das System bildet genau eine Organisation ab. Sender, Kombis und digitale/eventbezogene Inventare werden mandantenähnlich als eigenständige Buchungsdimension geführt, ohne organisatorische Datentrennung. Auswertungen müssen je Inventar und übergreifend möglich sein.

**ORG-001** Jedes Inventar besitzt eine stabile technische ID, Anzeigenamen, Kurzcode, Typ, Aktivstatus und Sortierreihenfolge.

**ORG-002** Kombis sind eigenständige buchbare Inventare und werden in Kalkulation, Preislisten, Werbemittelregeln und Disposition als eigenständige Inventare behandelt. Sie besitzen eigene Preise und eigene fachliche Konfiguration. Eine technische oder operative Pflege enthaltener Sender ist in V1 nicht erforderlich.

**ORG-003** Admin kann Inventare in V1 einzeln anlegen, ändern, aktivieren und deaktivieren. Historische Snapshots bleiben unverändert.

## 5.1 Initiale Inventare

| **Nr.** | **Inventar**                                      |
|---------|---------------------------------------------------|
| 1       | MORE Hamburg-Kombi                                |
| 2       | Hamburg-Kombi+                                    |
| 3       | Radio Hamburg                                     |
| 4       | ROCK ANTENNE Hamburg                              |
| 5       | 80er 90er OLDIE ANTENNE Hamburg                   |
| 6       | CARAVAN.fm                                        |
| 7       | MORE-Kombi Online Audio                           |
| 8       | MORE-Kombi Podcast                                |
| 9       | ffn Hamburg Plus                                  |
| 10      | RADIO BOLLERWAGEN DAB+ Hamburg                    |
| 11      | MORE-Kombi Events Radio Hamburg                   |
| 12      | MORE-Kombi Events 80er 90er OLDIE ANTENNE Hamburg |
| 13      | MORE-Kombi Events CARAVAN.fm                      |
| 14      | MORE-Kombi Events ROCK ANTENNE Hamburg            |

| **Namenszuordnung** HAMBURG ZWEI ist in der MORE Hamburg-Kombi durch 80er 90er OLDIE ANTENNE Hamburg ersetzt. Die Excel-Blätter 'radio ffn' und 'BOLLERWAGEN' entsprechen ffn Hamburg Plus bzw. RADIO BOLLERWAGEN DAB+ Hamburg. |
|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|

# 6. Werbemittel, Oberkategorien und Kombinationstabelle

## 6.1 Oberkategorien

| **Oberkategorie**       | **Steuert standardmäßig**                                                    |
|-------------------------|------------------------------------------------------------------------------|
| Spots                   | Durchschnitt, Planer, Festpreis; Spotlängenindex; Spotkomponenten            |
| SWF / Sonderwerbeformen | Durchschnitt, Planer, Festpreis; Aufschläge; kein Spotlängenindex            |
| Online Audio            | TKP, Targeting, Plattform-/Mengenverteilung, Sonderfreigaben                 |
| Social Media / Online   | Element-/Paketpreise, Influencer-Unterpositionen, Booster, dynamische Felder |
| Events / Promotion      | Fixe/freie Preispositionen, Eventdaten, dynamische Felder                    |
| Gegengeschäft           | Eigene Auswertungs- und Buchungslogik                                        |

**ADV-001** Jedes Werbemittel ist genau einer Oberkategorie zugeordnet. Die Oberkategorie liefert Standard-Kalkulationsarten, Standard-/Pflichtfelder, Dispo-Feldsets, Auswertungslogik und Default-Eigenschaften für Rabatt, AE und Preispositionen.

**ADV-002** Ein konkretes Werbemittel darf die Kategorie-Vorgaben um eigene Felder und Regeln ergänzen oder zulässige Systemfeld-Eigenschaften überschreiben.

**ADV-003** Produktion/Sonstiges ist kein normales Werbemittel, sondern eine Zusatzzeile innerhalb einer Werbemittelposition.

## 6.2 Zentrale Kombinationstabelle

Die Kombinationstabelle ist die verbindliche Whitelist zwischen Inventar und Werbemittel. Nur aktive, erlaubte Kombinationen sind in der Kalkulation auswählbar.

| **Attribut**           | **Regel**                                                                                                   |
|------------------------|-------------------------------------------------------------------------------------------------------------|
| Inventar + Werbemittel | Eindeutiger fachlicher Schlüssel; historisch über stabile IDs referenziert                                  |
| Buchungskennzeichen    | Automatisch, Pflicht im Dispoauftrag, gesperrt, nicht preisrelevant, in der Kalkulation noch nicht sichtbar |
| Einplanung durch       | Automatisch, Pflicht, gesperrt; Dispo korrigiert den Wert nicht                                             |
| Hinweistext            | Im Dispoauftrag sichtbar; admin-pflegbar und versioniert                                                    |
| Oberkategorie          | Aus Werbemittel abgeleitet und im Snapshot gespeichert                                                      |
| Aktivstatus            | Inaktive Kombinationen nicht neu auswählbar; historische Vorgänge bleiben lesbar                            |
| Sortierung             | Steuert Auswahl- und Adminlisten                                                                            |
| Planungsverbot         | Wert 'darf nicht geplant werden' verhindert die Auswahl/Übergabe                                            |

**MAT-001** Admin kann in V1 jede Kombination einzeln anlegen, bearbeiten, aktivieren/deaktivieren und vollständig konfigurieren.

**MAT-002** Admin kann nach Inventar, Werbemittel, Oberkategorie, Einplanung durch und Buchungskennzeichen filtern.

**MAT-003** Single-Spots für Hamburg-Kombi+, RADIO BOLLERWAGEN DAB+ Hamburg und ffn Hamburg Plus werden ausschließlich über nicht erlaubte Kombinationen verhindert.

**MAT-004** Massenimport, Kopierfunktionen und komplexe Matrixbearbeitung sind V2.

## 6.3 Initiale Werte

Initiale Buchungskennzeichen umfassen insbesondere Spots (L), SWF (K), Online Audio (UA), Social Media (US), Mod-Influencer (UI), Gegengeschäft (B), Events/Promotion (P), Spotproduktion/Sonstige (S) und UC. Die vollständige Zuordnung wird aus der vorhandenen Matrix importiert.

Mögliche Werte für 'Einplanung durch': Disposition, OAP, PDM-Digital / Niklas Farin, Redaktion, Moderator, Events, darf nicht geplant werden sowie kombinierte Hinweise wie 'Disposition, bitte Abbinder nutzen'.

# 7. Preislisten und Preisversionen

**PRI-001** Jedes Inventar besitzt eigenständige Preislisten. Eine Kombi wird nicht aus Preisen einzelner Sender berechnet.

**PRI-002** Preislisten werden jahresbezogen versioniert. Die aktuelle Jahrespreisliste ist vorausgewählt; Vertrieb darf eine andere aktive Preisliste wählen.

**PRI-003** Eine Kalkulation ist ohne Kampagnenzeitraum möglich. Ein Zeitraum über mehrere Preislisten wird in V1 nicht automatisch gemischt.

**PRI-004** Preise und Regeln werden beim Erstellen einer Kalkulationsposition als fachlicher Snapshot referenziert bzw. gespeichert. Spätere Preisänderungen verändern alte Kalkulationen nicht.

## 7.1 Import und Pflege

- Strukturierter Excel-Import durch Admin mit Upload, Zuordnung der Spalten, Vorschau, Validierung und Fehlerbericht.

- Import wird erst nach expliziter Bestätigung wirksam und erzeugt eine neue Preislistenversion.

- Admin darf einzelne Preise in der Oberfläche nachbearbeiten; jede Änderung erzeugt eine nachvollziehbare Version/Änderungshistorie.

- Doppelte Schlüssel, fehlende Pflichtwerte, negative Preise, ungültige Stunden und unbekannte Inventare/Werbemittel blockieren die Aktivierung.

- Die Importdatei und der Importbericht werden auditierbar am Preislistenlauf gespeichert.

## 7.2 Tagesgruppen

Importiert bzw. gepflegt werden ausschließlich Stunden-Sekundenpreise für die
Basisgruppen Mo-Fr, Samstag und Sonntag. **PO-PRI-HOURS-1:** Die buchbaren
Stunden dürfen je Basis-Tagesgruppe unterschiedlich sein. Fehlt eine Basiszeile
für `(Stunde, Tagesgruppe)`, ist die Kombination nicht buchbar (nicht Preis 0).
Abgeleitete Gruppen werden je Uhrstunde nur berechnet, wenn die erforderlichen
Basispreise derselben Stunde vorliegen:

| **Mo-Sa** P(Mo-Sa, h) = \[5 x P(Mo-Fr, h) + P(Sa, h)\] / 6 |
|------------------------------------------------------------|

| **Mo-So** P(Mo-So, h) = \[5 x P(Mo-Fr, h) + P(Sa, h) + P(So, h)\] / 7 |
|-----------------------------------------------------------------------|

**PRI-005** Die abgeleiteten Tagesgruppen werden nicht als unabhängige Eingabepreise gepflegt. Eine Änderung der Basiswerte muss sie reproduzierbar neu berechnen.

**PRI-006** Intern wird mit mindestens vier Dezimalstellen gerechnet. Anzeige und Positions-/Auftragssummen werden kaufmännisch auf zwei Cent gerundet.

# 8. Kalkulation: Objekt, Lebenszyklus und Summen

## 8.1 Kopfdaten

- genau ein Kunde

- optional eine Agentur

- Mediaberater

- Kampagne / Produkt / Titel

- eine oder mehrere Werbemittelpositionen

- gewählte Preisliste je Position

- Summenblock mit Mediabrutto, Rabatt, AE, N/N-Invest und Payfaktoren

**CAL-001** Eine Kalkulation kann parallel mehrere Sender und/oder Kombis mit unterschiedlichen Werbemitteln, Spotlängen, Mengen, Preisstunden und Rabatten enthalten. Mehrere Inventare, Werbemittel und Kalkulationsarten in einer Kalkulation bleiben zulässig. Akzeptanzbeispiel: 10 Spot-Classic-Spots bei Radio Hamburg und 5 Spot-Classic-Spots bei ROCK ANTENNE Hamburg in derselben Kalkulation.

**CAL-002** Die Kalkulationsart wird je Position gewählt. Ein späterer Wechsel ist nicht frei erlaubt; der Nutzer legt bei Bedarf eine neue Position an.

**CAL-003** Kalkulationen können gespeichert, bearbeitet, kopiert, archiviert, gefiltert und wieder geöffnet werden.

**CAL-004** Aus einer Kalkulation können mehrere Dispoaufträge entstehen, ohne dass die Kalkulation formal als bestätigt markiert sein muss. Das gilt nur für reguläre Kundenkalkulationen, nicht für Standardangebote (`STD-007`, `DSP-007`).

**CAL-005** Die Gesamtsumme der Kalkulation wird live aus allen Positionen gebildet. Jede Position bleibt separat editierbar und in der Rechenerklärung nachvollziehbar.

## 8.2 Positionsstruktur

| **Ebene**               | **Beispiele**                                                                                         |
|-------------------------|-------------------------------------------------------------------------------------------------------|
| Werbemittelposition     | Inventar, Werbemittel, Kalkulationsart, Preisliste, Zeitraum/offen, Preis-/Rabatt-/AE-Werte, Hinweise |
| Komponente              | Hauptspot, Allonge, Abbinder, Reminder; eigene Bezeichnung und Länge, separat sichtbar                |
| Plattform-/Mengenanteil | Online Audio: Plattform, Pre-/In-Stream, Impressions/AIs                                              |
| Element/Unterposition   | Social Paket: Story, Reel, Post; Influencer: je Influencer eigene Menge/Preis                         |
| Zusatzpreiszeile        | Produktion, Fremdkosten, Sonstiges, Boosterbudget                                                     |
| Dynamische Werte        | Feldset- und werbemittelspezifische Angaben                                                           |

## 8.3 Rechen- und Rundungsreihenfolge

1.  Zeitraumssummen eines Werbeelements berechnen (Stunden, Ø-Preis, Länge, Index, Aufschlag, Zeitraum-Spots).

2.  Zeitraumssummen zum Brutto des Werbeelements addieren.

3.  Rabattierbare und nicht rabattierbare Preiszeilen trennen.

4.  Rabatte des Werbeelements nacheinander anwenden.

5.  Rabattierte Werbeelemente zur Auftragssumme addieren; Auftragsrabatte nacheinander anwenden.

6.  AE auf den rabattierten, AE-fähigen Betrag anwenden.

7.  N/N-Invest und Payfaktoren berechnen; nicht rabattierbare/nicht AE-fähige Zusatzzeilen ergänzen.

8.  Intern vier Dezimalstellen halten; Position und Auftrag auf zwei Cent runden.

## 8.4 Budget-Assistent

Der Budget-Assistent ist eine optionale Hilfe in der Kundenkalkulation. Er erzeugt deterministische Mengenvorschläge, ändert aber nichts ohne explizite Übernahme.

**BUD-001** Ein Zielbudget ist optional. Wird es gesetzt, muss es ein numerisches EUR-Feld mit Validierung sein; ein unstrukturiertes Textfeld ist unzulässig.

**BUD-002** Das Zielbudget in V1 ist N/N-Invest. Beim Pfad „Selbst planen“ dient es nur dem Vergleich mit dem aktuellen N/N-Invest. Beim Pfad „Mit Budget planen“ ist es die Vorgabe für den Vorschlag.

**BUD-003** Der Vorschlag berücksichtigt die ausgewählten Sender/Kombis, Preisstunden, Spotlängen, Positionsrabatte, AE und den zusätzlichen Auftragsrabatt.

**BUD-004** Vorschläge sind deterministisch, nachvollziehbar und immer editierbar.

**BUD-005** Der Pfad „Mit Budget planen“ erzeugt einen neuen Vorschlag. Ein „bestehendes Senderverhältnis“ wird nicht verwendet, weil keine manuelle Vorplanung vorausgesetzt wird.

**BUD-006** V1-Verteilungslogiken: Budget je ausgewähltem Sender/Kombi gleich verteilen; ganzzahlige Spotanzahl innerhalb der gewählten Preisstunden maximieren.

**BUD-007** Spotmengen im Vorschlag sind ganzzahlig. Ein Rest unter dem Zielbudget oder eine geringfügige Überschreitung durch Ganzzahligkeit wird transparent ausgewiesen.

**BUD-008** Der Vorschlag verändert die Kalkulation nicht automatisch. Erst eine explizite Übernahme schreibt die Mengen in die Positionen; danach bleiben sie frei editierbar.

**BUD-009** V1 behauptet keine Reichweiten- oder KI-Optimierung. Belastbare Reichweiten- oder Leistungsdaten sind nicht Teil der Berechnungsgrundlage.

## 8.5 Standardangebote

Ein Standardangebot ist eine versionierte, sender- bzw. kombibezogene Kalkulationsvorlage ohne Kundenbindung. Es besitzt einen eigenen Navigationspunkt und ist kein Kundenangebot im Sinne von `SCP-001`.

**STD-001** Ein Standardangebot ist an Sender und/oder Kombis gebunden, besitzt keine Kunden- oder Agenturzuordnung und dient als Vorlage für spätere Kundenkalkulationen.

**STD-002** Zulässige Status sind Entwurf, veröffentlicht und archiviert. Nur veröffentlichte Versionen sind für Vertrieb zur Übernahme sichtbar.

**STD-003** Die linke Navigation enthält den eigenen Punkt Standardangebote. Sichtbarkeit richtet sich nach Rolle (`AUTH-006`, `AUTH-007`).

**STD-004** Vertrieb darf veröffentlichte Standardangebote ansehen und die Aktion Übernehmen ausführen.

**STD-005** Übernehmen erzeugt eine eigenständige Kundenkalkulation als Snapshot der veröffentlichten Version. Änderungen an der Kundenkalkulation verändern niemals das Standardangebot; Änderungen am Standardangebot verändern niemals bereits übernommene Kalkulationen.

**STD-006** Vertrieb darf die übernommene Kundenkalkulation anpassen, Senderpositionen ändern, Spotmengen skalieren und den Budget-Assistenten verwenden.

**STD-007** Ein Dispoauftrag darf nur aus der übernommenen regulären Kundenkalkulation entstehen, niemals direkt aus dem Standardangebot (`DSP-007`).

**STD-008** Jede Version speichert Autor, Veröffentlichungszeitpunkt sofern veröffentlicht, vollständige Versionshistorie und ist auditierbar (`AUD-001`, `AUD-002`).

**STD-009** Produktmanagement, Admin und Geschäftsführung dürfen Standardangebote anlegen, bearbeiten, versionieren, veröffentlichen und archivieren und dafür Preis-/Produkt-Snapshots verwenden.

# 9. Spotkalkulation

## 9.1 Zulässige Kalkulationsarten

| **Kalkulationsart** | **Eingabe**                                               | **Preisermittlung**                                                |
|---------------------|-----------------------------------------------------------|--------------------------------------------------------------------|
| Durchschnitt        | Tagesgruppe, ein/mehrere Preiszeiträume mit Spotanzahl je Zeitraum, Länge | Jeder Zeitraum separat (Ø der enthaltenen Stunden × Zeitraum-Spots); Summe der Zeitraumssummen |
| Planer/Kalender     | Reale Daten; Spotzahl je Datum und Stunde; Länge          | Jeder Spot zum Stundenpreis des automatisch ermittelten Wochentags |
| Festpreis           | Vereinbarter N/N-Endpreis                                 | Effektiver Rabatt und Payfaktor werden rückwärts ermittelt         |

## 9.2 Durchschnittskalkulation

**SPT-001** Vertrieb erfasst je Werbeelement mindestens einen Preiszeitraum: Beginn, Ende, Tagesgruppe (Mo-Fr, Sa, So, Mo-Sa oder Mo-So) und Spotanzahl. Die Gesamtspotzahl ist die Summe der Zeitraum-Spots und nicht unabhängig editierbar.

**SPT-002** Das Ende ist exklusiv. Ein Zeitraum 08:00-18:00 umfasst die Preisstunden 8 bis einschließlich 17 (Anzeige bis 17:59). Stunde 18 gehört nicht zum Zeitraum. 08:00-09:00 entspricht der bisherigen einzelnen Preisstunde 8.

**SPT-003** Innerhalb derselben Tagesgruppe sind Überschneidungen unzulässig. Direkt angrenzende Zeiträume sind erlaubt. Gleiche Uhrzeiten in unterschiedlichen Tagesgruppen sind erlaubt.

**SPT-004** Jeder Zeitraum wird separat aus dem gleichgewichteten Durchschnitt seiner Stunden berechnet und mit der Spotanzahl dieses Zeitraums multipliziert. Die Zeitraumssummen werden addiert. Ein ungewichteter Durchschnitt über alle Zeiträume, danach multipliziert mit der Gesamtspotzahl, ist unzulässig.

| **Formel Durchschnitt** Je Zeitraum: Sekundenpreis_avg = Summe der Sekundenpreise der Stunden start … Ende exklusiv − 1 / Anzahl dieser Stunden. Zeitraumssumme = Sekundenpreis_avg x tatsächliche Gesamtlänge x Spotlängenindex / 100 x (1 + Aufschlag) x Spots des Zeitraums. Brutto Werbeelement = Summe der Zeitraumssummen. |
|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|

## 9.3 Planer/Kalender

**SPT-005** Der Planer verwendet reale Datumswerte und leitet Wochentag, Tagesgruppe und gültigen Stundenpreis automatisch ab.

**SPT-006** Pro Datum und Stunde kann eine nichtnegative ganzzahlige Spotanzahl eingetragen werden. Leere Zellen entsprechen null.

**SPT-007** Die Kampagnendauer ist fachlich nicht begrenzt. Die Oberfläche bietet mindestens Wochen- und Monatsnavigation.

**SPT-008** Die konkrete Datum-/Stundenverteilung wird im Dispoauftrag lesbar angezeigt und exportiert.

## 9.4 Spotlängenindex und Aufschläge

| **Länge**      | **Index** |
|----------------|-----------|
| 1-15 Sekunden  | 110       |
| 16-24 Sekunden | 105       |
| 25-34 Sekunden | 100       |
| ab 35 Sekunden | 95        |

**SPT-009** Die Indexstaffel gilt initial für alle Sender und Spot-Werbemittel. Ab 100 Sekunden gilt weiterhin Index 95.

**SPT-010** Single-Spot maximal 45 Sekunden ist nur ein Hinweis. Längere Single-Spots bleiben plan- und kalkulierbar.

**SPT-011** Werbemittelaufschläge werden prozentual auf den längenbereinigten Spotpreis gerechnet und je Inventar-Werbemittel-Kombination konfiguriert. Initial: Single-Spot +50 %, Erst-/Letztplatzierung +30 %.

## 9.5 Komponenten und Gesamtlänge

**SPT-012** Tandem/Reminder und Tridem werden über die Gesamtlänge aller Komponenten kalkuliert.

**SPT-013** Hauptspot, Allonge, Abbinder und Reminder bleiben als einzelne Komponenten mit tatsächlicher Länge sichtbar.

**SPT-014** Admin kann je Kombination für Allonge/Komponenten die Berechnungsart 'einzeln berechnen' oder 'gemeinsame Gesamtlänge' festlegen.

**SPT-015** Jede Spot-Classic-Position besitzt eine frei editierbare tatsächliche Spotlänge in Sekunden. Sie ist kein gesperrter Standardwert. Administrativ gepflegte Standardlängen sind ausschließlich Vorbelegungen und keine Beschränkung. Die Länge ist in der Kalkulation direkt bei jeder Sender- bzw. Kombinationsposition sichtbar, wird in der Preisberechnung verwendet und im Dispo-Snapshot ausgewiesen.

**SPT-016** Klassische Spot-Durchschnittsplanung verwendet Preiszeiträume mit exklusivem Ende und Spotanzahl je Zeitraum (`SPT-001`–`SPT-004`). Der Kalenderplaner bleibt stunden- und datumsbezogen (`SPT-005`–`SPT-008`). Trailer, Allongen und weitere SWF aus der Trailerkalkulation dürfen abweichend konfigurierte Standardlängen und gruppierte Zeitschienen verwenden (`SWF-004`, `SWF-008`).

# 10. SWF- und Trailerkalkulation

SWF verwendet dieselben drei Bedienmodelle wie Spots, jedoch ohne Spotlängenindex. Grundlage sind inventar- und stundenbezogene Sekundenpreise, tatsächliche Länge und konfigurierbare Werbemittelaufschläge.

| **Formel SWF** Positionsbrutto = Anzahl x Sekundenpreis der Tagesgruppe/Stunde x tatsächliche Länge x (1 + Aufschlag). Bei Durchschnitt: gleichgewichteter Sekundenpreis der ausgewählten Stunden. Beim Planer: Summe je Datum/Stunde. |
|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|

**SWF-001** SWF unterstützt Durchschnittskalkulation, Planer/Kalender und Festpreis.

**SWF-002** Es wird kein Spotlängenindex angewendet.

**SWF-003** Premium-, Tages-, Abend- und Wochenend-Trailer sind keine eigenen Werbemittel. Sie ergeben sich aus Tagesgruppe und Uhrzeit.

**SWF-004** Standardlängen sind administrativ pflegbar und durch Vertrieb änderbar. Initiale RHH-Referenzen: Trailer 20 s, Allonge 10 s, Promo 20 s, CityLife 20 s, Gewinnspieldurchgang 25 s.

**SWF-005** Initiale RHH-Aufschläge aus der Trailerkalkulation: Trailer +30 %, Allonge +30 %, Promo +50 %, CityLife +50 %, Gewinnspieldurchgang +50 %. Die Werte sind als Admin-Daten, nicht als Hardcode, anzulegen.

**SWF-006** CityLife ist eine Radio-Hamburg-spezifische Produktvariante des Werbemittels Veranstaltungstipp mit eigenen Standardzeiten, Beschreibungen und Regeln.

**SWF-007** Event-Tipp wird als zusätzliches Werbemittel übernommen.

**SWF-008** Trailer, Allongen und weitere SWF aus der Trailerkalkulation dürfen konfigurierte Standardlängen und gruppierte Zeitschienen verwenden. Das ist ausdrücklich abweichend von der klassischen Spotplanung (`SPT-016`).

## 10.1 Keine Excel-Begrenzungen übernehmen

- Keine technisch feste Vier-Wochen-Grenze.

- Keine Abhängigkeit von versteckten Hilfsspalten oder hart codierten Zellbereichen.

- Keine redundanten Werbemittelvarianten nur wegen eines Zeitfensters.

- Warnhinweise und Sonderzeiten werden als versionierte Admin-Regeln gepflegt.

# 11. Online Audio und Podcast

## 11.1 TKP-Modell

| **Wert**             | **Definition**                                                          |
|----------------------|-------------------------------------------------------------------------|
| Brutto-TKP           | Listen-TKP; 'brutto' bedeutet Listenpreis, nicht inklusive Umsatzsteuer |
| Mindest-TKP          | Niedrigster Basisverkaufspreis ohne Sonderfreigabe                      |
| Basis-TKP            | Von Vertrieb gewählter TKP; regulär zwischen Mindest- und Brutto-TKP    |
| Targeting-Aufschläge | Feste additive TKP-Aufschläge, beliebig kombinierbar                    |
| Finaler TKP          | Basis-TKP plus Summe aller Targeting-Aufschläge                         |

| **Formel Online Audio** Finaler TKP = Basis-TKP + Summe Targeting-Aufschläge. Invest = Impressions / 1.000 x finaler TKP. |
|---------------------------------------------------------------------------------------------------------------------------|

**OA-001** Die Mindest-TKP-Prüfung erfolgt gegen den Basis-TKP, nicht gegen den finalen TKP.

**OA-002** Ein Basis-TKP unter Mindest-TKP ist speicherbar, blockiert aber die Übergabe an die Disposition, bis Begründung und kaufmännische Sonderfreigabe vorliegen.

**OA-003** Bei Festpreis wird der effektive TKP automatisch berechnet und ebenfalls gegen den Mindest-TKP geprüft. Online-Audio-Festpreis erfordert immer eine Sonderfreigabe.

**OA-004** Der zusätzliche Adserver-TKP von 15 EUR bei Spotify/Deezer/YouTube wird als Verkaufs-TKP/Preisbestandteil geführt.

## 11.2 Plattformen, Mengen und Targeting

**OA-005** Eine Online-Audio-Position darf mehrere Plattformen enthalten.

**OA-006** Die Mengenverteilung je Plattform sowie auf Pre-Stream und In-Stream ist Pflicht. Eine reine Gesamtmenge mit Freitext genügt nicht.

**OA-007** Bei Pre-Stream sind Pre-Stream-AIs Pflicht; bei In-Stream sind In-Stream-AIs Pflicht. Summe der Detailmengen muss der Positionsgesamtmenge entsprechen.

**OA-008** Targetings werden als unbegrenzt wiederholbare Datensätze mit Kategorie (technisch/DMP), Spezifikation, Aufschlag und optionaler UND-/ODER-Verknüpfung gespeichert.

**OA-009** Targetings und deren Aufschläge sind admin-pflegbar, versioniert und im Dispoauftrag sichtbar.

## 11.3 Pflicht-/Dispofelder

- Inventar, Plattform(en), Werbemittel und HR-Kampagne ja/nein

- tatsächliche Länge in Sekunden

- Kampagnenzeitraum oder Zeitraum offen

- Impressions/AIs gesamt und Mengenverteilung

- Basis-TKP, Targeting-Aufschläge und finaler TKP

- URL

- Reporting ja/nein; bei ja ist Reporting-E-Mail Pflicht

- Rechnung per Ende, sobald ein Zeitraum vorhanden ist

- Bemerkung/Verteilungshinweis

- optional Frequency Capping

# 12. Social Media, Influencer, Events und freie Preispositionen

## 12.1 Social Media

**SOC-001** Social-Media-Positionen werden grundsätzlich als Festpreis pro Werbeelement kalkuliert.

**SOC-002** Eine Position darf Paketangebote mit mehreren Elementen enthalten, z. B. zwei Storys, ein Reel und einen Post.

**SOC-003** Bei Influencer-Formaten wird jeder Influencer als eigene Unterposition mit eigener Menge, Elementen und Preis geführt.

**SOC-004** Medienpreis und Boosterbudget werden als getrennte Preisbestandteile gespeichert und ausgewiesen.

**SOC-005** Die gesamte Social-Media-Position ist initial nicht rabattierbar und nicht AE-fähig. Admin kann diese Eigenschaften später je Werbemittel/Preisposition ändern; die Änderung gilt nur für neue Snapshots.

**SOC-006** Boosterbudget bleibt als separate, nicht rabattierbare und nicht AE-fähige Preiszeile erkennbar.

## 12.2 Initiale Preisbeispiele aus der RHH-Trailerkalkulation

| **Element**              | **Medienpreis** | **Booster** | **Gesamt** |
|--------------------------|-----------------|-------------|------------|
| Facebook Post            | 450 EUR         | 50 EUR      | 500 EUR    |
| Instagram/Facebook Story | 900 EUR         | 50 EUR      | 950 EUR    |
| Instagram/Facebook Reel  | 1.300 EUR       | 100 EUR     | 1.400 EUR  |

Weitere Festpreise, z. B. für Podcast, Online Audio und Events, werden vor Produktivsetzung als admin-editierbare Initialpreislisten geliefert.

## 12.3 Events und flexible Leistungen

**EVT-001** Events, Promotion und weitere Online-Leistungen werden in V1 über dynamische Feldsets sowie fixe oder freie Preispositionen abgebildet.

**EVT-002** Feste Berechnungslogiken können später ergänzt werden, ohne historische Vorgänge zu verändern.

**EVT-003** Event-Kostenstellen und inventarspezifische Hinweise werden als dynamische Regeln initialisiert.

# 13. Produktion und Sonstiges

**PRO-001** Produktion/Sonstiges ist eine optionale Zusatzzeile innerhalb einer Werbemittelposition; mehrere Zeilen sind zulässig.

**PRO-002** Jede Zeile besitzt Typ, frei benennbare Bezeichnung, optionalen Mengenwert mit Standard 0, Einzelpreis, Gesamtpreis und Bemerkung.

**PRO-003** Typen umfassen mindestens Spotproduktion, Influencer-Produktion, Social-Media-Produktion, Fremdkosten und Sonstiges.

**PRO-004** Reguläre Produktionspreise stammen aus einer inventar- und typbezogenen Produktionspreisliste und sind für Vertrieb grundsätzlich gesperrt.

**PRO-005** Bei Typ Sonstiges darf Vertrieb einen Preis frei eingeben. Eine Überschreibung eines regulären Produktionspreises erfordert Begründung und kaufmännische Sonderfreigabe.

**PRO-006** Produktion/Sonstiges verwendet immer Buchungskennzeichen Spotproduktion/Sonstige (S) und erscheint im Dispoauftrag als eigene Zeile.

**PRO-007** Produktion/Sonstiges ist initial nicht rabattierbar und nicht AE-fähig. Admin kann die Eigenschaften je Preisposition ändern.

# 14. Rabatt, AE, Festpreis und Payfaktor

## 14.1 Rabatt

**COM-001** Rabatte einer Ebene werden nacheinander angewendet, nicht addiert. Zuerst alle Rabatte des Werbeelements in ihrer Reihenfolge, danach die Auftragsrabatte auf die verbleibende Summe. Nettofaktor = Produkt aller (1 - r).

| **Beispiel** 10 % Mengenrabatt und danach 5 % Sonderrabatt ergeben 14,5 % effektiven Nachlass, nicht 15 %. 10 % plus 10 % Auftrag bleiben 19 %. |
|-----------------------------------------------------------------------------------------------------------------------------------------------|

**COM-002** Die persönliche Rabattgrenze wird je Werbemittelposition gegen den effektiven kumulierten Nachlass aller Positions- und Auftragsrabatte geprüft. Mehrere kleine Rabatte dürfen die Grenze nicht umgehen. AE zählt nicht zur Rabattgrenze.

**COM-003** Rabattgrenzen sind nutzerabhängig, nicht zusätzlich sender-, kategorie- oder werbemittelabhängig.

**COM-004** Admin steuert je Werbemittel und Preisposition, ob diese rabattierbar ist.

## 14.2 AE

**COM-005** Der AE-Standardsatz beträgt 15 %. In der Spotkalkulation wird AE als Checkbox `15 % AE berücksichtigen` angeboten, standardmäßig deaktiviert. Ein frei editierbarer AE-Prozentsatz gehört nicht zu diesem Slice.

**COM-006** Für neue Kalkulationen gilt: aktiviert = 15 %, deaktiviert = 0 %. Bestehende Kalkulationen mit explizitem AE-Wert größer 0 behalten ihre bisherige Wirkung. Die spätere Hierarchie Position > Kalkulation > Agenturstandard bleibt für Folge-Slices vorgesehen.

**COM-007** AE wird nach Abzug der Rabatte auf den AE-fähigen Betrag berechnet.

**COM-008** Admin steuert je Werbemittel und Preisposition, ob diese AE-fähig ist.

## 14.3 Festpreis und Payfaktor

**COM-009** Festpreis ist ein vereinbarter rabattierter N/N-Endpreis. Das System leitet effektiven Rabatt und Payfaktor aus dem zugehörigen Mediabrutto ab.

**COM-010** Payfaktor = N/N-Invest / Mediabrutto x 100. Das System weist Payfaktor ohne Online Audio, Payfaktor Online Audio und einen Gesamt-Payfaktor getrennt aus.

**COM-011** Bei Mediabrutto 0 darf keine Division erfolgen; Payfaktor wird als nicht berechenbar gekennzeichnet und die Übergabe kaufmännisch geprüft.

# 15. Freigaben und Sperrlogik

## 15.1 Reihenfolge

1.  Dispoauftrag ist im Entwurf vollständig und besitzt Kundenbestätigung oder Ausnahme.

2.  Falls ausgelöst, wird zuerst die kaufmännische Sonderfreigabe angefordert und erteilt.

3.  Danach erfolgt die allgemeine Vier-Augen-Vertriebsfreigabe durch eine andere Person.

4.  Erst dann wechselt der Auftrag zu 'Liegt bei Disposition'.

**APR-001** Ein Dispoauftrag kann nicht an Disposition übergeben werden, solange eine erforderliche Freigabe fehlt oder abgelehnt wurde.

**APR-002** Ablehnung erfordert eine Begründung. Der Ersteller kann danach bearbeiten und erneut einreichen.

**APR-003** Während einer laufenden Freigabe ist Bearbeitung gesperrt. Der Ersteller muss den Auftrag zuerst zurückziehen.

## 15.2 Ungültigwerden erteilter Freigaben

Folgende Änderungen setzen betroffene Freigaben zurück und protokollieren den Grund: Preise, Rabatt, AE, Kunde, Agentur, Rechnungsempfänger, Positionen/Komponenten, Mengen, Zeitraum, Pflichtfelder und freigaberelevante dynamische Felder.

Reine Kommentare sowie zusätzliche, nicht ersetzende Materialien/Uploads setzen Freigaben nicht zurück. Das Ersetzen oder Archivieren der Kundenbestätigung ist freigaberelevant.

**APR-004** Nach einer freigabeinvalidierenden Änderung muss der Auftrag den vollständigen erforderlichen Freigabeprozess erneut durchlaufen.

# 16. Dispoauftrag und Positionsübernahme

## 16.1 Erstellung

**DSP-001** Die Aktion 'Dispoauftrag erstellen' zeigt alle Kalkulationspositionen zur Auswahl. Bereits übernommene Positionen sind gekennzeichnet, dürfen aber erneut gewählt werden.

**DSP-002** Jeder neue Dispoauftrag übernimmt die gewählten Daten frisch aus dem aktuellen Kalkulationsstand.

**DSP-003** Ein erstellter Dispoauftrag ist ein unabhängiger Snapshot. Spätere Änderungen an der Kalkulation synchronisieren ihn nicht.

**DSP-004** Die Auftragsnummer wird automatisch gebildet. Bei einer neuen Dispoauftragsfamilie entsprechen Jahr und Stammsequenz der zugehörigen Kalkulationsnummer, z. B. `K-2026-00005` → `DA-2026-00005-01`. Weitere Teilaufträge und Korrekturen erhöhen ausschließlich den zweistelligen Suffix. Bereits vergebene Dispoauftragsnummern bleiben unverändert; bestehende Familien behalten ihren bisherigen Stamm.

## 16.2 Aufbau

| **Bereich**    | **Inhalte**                                                                                                                                         |
|----------------|-----------------------------------------------------------------------------------------------------------------------------------------------------|
| Kopfdaten      | Kunde, Rechnungsempfänger, Meridian-Nr., Agentur, Kontakte, Kampagne, Mediaberater, Prüf-/Payfaktorfelder, Rechnungs- und Dispohinweise             |
| Positionen     | Inventar (Kombi als eigenständige Position, ohne Auflösung in Sender), Werbemittel, Kategorie, Buchungskennzeichen, Einplanung durch, Zeitraum, tatsächliche Spotlänge, Invest, dynamische Felder, Hinweise |
| Uploads        | Zentrale Uploadliste einschließlich Dateien aus dynamischen Datei-Feldern                                                                           |
| Abrechnung     | Mediabrutto, Rabatte, AE, N/N, Payfaktoren, Rechnung per Ende                                                                                       |
| Zusammenarbeit | Kommentare, Rückfragen/Antworten, Status-, Freigabe- und Änderungshistorie                                                                          |

## 16.3 Kopfdaten

- Kunde und Rechnungsempfänger-Kennzeichnung (nur Kunde oder Agentur)

- Meridian-Nummer des gewählten Rechnungsempfängers

- Agentur, Ansprechpartner und E-Mail Ansprechpartner

- AE-fähig/AE-Wert sowie Produkt/Titel/Kampagne

- Erstellt am und Mediaberater

- Kalkulation geprüft

- Payfaktor ohne OA, Payfaktor OA und Gesamt-Payfaktor

- Freigabe Payfaktor durch

- Besonderheiten zur Rechnungsstellung

- Wichtige Informationen an die Disposition

- Priorität normal/dringend; bei dringend Begründung; optional gewünschtes Bearbeitungsdatum

**DSP-005** Weitere Kopffelder können über das dynamische Feldsystem durch Admin ergänzt werden.

**DSP-006** Es gibt keine Felder für gewünschte Auftragsbestätigung oder Versandart, da V1 nur die interne Dispo-Zusammenfassung erzeugt.

**DSP-007** Ein Dispoauftrag darf ausschließlich aus einer regulären Kundenkalkulation erzeugt werden. Die direkte Erstellung aus einem Standardangebot ist unzulässig (`STD-007`).

# 17. Statusmodell und operative Bearbeitung

| **Status**                   | **Setzt**            | **Regel**                                                                                   |
|------------------------------|----------------------|---------------------------------------------------------------------------------------------|
| Entwurf                      | Ersteller/Vertrieb   | Bearbeitbar; noch nicht eingereicht.                                                        |
| Wartet auf Vertriebsfreigabe | System/Vertrieb      | Gesperrt; wartet auf zweite Person, ggf. nach Sonderfreigabe.                               |
| Freigabe abgelehnt           | Freigeber            | Begründung Pflicht; Ersteller darf bearbeiten und neu einreichen.                           |
| Liegt bei Disposition        | System               | Nach vollständiger Freigabe; Arbeitsvorrat der Disposition.                                 |
| In Bearbeitung               | Disposition          | Disposition bearbeitet aktiv.                                                               |
| Rückfrage Vertrieb           | Disposition          | Pflichtnotiz; adressiert Mediaberater, Ersteller, Freigeber und optional weiteren Vertrieb. |
| Material fehlt               | Disposition          | Manuell gesetzt; kein Automatismus.                                                         |
| Material erhalten            | Disposition          | Manuell gesetzt; kein Automatismus.                                                         |
| Disponiert                   | Disposition          | Manuell in Meridian eingebucht; fachliche Felder gesperrt.                                  |
| Abgeschlossen                | Disposition/Admin/GF | Nur bei erfüllten Abschlussbedingungen; Admin-Override mit Begründung.                      |
| Storniert                    | Berechtigte Rollen   | Jederzeit, auch nach Disponiert/Abgeschlossen; Begründung Pflicht.                          |

**STA-001** Ein Dispoauftrag besitzt nur einen Gesamtstatus; V1 führt keine eigenen operativen Positionsstatus.

**STA-002** Vertrieb beantwortet eine Rückfrage mit Pflichtnotiz und setzt den Auftrag aktiv zurück auf 'Liegt bei Disposition'.

**STA-003** Ab 'Disponiert' sind fachliche und kaufmännische Daten gesperrt. Disposition, Admin oder Geschäftsführung können mit Pflichtbegründung wieder öffnen; kaufmännische Änderungen lösen Freigaben erneut aus.

**STA-004** Nach 'Abgeschlossen' dürfen nur Admin oder Geschäftsführung mit Begründung wieder öffnen.

**STA-005** Storno nach 'Abgeschlossen' ist zulässig und wird vollständig protokolliert.

## 17.1 Abschlussbedingungen

- alle aktuell sichtbaren Pflichtfelder erfüllt

- keine offene Rückfrage

- Rechnung-per-Ende ergänzt, wenn ein konkreter Zeitraum vorhanden ist

- Kundenbestätigung oder freigegebene Ausnahme vorhanden

- erforderliche Freigaben gültig

**STA-006** Admin darf einen Abschluss trotz verletzter Regel erzwingen; Pflichtbegründung, verletzte Prüfungen und Benutzer werden im Audit gespeichert.

# 18. Pflichtfelder, Rechnung, Uploads und Kommentare

## 18.1 Kunden, Agenturen und Kontakte

**CRM-001** Jede Kalkulation gehört genau einem intern gepflegten Kunden; eine Agentur ist optional.

**CRM-002** Rechnungsempfänger kann ausschließlich Kunde oder Agentur sein.

**CRM-003** Kunde und Agentur können jeweils eine Meridian-Nummer und mehrere Ansprechpartner besitzen. Der gewählte Rechnungsempfänger bestimmt die anzuzeigende Meridian-Nummer.

**CRM-004** Ansprechpartner dürfen im Kalkulationsentwurf fehlen, müssen jedoch nach den konfigurierten Dispo-Pflichtregeln vor Übergabe/Abschluss ergänzt sein.

## 18.2 Rechnung per Ende

**INV-001** Rechnung per Ende wird je Werbemittelposition als Mehrfachauswahl aus allen Monaten geführt.

**INV-002** V1 teilt keine Beträge auf Monate auf.

**INV-003** Bei Zeitraum offen darf die Auswahl leer sein. Sobald ein konkreter Zeitraum vorhanden ist, muss sie spätestens vor Abschluss befüllt sein.

## 18.3 Kundenbestätigung

**UPL-001** Vor Einreichen zur Vertriebsfreigabe muss entweder ein Upload der Kategorie Kundenbestätigung oder die Checkbox 'Bestätigung liegt vor' mit Ausnahmegrund vorhanden sein.

**UPL-002** Der zweite Freigeber muss die Nutzung des Ausnahmegrunds ausdrücklich mitfreigeben.

**UPL-003** Disposition kann über 'Rückfrage Vertrieb' mitteilen, dass die Bestätigung nicht ausreicht.

## 18.4 Uploads

| **Kategorie**     | **V1-Regel**                           |
|-------------------|----------------------------------------|
| Kundenbestätigung | grundsätzlich Pflicht oder Ausnahme    |
| Audio-Motiv       | optional; nur MP3/WAV; mehrere Dateien |
| Briefing          | optional bzw. dynamisch verpflichtend  |
| Skript/Text       | optional bzw. dynamisch verpflichtend  |
| Layout/Grafik     | optional bzw. dynamisch verpflichtend  |
| Event-Unterlagen  | optional bzw. dynamisch verpflichtend  |
| Sonstiges         | optional                               |

**UPL-004** Uploads hängen zentral am Dispoauftrag. Dateien aus dynamischen Datei-Feldern erscheinen zusätzlich in derselben zentralen Uploadliste.

**UPL-005** Uploads werden nicht gelöscht. Admin kann sie archivieren; Archivierung bleibt historisch nachvollziehbar.

**UPL-006** Standard-Maximalgröße ist 50 MB pro Datei; Dateitypen sind je Feld/Kategorie validierbar.

**UPL-007** Audio-Motive benötigen in V1 keinen Motivnamen, Materialstatus oder Positionsbezug. Download und Abspielen im Browser sind erforderlich; Länge wird manuell geprüft.

## 18.5 Kommentare

**CMT-001** Alle Rollen dürfen allgemeine Kommentare schreiben; alle Rollen dürfen die vollständige Kommentarhistorie sehen.

**CMT-002** Kommentare dürfen nicht nachträglich bearbeitet oder gelöscht werden. Korrekturen erfolgen als neuer Kommentar.

**CMT-003** Rückfragen und Antworten werden als strukturierte Ereignisse in derselben Historie angezeigt.

# 19. Dynamisches Feldsystem und Snapshots

## 19.1 Hybridmodell

Kernobjekte und fachkritische Berechnungen bleiben fest im System. Ergänzende Felder, Feldsets, Sichtbarkeit, Pflichtregeln und Validierungen werden konfiguriert. Systemfelder können pro Werbemittel als sichtbar, Pflicht und relevant markiert werden, ohne ihre technische Bedeutung zu verlieren.

**DYN-001** Dynamische Felddefinitionen besitzen stabile ID, internen Schlüssel, sichtbaren Namen, Typ, Hilfetext, Gruppe, Reihenfolge, Status und Auswertungskennzeichen.

**DYN-002** Ein Werbemittel erbt Felder seiner Oberkategorie und kann eigene Felder sowie mehrere wiederverwendbare Feldsets ergänzen.

**DYN-003** Feldsets besitzen die Status Entwurf, Aktiv und Archiviert.

## 19.2 Feldtypen V1

| **Gruppe**    | **Feldtypen**                                             |
|---------------|-----------------------------------------------------------|
| Text          | kurzer Text, langer Text                                  |
| Numerisch     | Zahl, Geldbetrag, Prozent                                 |
| Datum/Zeit    | Datum, Zeitraum von/bis, Uhrzeit, Monat-Auswahl           |
| Logik/Auswahl | Ja/Nein, Einfachauswahl, Mehrfachauswahl                  |
| Kontakt       | URL, E-Mail, Telefon                                      |
| Datei         | Datei-Upload                                              |
| Referenz      | Inventar-, Werbemittel-, Ansprechpartner-, Kunden-Auswahl |

## 19.3 Regeln und Validierungen

- Pflicht, wenn Feld X Wert Y hat

- Pflicht, wenn Feld X nicht leer ist

- Pflicht, wenn Feld X größer/kleiner als Y ist

- mehrere Bedingungen mit UND/ODER

- Sichtbarkeit nach Wert oder Befüllungsstatus eines anderen Feldes

- Textlänge, Zahl min/max, URL-/E-Mail-/Telefonformat, Dateitypen und Dateigröße

**DYN-004** Regeln werden bei jeder fachlichen Speicherung und vor jedem Statusübergang serverseitig ausgewertet.

**DYN-005** Ausgeblendete Felder dürfen nicht versehentlich neue Pflichtfehler erzeugen, sofern die konfigurierte Regel nicht ausdrücklich unabhängig von Sichtbarkeit gilt.

**DYN-006** Beispiele für Initialregeln: Reporting ja -\> Reporting-E-Mail Pflicht; Targeting gewählt -\> Spezifikation Pflicht; Zeitraum offen nein -\> Zeitraum Pflicht; Kundenbestätigung ohne Upload -\> Ausnahmegrund Pflicht.

## 19.4 Auswahloptionen

**DYN-007** Auswahloptionen besitzen internen Wert, sichtbaren Namen, Sortierung und Aktivstatus.

**DYN-008** Deaktivierte Optionen bleiben in historischen Vorgängen sichtbar, sind aber nicht mehr neu auswählbar.

## 19.5 Versionierung und Snapshots in V1

**VER-001** Snapshots und Feldset-Versionierung sind zwingender Bestandteil von V1.

**VER-002** Beim Erstellen einer Kalkulation wird die zu diesem Zeitpunkt aktive Konfigurationsbasis aus Felddefinitionen, Feldsets, Regeln und Systemfeld-Eigenschaften als Snapshot festgelegt. Spätere Adminänderungen werden nicht automatisch in diese Kalkulation übernommen.

**VER-003** Beim Anlegen einer Kalkulationsposition werden aus der Kalkulations-Snapshotbasis die relevanten Werbemittel-, Kombinations- und Felddefinitionen sowie die ausgewählte Preislistenversion unveränderbar zugeordnet.

**VER-004** Beim Erstellen eines Dispoauftrags wird ein eigener Snapshot des gewählten Kalkulationsstands gespeichert. Beim Übernehmen eines Standardangebots wird ein eigener Snapshot der veröffentlichten Vorlagenversion in der neuen Kundenkalkulation gespeichert (`STD-005`).

**VER-005** Änderungen aktiver Feldsets erzeugen eine neue Version. Neue Regeln gelten ausschließlich für danach erzeugte Kalkulationen/Snapshots.

**VER-006** Alte Versionen sind lesbar, nicht direkt reaktivierbar und können als Vorlage in eine neue Version kopiert werden.

**VER-007** Alte Feldnamen, deaktivierte Felder und deaktivierte Optionen bleiben in historischen Kalkulationen und Dispoaufträgen unverändert sichtbar.

# 20. Administration und Initialdaten

## 20.1 Adminmodule V1

| **Modul**              | **Funktionen**                                                                    |
|------------------------|-----------------------------------------------------------------------------------|
| Inventare/Kombis       | Einzelanlage/-bearbeitung, Typ (`sender`/`kombi`), Aktivstatus, Sortierung        |
| Werbemittel/Kategorien | Stammdaten, Zuordnung, Kalkulationsarten, Default-Eigenschaften                   |
| Kombinationstabelle    | Vollständige Einzelbearbeitung, Buchungskennzeichen, Einplanung, Hinweise, Filter |
| Preislisten            | Excel-Import, Vorschau, Fehlerprüfung, Aktivierung, Einzeländerung, Versionen     |
| Produktionspreise      | Inventar-/Typpreise, Gültigkeit, Rabatt-/AE-Eigenschaften                         |
| Online Audio           | TKP-Listen, Mindest-TKP, Targetings, Aufschläge, Plattformen                      |
| Dynamische Felder      | Felder, Gruppen, Feldsets, Regeln, Validierung, Vorschau, Versionen               |
| Benutzer/Rollen        | Konten, Rollen (inkl. Produktmanagement), Rabattgrenzen, Sonderfreigaberechte, Aktivstatus |
| Stammdaten             | Kunden, Agenturen, Kontakte, Meridian-Nummern                                     |
| Standardangebote       | Vorlagen ohne Kundenbindung; Versionen, Veröffentlichung, Archiv; Übernahme nur in Kundenkalkulation |

**ADM-001** Jede Adminänderung ist mit altem Wert, neuem Wert, Benutzer, Zeit und betroffener Version zu protokollieren.

**ADM-002** Admin erhält vor Aktivierung neuer Feld-/Regelversionen eine Vorschau anhand eines Testformulars.

## 20.2 Initiale Sonderregeln

| **Bereich**      | **Initialregel/Hinweis**                                              |
|------------------|-----------------------------------------------------------------------|
| Events           | Event-Kostenstelle bei Event-Kombis                                   |
| Produktion       | Nutzungsrechte bei über Krane & Raabe produzierten Spots              |
| HR-Kampagnen     | Verpackungs-/Dispohinweise                                            |
| Instagram        | Boosterbudget getrennt ausweisen                                      |
| Sponsoring/SWF   | Hinweise für Wettersponsoring, CityLife und Chartshow                 |
| St. Pauli        | Backstage-Abbinder-Hinweis                                            |
| RMS              | Keine Verprovisionierung von Produktion, Influencern und Social Media |
| Spotify/Adserver | Besondere TKP-/Einplanungshinweise                                    |

**ADM-003** Diese Regeln werden als initiale Admin-Konfiguration angelegt und nicht in Programmcode fest verdrahtet.

# 21. Suche, Listen, Reports und Exporte

**REP-001** Listen für Kalkulationen, Standardangebote und Dispoaufträge unterstützen Volltextsuche, kombinierbare Filter, Sortierung, Pagination und benutzerbezogene Spaltenauswahl.

**REP-002** V1 wertet Umsatz mindestens nach Mediaberater, Kunde, Sender/Inventar, Werbemittel, Oberkategorie, Monat, Status, Rabatt und AE aus.

**REP-003** Auswertungen funktionieren je Inventar und inventarübergreifend.

**REP-004** Dynamische Felder sind grundsätzlich reportfähig: Zahlen/Geld summierbar, Auswahl gruppierbar, Text durchsuchbar, Datum als Zeitraumfilter. Admin kann Reportfähigkeit je Feld deaktivieren.

**REP-005** PDF-Exporte: interne Kalkulationsübersicht, interner Dispoauftrag, Auswertungen/Reports.

**REP-006** Excel- und CSV-Exporte: Listen und Auswertungen. Exportiert werden nur die gemäß Rolle sichtbaren Daten.

**REP-007** Der Dispo-PDF-Export zeigt das gebuchte Inventar, konkrete Spotverteilung, Targetings/Plattformverteilung, Zusatzpreiszeilen, Hinweise, Freigaben und investitionsrelevante Summen. Eine Kombi erscheint als eigenständige Inventarposition und wird nicht in einzelne Sender aufgefächert.

# 22. Historie, Benachrichtigungen und Nachvollziehbarkeit

## 22.1 Audit

**AUD-001** Alle Änderungen an Kalkulationen, Standardangeboten und Dispoaufträgen werden auf Feld- und Objektebene mit Benutzer, Zeitpunkt, altem/neuem Wert und Kontext protokolliert.

**AUD-002** Zusätzlich werden Statuswechsel, Freigaben, Ablehnungen, Rückfragen/Antworten, Kommentare, Uploads, Downloads, Exporte, Archivierungen und erzwungene Aktionen protokolliert.

**AUD-003** Reine Seitenaufrufe/Ansichten werden nicht protokolliert.

**AUD-004** Historien sind unveränderbar und chronologisch lesbar; Systemereignisse und Benutzeraktionen sind unterscheidbar.

## 22.2 Benachrichtigungen

E-Mail und In-App-Benachrichtigungen werden ausgelöst bei: Freigabe angefordert, freigegeben, abgelehnt, Rückfrage, Antwort, Material fehlt, Material erhalten, disponiert, abgeschlossen und storniert.

**NOT-001** Benachrichtigungen enthalten Vorgangsnummer, Kunde/Kampagne, Ereignis, handelnde Person und direkten internen Link, jedoch keine unnötigen sensiblen Anlagen.

**NOT-002** Fehler beim E-Mail-Versand dürfen den fachlichen Statuswechsel nicht zurückrollen; sie werden für Admin sichtbar protokolliert und erneut versucht.

# 23. Fachliches Datenmodell und technische Leitplanken

## 23.1 Kernobjekte

| **Objekt**                                    | **Kardinalität** | **Zweck**                                                       |
|-----------------------------------------------|------------------|-----------------------------------------------------------------|
| Organisation                                  | 1                | Mandantenrahmen; V1 genau eine Organisation                     |
| Inventar                                      | n                | Sender (`type=sender`) und Kombi (`type=kombi`) als eigenständige Inventare |
| Oberkategorie / Werbemittel                   | n                | Produktkatalog und Default-Regeln                               |
| Inventar-Werbemittel-Regel                    | n:m              | Whitelist, Buchungskennzeichen, Einplanung, Hinweis, Aufschlag  |
| Preisliste / Preiszeile / Version             | n                | Jahr, Gültigkeit, Tagesgruppe, Stunde, Sekunden-/Fix-/TKP-Preis |
| Kunde / Agentur / Kontakt                     | n                | Stammdaten, Meridian-Nr., AE-Standard                           |
| Benutzer / Rolle / Rabattgrenze               | n                | Zugriff und Freigaberechte, inkl. Produktmanagement             |
| Standardangebot / Version                     | 1:n              | kundenlose Kalkulationsvorlage, Status, Snapshot                |
| Kalkulation / Position                        | 1:n              | Kopfdaten, Summen, Kalkulationsarten; optional Herkunft aus Standardangebot |
| Komponente / Plattformanteil / Social-Element | n                | fachspezifische Unterobjekte                                    |
| Produktions-/Zusatzzeile                      | n                | Preisbestandteile und Buchungskennzeichen S                     |
| Dispoauftrag / Dispoposition-Snapshot         | 1:n              | unabhängige Übergabeversion                                     |
| Freigabe / Statushistorie / Kommentar         | n                | Workflow und Kommunikation                                      |
| Datei / Dateiverknüpfung                      | n                | zentrale Uploadliste und dynamische Datei-Felder                |
| Felddefinition / Feldset-Version / Regel      | n                | dynamische Konfiguration                                        |
| Snapshot / dynamischer Wert                   | n                | historisch stabile Darstellung                                  |
| Auditereignis / Benachrichtigung              | n                | Nachvollziehbarkeit und Zustellung                              |

## 23.2 Technische Leitplanken

- Relationale Datenbank für transaktionale Kernobjekte, Versionen, Freigaben und Reporting-Schlüssel.

- Dateien in revisionssicher referenziertem privatem Dateispeicher (Laravel Filesystem, austauschbarer Treiber); Metadaten und Archivstatus in der Datenbank; keine direkt öffentlichen Datei-URLs.

- Serverseitige Rechen-/Regelkomponente als einzige fachliche Wahrheit; Frontend darf Vorschauen berechnen, aber nicht autoritativ speichern.

- Dezimaltypen für Geld, Prozente und TKP; keine binären Floating-Point-Typen für kaufmännische Ergebnisse.

- Optimistisches Sperrverfahren mit Versionsnummer: bei konkurrierender Bearbeitung kein stilles Überschreiben.

- Asynchrone Jobs für Excel-Importe, größere Exporte, E-Mails und optionale Virenprüfung.

- Stabile IDs und unveränderbare Snapshotdaten; Anzeigenamen dürfen versioniert geändert werden.

- API und Datenmodell müssen eine spätere Meridian-/Salesforce-Anbindung ermöglichen, ohne V1-Schnittstellen vorzutäuschen.

## 23.3 Nummerierung

**TEC-001** Kalkulation, Standardangebot und Dispoauftrag besitzen interne unveränderbare IDs sowie lesbare fortlaufende Nummern. Nummern werden transaktionssicher und ohne Dubletten vergeben.

**TEC-002** Eine Dispoauftragsnummer besteht mindestens aus Präfix, Jahr, Stammsequenz und laufender Nummer innerhalb der Kalkulation. Bei neuen Familien entsprechen Jahr und Stammsequenz der Kalkulationsnummer, z. B. `K-2026-00005` → `DA-2026-00005-01`. Weitere Teilaufträge und Korrekturen erhöhen ausschließlich den Suffix. Legacy-Familien behalten ihren bisherigen Stamm.

# 24. Nichtfunktionale Anforderungen

| **Bereich**         | **MUSS-Anforderung**                                                                                                                                    |
|---------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------|
| Sicherheit          | Passwörter stark gehasht; Passwort-Reset; rollenbasierter Zugriff; sichere Sessions; Schutz vor gängigen Webangriffen.                                  |
| Dateien             | MIME-/Dateiendungsprüfung, konfigurierbare Limits, Malwareprüfung soweit Infrastruktur verfügbar, keine direkte öffentliche URL.                        |
| Datenschutz         | Nur erforderliche personenbezogene Daten; rollenbasierte Sichtbarkeit; dokumentiertes Lösch-/Aufbewahrungskonzept unter Erhalt kaufmännischer Historie. |
| Performance         | Typische Listen-/Detailaufrufe und Speichervorgänge bei normaler Last im Regelfall innerhalb von 2 Sekunden; lange Jobs asynchron.                      |
| Verfügbarkeit       | Geeignete produktive Überwachung, Fehlerprotokollierung und Alarmierung; geplante Wartung kommunizierbar.                                               |
| Backup              | Automatisierte Backups von Datenbank und Dateimetadaten; Wiederherstellung regelmäßig testen.                                                           |
| Nachvollziehbarkeit | Jede Berechnung zeigt verwendete Preisliste, Version, Eingaben, Aufschläge, Rabatt-/AE-Schritte und Rundung.                                            |
| Barrierearmut       | Tastaturbedienbare Formulare, sichtbare Fokusführung, aussagekräftige Labels/Fehlertexte, ausreichende Kontraste.                                       |
| Browser             | Aktuelle Unternehmensbrowser; Mindestmatrix vor Projektstart verbindlich festlegen.                                                                     |
| Betrieb             | Getrennte Entwicklungs-, Test- und Produktivumgebung; Konfigurations-/Initialdatenübernahme kontrolliert und protokolliert.                             |

# 25. Abnahme- und Testkatalog

Die folgenden Szenarien bilden die Mindestabnahme. Zusätzlich sind Unit-, Integrations-, Berechtigungs- und Regressionstests für alle Formeln und Statusübergänge erforderlich.

| **ID** | **Szenario**          | **Testdaten/Aktion**                            | **Erwartung**                                                               |
|--------|-----------------------|-------------------------------------------------|-----------------------------------------------------------------------------|
| AT-01  | Durchschnitt Spot     | Mo-Fr, zwei Zeitfenster, 10 Spots, 20 s         | Gleichgewichteter Stundenmittelwert; Index 105; korrekte Aufschläge/Rundung |
| AT-02  | Planer Spot           | Mehrere Daten inkl. Sa/So                       | Wochentag automatisch; jede Stunde mit korrektem Basispreis                 |
| AT-03  | Lange Single-Spots    | 46 s und 100 s                                  | Nur Hinweis bei 46 s; berechenbar; Index 95                                 |
| AT-04  | Komponenten           | Hauptspot + Allonge                             | Beide sichtbar; je Konfiguration einzeln oder über Gesamtlänge              |
| AT-05  | SWF Trailer           | 20 s, +30 %, Zeitfenster                        | Sekundenpreis x 20 x 1,30; kein Spotlängenindex                             |
| AT-06  | Rabattfolge           | 10 % Position + 10 % Auftrag                    | Effektiver Rabatt 19 %; Grenzprüfung gegen 19 %                             |
| AT-07  | AE                    | 15 % mit rabattierbaren/nicht AE-fähigen Zeilen | AE nur nach Rabatt und nur auf AE-fähige Basis                              |
| AT-08  | Online Audio          | 2 Plattformen, Pre-/In-Stream, Targeting        | Mengen stimmen; finaler TKP additiv; Invest korrekt                         |
| AT-09  | TKP unter Minimum     | Basis-TKP unterschritten                        | Speichern möglich; Übergabe blockiert; Begründung/Sonderfreigabe nötig      |
| AT-10  | Social Paket          | Story + Reel + Booster                          | Unterpositionen; Medien-/Boostertrennung; initial kein Rabatt/AE            |
| AT-11  | Produktion            | Menge Standard 0; regulär und Sonstiges         | Preislistenbezug; Überschreibung nur mit Freigabe                           |
| AT-12  | Vier Augen            | Ersteller versucht Freigabe                     | Aktion blockiert; anderer Berechtigter möglich                              |
| AT-13  | Freigabeinvalidierung | Preis nach Freigabe geändert                    | Freigaben zurückgesetzt; Audit mit Ursache                                  |
| AT-14  | Snapshot              | Admin ändert Feldname/Preis                     | Alter Vorgang unverändert; neuer Vorgang nutzt neue Version                 |
| AT-15  | Mehrere Dispoaufträge | Teilmenge zweimal auswählen                     | Kennzeichnung vorhanden; erneute Auswahl erlaubt; unabhängige Snapshots     |
| AT-16  | Kundenbestätigung     | Kein Upload, Ausnahme                           | Grund Pflicht; explizite Mitfreigabe                                        |
| AT-17  | Status Rückfrage      | Dispo stellt Frage, Vertrieb antwortet          | Pflichtnotizen; Historie; Rückkehr zu Liegt bei Disposition                 |
| AT-18  | Abschluss             | Rechnungsmonat fehlt                            | Abschluss blockiert; Admin-Override nur mit Begründung                      |
| AT-19  | Storno                | Nach Abgeschlossen                              | Mit Begründung möglich; Historie vollständig                                |
| AT-20  | Audit                 | Änderung, Download, Export, Kommentar           | Alle Aktionen protokolliert; reine Ansicht nicht                            |
| AT-21  | Preisimport           | Fehlerhafte Excel-Datei                         | Vorschau/Fehlerbericht; keine Teilaktivierung                               |
| AT-22  | Berechtigung          | Dispo ändert Rabatt                             | Blockiert; Rückfrage an Vertrieb erforderlich                               |
| AT-23  | Mehrsender-Kalkulation | 10 Spot Classic Radio Hamburg, 5 ROCK ANTENNE Hamburg, unterschiedliche Längen/Stunden/Rabatte | Eine Kalkulation; Live-Gesamtsumme; jede Position separat editierbar (`CAL-001`, `CAL-005`) |
| AT-24  | Spotlänge Classic     | Standardlänge vorbelegt, dann auf abweichende Sekunden ändern | Feld sichtbar und frei editierbar; Preis und Dispo-Snapshot nutzen die tatsächliche Länge (`SPT-015`) |
| AT-25  | Budget Mehrsender     | Zielbudget N/N, zwei Sender, gleich verteilen | Deterministischer Vorschlag ohne bestehendes Senderverhältnis; editierbar (`BUD-003`–`BUD-006`) |
| AT-26  | Budgetrest            | Zielbudget, das sich nicht ganzzahlig aufteilen lässt | Ganzzahlige Mengen; Rest oder Überschreitung transparent ausgewiesen (`BUD-007`) |
| AT-27  | Budget-Übernahme      | Vorschlag anzeigen, nicht übernehmen, dann explizit übernehmen | Ohne Übernahme unveränderte Positionen; nach Übernahme Mengen übernommen und weiter editierbar (`BUD-008`) |
| AT-28  | Standardangebot übernehmen | Veröffentlichung, Vertrieb übernimmt, ändert Mengen | Eigenständige Kundenkalkulation; Standardangebot unverändert (`STD-004`, `STD-005`) |
| AT-29  | Dispo aus Vorlage     | Dispoauftrag direkt am Standardangebot          | Aktion unzulässig; Dispo nur aus Kundenkalkulation (`STD-007`, `DSP-007`) |
| AT-30  | Rolle Produktmanagement | Nutzer nur Produktmanagement                    | Standardangebote erlaubt; Kundenkalkulationen und Dispoaufträge ohne Extra-Recht verweigert (`AUTH-006`, `AUTH-007`) |
| AT-31  | Standardangebot Version | Entwurf veröffentlichen, archivieren, Historie  | Statuswechsel, Autor, Veröffentlichungszeitpunkt und Audit vollständig (`STD-002`, `STD-008`) |

# 26. Initialkataloge

## 26.1 Werbemittel

| **Nr.** | **Werbemittel**                                 | **Nr.** | **Werbemittel**                      |
|---------|-------------------------------------------------|---------|--------------------------------------|
| 1       | Werbespot                                       | 2       | Werbespot erstplatziert              |
| 3       | Werbespot letztplatziert                        | 4       | Single-Spot                          |
| 5       | Showsponsoring-Single-Spot                      | 6       | Tandem / Reminder                    |
| 7       | Tridem                                          | 8       | Jobspot                              |
| 9       | Gegengeschäft                                   | 10      | Promo/Moderation                     |
| 11      | Event-Tipp                                      | 12      | Veranstaltungstipp                   |
| 13      | Preseller                                       | 14      | Trailer/Vorpr. Element Station Voice |
| 15      | Abbinder                                        | 16      | Allonge                              |
| 17      | Opener                                          | 18      | Bumper                               |
| 19      | Stinger                                         | 20      | Closer                               |
| 21      | Gewinnspiel/Pay-Off                             | 22      | Sondersendung (4x90 Sek.)            |
| 23      | Influencer-Spot                                 | 24      | Influencer-Spot als Single-Spot      |
| 25      | Infomercial / Profi-Tipp                        | 26      | Visual-Spot als Single-Spot          |
| 27      | Visual-Spot mit .de-Nennung                     | 28      | Presenting-Spot                      |
| 29      | Online Anzeigencontainer                        | 30      | Online Facebook                      |
| 31      | Online GWS                                      | 32      | Online Instagram                     |
| 33      | Online Instagram (Influencer)                   | 34      | Online Sondersendung                 |
| 35      | Online TikTok                                   | 36      | Off-Air                              |
| 37      | Pre-Stream                                      | 38      | In-Stream                            |
| 39      | Pre-Stream Influencer                           | 40      | In-Stream Influencer                 |
| 41      | Mid-Roll Spotify / Deezer / YouTube Musikumfeld | 42      | Native-Ad                            |

Die kombinierten Werbemittel 'Pre-/In-Stream' und 'Pre-/In-Stream Influencer' entfallen. Die Einzelvarianten bleiben bestehen. Oberkategorie und Kombinationsfreigabe werden aus der initialen Matrix übernommen und administrativ gepflegt.

## 26.2 Standard-Auswahlwerte

| **Katalog**       | **Werte**                                                                                                                         |
|-------------------|-----------------------------------------------------------------------------------------------------------------------------------|
| Oberkategorien    | Spots; SWF/Sonderwerbeformen; Online Audio; Social Media/Online; Events/Promotion; Gegengeschäft                                  |
| Einplanung durch  | Disposition; OAP; PDM-Digital / Niklas Farin; Redaktion; Moderator; Events; darf nicht geplant werden; kombinierte Sonderhinweise |
| Upload-Kategorien | Kundenbestätigung; Audio-Motiv; Briefing; Skript/Text; Layout/Grafik; Event-Unterlagen; Sonstiges                                 |
| Priorität         | normal; dringend                                                                                                                  |
| Feldset-Status          | Entwurf; Aktiv; Archiviert                                                                                                  |
| Standardangebot-Status  | Entwurf; veröffentlicht; archiviert                                                                                         |

# 27. Liefergegenstände vor Produktivsetzung

Die folgenden Punkte sind keine offenen Grundsatzentscheidungen. Sie sind konkrete Inhalte, die im Projekt als Initialdaten bereitgestellt, geprüft und abgenommen werden müssen:

- vollständige initiale Inventar-Werbemittel-Kombinationstabelle einschließlich Buchungskennzeichen, Einplanung durch, Hinweisen und Aktivstatus

- Jahrespreislisten je Inventar als vereinbarte Excel-Importstruktur

- Festpreise für Online Audio, Podcast, Events sowie weitere digitale Produkte

- Produktionspreislisten je Inventar und Produktionstyp

- finale Targeting-Kataloge einschließlich technischer/DMP-Kategorie und TKP-Aufschlägen

- initiale Kunden-, Agentur- und Kontaktstammdaten, soweit zum Start benötigt

- Nutzerliste mit Rollen (einschließlich Produktmanagement), persönlichen Rabattgrenzen und Sonderfreigaberechten

- Text und Ausprägung der initialen Sonderhinweise (Krane & Raabe, HR, Wetter, Chartshow, St. Pauli, RMS, Spotify/Adserver)

- Freigabe der PDF-Layouts für interne Kalkulationsübersicht, Dispoauftrag und Reports

- Betriebsparameter wie erlaubte Browser, E-Mail-Absender, Backup-/Aufbewahrungsfristen und produktive Domain

| **Abnahmereife** Sind diese Initialdaten geliefert, kann V1 ohne weitere fachliche Grundsatzentscheidung umgesetzt und anhand des Testkatalogs abgenommen werden. |
|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|

## Dokumentende

*Dieses Lastenheft konsolidiert den fachlichen Stand einschließlich aller Antworten bis Frage 99 und der Regeln aus den drei bereitgestellten Excel-Referenzen. Bei späteren Änderungen ist eine neue Dokument- und Konfigurationsversion zu erzeugen; laufende Vorgänge bleiben snapshot-stabil.*
