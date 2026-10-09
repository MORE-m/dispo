# Readiness: BL-P2-03 – CRM Salesforce / Meridian

Status: **READY / Slice umgesetzt** (`BL-P2-03a`; `BL-P2-03`/`CRM-004` teilweise bzw. offen)
Stand: 9. Oktober 2026
Auditbasis: `origin/main` @ `fff472f112882b65abad4633ea8e5177ebe58f42`
(Merge PR #132; Feature `feat/bl-p2-03a-salesforce-meridian`; **kein** Deploy)
IDs: `CRM-001`–`CRM-003` (Slice), `CRM-004` (Folgeslice), `DSP-*`, `APR-004` (E1),
`VER-001`/`VER-004`, `AUD-001`/`AUD-002`, `ADM-001`, `AUTH-001`–`AUTH-007`,
`AT-14`/`AT-20`/`AT-21` (Muster), Abgrenzung `SCP-*` / Katalog Kap. 3
Entscheidung: [`docs/entscheidungen/PO-BLP203-1-crm-salesforce-meridian.md`](../entscheidungen/PO-BLP203-1-crm-salesforce-meridian.md)
(**Akzeptiert** A1/B1/C1/D-CSV/E1/F1/G1/H1)
Worktree: `dispo-wt-feat-bl-p2-03a` / Branch `feat/bl-p2-03a-salesforce-meridian`

> Implementierung im Feature-Branch. Kein Merge/Deploy in diesem Auftrag.
> Dev-DB `dispo_mat_core` / Port 8000 / `dispo-main` / `.env` unberührt.
> Keine personenbezogenen Exportzeilen im Repository; nur synthetische Beispiele.

## 0. Urteil

| Voraussetzung | Status |
|---|---|
| Fachablauf Salesforce → Meridian → täglicher Export → Tool | **verbindlich bestätigt** (PO-Eingabe) |
| `CRM-001`–`CRM-004` im Anforderungskatalog | **belegt**; Code nur Freitext-Zwischenstand |
| Salesforce-Account-ID als dauerhafter Identitätsschlüssel | **bestätigt**; Code **fehlt** |
| „Meridian-Nummer folgt“ in allen Auftragsstatus zulässig | **bestätigt**; Code **fehlt** (kein Meridian-Feld) |
| Domain+Typ-Erstzuordnung vorläufiger Datensätze | **bestätigt**; Code **fehlt** |
| Stammdatenversionierung Kunde/Agentur | **fachlich gefordert / Muster vorhanden**; CRM-Modelle **fehlen** |
| Dateiimport-Muster (Upload → Vorschau → Report → Audit) | **vorhanden** (`PriceListImport*`); CRM-Import **fehlt** |
| UX-GATE-D Teilfreigabe CRM-Admin / Import / Zuordnungs-UI | **fehlt** |
| Rollenrechte Import/vorläufige Anlage/Prüfliste | **offen** (PO; keine Rolle erfinden) |
| Kontakte (`CRM-004`) und volle Rechnungsempfänger-UI | **bewusst Folgescope** empfohlen |

**Readiness-Urteil (Nachzug Implementierung):** Vorbedingungen für `BL-P2-03a` sind
über PO-BLP203-1 **akzeptiert** (inkl. CSV-Format und Typstrings). Der Slice ist im
Feature-Branch umgesetzt. `BL-P2-03` / `CRM-004` bleiben **nicht** pauschal erledigt.
Keine Deployment-Freigabe aus diesem Dokument.

---

## 1. Bestätigter Fachablauf (nicht erneut zur Freigabe)

Klassifikation: **bestätigt** (verbindliche PO-Eingabe dieses Auftrags).

1. Salesforce ist Quelle für Kunden-/Agenturstammdaten. Mediaberater pflegen Firmierung
   und Agenturen dort. Projektmanagement stößt den Abgleich mit Meridian **außerhalb**
   des Tools an; danach erhält der Salesforce-Account seine Meridian-Nummer.
2. Der tägliche Salesforce-Export wird ins Tool importiert (auch Accounts ohne Meridian-Nummer).
   Automatische E-Mail-Verarbeitung eingehender Exporte ist **nicht** Bestandteil;
   manueller Dateiimport ist der vorgesehene Einstieg.
3. Bekannte Exportspalten: Accountname, Meridian-ID, Account-ID, Rechnungs-E-Mail,
   Account-Datensatztyp. Beispiel Kunde: `Account KUNDE`. Agentur-Typbezeichnung und
   Dateiformat (CSV/XLSX) noch verifizieren. Numbers-Beispieldatei beschreibt Spalten;
   Numbers-Unterstützung **nicht** voraussetzen.
4. Importierte Salesforce-Account-ID ist der dauerhafte Identitätsschlüssel. Firmierung
   ist kein Identitätsschlüssel. Geänderte Stammdaten → neue Kunden-/Agenturversion;
   unveränderte Daten / wiederholter Import → keine zusätzliche Version. Bestehende
   Aufträge behalten gespeicherte Firmierung und sonstige Stammdaten-Snapshots.
5. „Meridian-Nummer folgt“ ist in **jedem** Auftragsstatus zulässig. Fehlende Nummer
   blockiert weder Anlage noch Freigabe, Übergabe oder Disposition. Späterer Import
   ergänzt die Nummer bei zugeordneten Dispoaufträgen statusunabhängig. Diese
   Identitätsergänzung darf Preise, Firmierungen oder sonstige eingefrorene Auftragsdaten
   **nicht** neu berechnen oder ersetzen. Protokollierung Pflicht. Abweichende vorhandene
   Meridian-Nummern nicht still überschreiben → Konflikt zur Prüfung.
6. Vorläufige Kunden/Agenturen im Tool erlaubt, auch ohne Salesforce-Existenz;
   Dispoaufträge damit normal bearbeitbar. Pflicht für Erstabgleich: Firmierung,
   Datensatztyp, Domain bzw. E-Mail (Domainableitung). Adresse keine Voraussetzung
   für Erstabgleich; sonstige Pflichtfelder separat prüfen.
7. Erstzuordnung ausschließlich Domain + Datensatztyp (kein Firmenname-/Adress-Matching).
   Domain aus Rechnungs-E-Mail. Genau ein Treffer gleichen Typs → automatisch;
   mehrere → manuell; keine Domain/kein Treffer → vorläufig belassen; allgemeine
   Maildomains → manuell; Kunde nur↔Kunde, Agentur nur↔Agentur; Eindeutigkeit über
   gesamten bekannten Salesforce-Bestand; nach Erstzuordnung nur noch Salesforce-ID;
   Domainänderung löst keine automatische Neuzuordnung aus.

**Abgrenzung Katalog Kap. 3:** V1 schließt API-Schnittstellen zu Meridian/Salesforce aus.
Der bestätigte Ablauf ist **manueller Dateiimport** eines Salesforce-Exports – analog zum
bestehenden Preislisten-Excel-Import – und **kein** API-Adapter. Das widerspricht nicht
der Leitentscheidung „Meridian/Salesforce bleiben in V1 manuell“, erweitert sie aber um
einen strukturierten Importpfad. Eine API-Anbindung bleibt V2.

---

## 2. Ist-Analyse (belegt)

### 2.1 Anforderungen und Backlog

| Quelle | Inhalt | Beleg |
|---|---|---|
| `CRM-001` | Jede Kalkulation gehört genau einem intern gepflegten Kunden; Agentur optional | `anforderungskatalog.md` §18.1 |
| `CRM-002` | Rechnungsempfänger ausschließlich Kunde oder Agentur | ebenda |
| `CRM-003` | Meridian-Nummer + Ansprechpartner je Kunde/Agentur; Anzeige aus gewähltem Rechnungsempfänger | ebenda |
| `CRM-004` | Ansprechpartner im Calc-Entwurf optional; vor Übergabe/Abschluss nach Dispo-Pflichtregeln | ebenda |
| `BL-P2-03` | Phase 2, Status **offen**; Ergebnis Stammdatenpflege inkl. Meridian; Akzeptanz Rechnungsempfänger modellierbar | `backlog-v1.md` |
| Zwischenstand | Freitext Kunde/Agentur; `CRM-001` nicht erledigt | `blocker-und-entscheidungslog.md` 29.08.2026; `ux-ui-gate.md` (CRM-001 Freitext bis CRM-Slice) |
| Kopfdaten Dispo | Kunde, Rechnungsempfänger, Meridian-Nr., Agentur, Kontakte … | `anforderungskatalog.md` §16.2/16.3 |
| Freigabeinvalidierung (Soll) | u. a. Kunde, Agentur, Rechnungsempfänger | Katalog §15.2; `workflows-und-berechtigungen.md` |
| Freigabeinvalidierung (Ist) | nur CC-Archiv bei `at_disposition` (PO-AT13-CC-1); übrige Auslöser **offener Scope** | `workflows-und-berechtigungen.md` |
| Datenmodell-Soll | `Customer`, `Agency`, `Contact`; polymorphe Rechnungsempfänger-Auswahl | `datenmodell.md` „CRM-Stammdaten“ |
| Versionierung | Snapshots/Versionen zwingend; Adminänderungen mutieren Historie nicht | `VER-001`–`VER-007`, `AGENTS.md` |
| Audit | Feld-/Objektänderungen, Imports, erzwungene Aktionen | `AUD-001`/`AUD-002`, `ADM-001` |

### 2.2 Code- und Schema-Ist

| Prüfpunkt | Einstufung | Beleg / Lücke |
|---|---|---|
| Modelle `Customer` / `Agency` / `Contact` | **fehlt** | Keine Models/Migrationen; `datenmodell.md` nur Soll. `Organization` = Inventar-Träger, **kein** CRM |
| Calc-Felder | **Freitext** | `calculations.customer_name` / `agency_name` nullable string (`2026_08_29_130000_…`); `CalculationWriter` schreibt Payload |
| Dispo-Kopf | **Freitext-Snapshot** | `dispo_orders.customer_name` / `agency_name`; `DispoOrderSnapshotMapper` kopiert aus Calc |
| Meridian-Nummer | **fehlt** | kein Spalten-/UI-/Validierungspfad |
| Rechnungsempfänger | **fehlt** | kein polymorphes Feld; CRM-002/003 nicht modelliert |
| Stammdatenversionierung CRM | **fehlt** | Versionierungsmuster bei Feldsets/Preislisten vorhanden, nicht für CRM |
| Salesforce-Account-ID / Domain / Datensatztyp | **fehlt** | – |
| Vorläufige Datensätze / Zuordnungsprüfliste | **fehlt** | – |
| CRM-Dateiimport | **fehlt** | wiederverwendbares Muster: `PriceListImportService` (Upload → Preview → Draft/Report → Audit; kein Auto-Activate) |
| Matrix-/Katalog-Import | **vorhanden (anderes Fach)** | `CombinationMatrixImporter` etc. – Vorbild Parallelität/Checksum, nicht CRM-Semantik |
| Calc-/Dispo-Pfade | **Freitext durchgängig** | Wizard, Adopt (`StandardOfferWriter`: Kunde Freitext Pflicht), Notifications (`customer_name` in Payload), Spot-Export |
| Allgemeine Freigabeinvalidierung bei Kundenwechsel | **Soll ja / Code nein** (außer CC) | APR-Liste vs. nur `DispoOrderApprovalInvalidationService` für CC |

### 2.3 Rechte und Rollen (keine Erfindungen)

Vorhandene Rollen im Code (`App\Enums\Role`): `admin`, `sales`, `disposition`,
`management`, `product_management`.

| Fachbegriff | Tool-Rolle / Bedeutung | Beleg |
|---|---|---|
| Mediaberater | fachlich Vertrieb; operativ oft `dispo_orders.advisor_id` (Snapshot) | Katalog; Dispo-Services |
| Vertrieb | Rolle `sales` | `AUTH-002`, Role-Enum |
| Disposition | Rolle `disposition` | Workflows |
| Admin / GF | `admin` / `management` | `AUTH-003` |
| Produktmanagement | Rolle `product_management`; Standardangebote (`AUTH-006`); **kein** automatischer Calc-/Dispo-Zugang (`AUTH-007`) | Katalog §4.1 |
| „Projektmanagement“ (Salesforce-Ablauf) | **Prozessrolle außerhalb des Tools** (Meridian-Abgleich in Salesforce). Im Katalog Kap. 3 als Team-Label „Disposition/Projektmanagement“; **keine** eigene Tool-Rolle `Projektmanagement`. Nicht mit `product_management` gleichsetzen, solange PO das nicht explizit tut. | bestätigt + Katalog; Role-Enum |

**Ist-Rechte CRM:** Es gibt keine CRM-Policies, keine Importrechte, keine Stammdaten-UI.
Vertrieb darf Freitext `customer_name`/`agency_name` an Calc schreiben; Disposition sieht
Snapshots; Produktmanagement hat ohne Extra-Recht keinen Dispo-/Calc-CRM-Pfad.

### 2.4 UX-GATE-D

| Gate | CRM-Bezug | Stand |
|---|---|---|
| UX-GATE-B/C | Calc-Wizard mit Freitext Kunde/Agentur | freigegeben für bestehende Calc-Pfade; **nicht** CRM-Stammdaten |
| UX-GATE-D | Admin Stammdaten, Importvorschau, Zuordnungsprüfliste, ggf. Konflikte | CRM-Oberflächen **nicht** teilfreigegeben; Gate-D insgesamt „teilweise“ für andere Module |

### 2.5 Tatsächliche Gate- und Vertragslücken

1. Keine CRM-Entitäten / Versionen / Salesforce-ID / Meridian / Rechnungsempfänger im Schema.
2. Kein Importvertrag umgesetzt; Format Agentur-Typ und CSV/XLSX offen.
3. Keine UX-GATE-D-Teilfreigabe für CRM.
4. Keine festgelegten Tool-Rechte für Import, vorläufige Anlage, manuelle Zuordnung, Konflikte.
5. Freigabewirkung des Meridian-Nachtrags gegenüber APR-Liste **nicht** entschieden
   (allgemeine Invalidierung ohnehin weitgehend unimplementiert – trotzdem PO-klarstellen).
6. `CRM-004` Kontakte und volle Rechnungsempfänger-Pflege: Soll vorhanden, für Erst-Slice
   abgrenzbar (siehe §4).
7. Katalog-Soll „intern gepflegter Kunde“ (`CRM-001`) vs. Salesforce als führende Quelle:
   bestätigter Ablauf präzisiert die Pflegequelle; Tool hält Versionen/Snapshots.

---

## 3. Import- und Zuordnungsvertrag (Vorschlag)

Legende: **B** = bestätigt · **T** = technische Ableitung · **O** = offene Entscheidung

### 3.1 Spalten- und Typmapping

| Exportspalte (bekannt) | Ziel | Klassifikation |
|---|---|---|
| Account-ID | `salesforce_account_id` (Text, Identität) | **B** |
| Accountname | Firmierung / Anzeigename der Version | **B** |
| Meridian-ID | `meridian_number` nullable; leer = „noch nicht vorhanden“ | **B** |
| Rechnungs-E-Mail | Quellwert; Domain ableiten | **B** |
| Account-Datensatztyp | `customer` \| `agency` | **B** + **O** exakte Agentur-Bezeichnung |

**Typmapping (Vorschlag):**

| Exportwert | Intern | Status |
|---|---|---|
| `Account KUNDE` (Beispiel) | `customer` | **B** (Beispiel bestätigt) |
| Agentur-Typ (Bezeichnung verifizieren) | `agency` | **O** |
| unbekannter Typ | Zeile ablehnen / Fehlerbericht | **T** |

**Salesforce-ID (**T**):** immer als Text speichern; trimmen; canonical vergleichen
case-insensitive (Salesforce-IDs sind alphanumerisch, Groß-/Kleinschreibung und
15-/18-stellige Varianten sauber behandeln – Implementierung: eine Normalisierungsfunktion,
Persistenz der **kanonischen** Form plus optional Rohwert im Importbericht). Keine numerische
Konvertierung.

### 3.2 Domainnormalisierung (**T**)

1. E-Mail trimmen, lower-case für Domainteil.
2. Domain = Teil nach dem letzten `@` der **ersten gültigen** E-Mail.
3. Kein stilles Zusammenfassen verschiedener Subdomains (`a.example.com` ≠ `example.com`).
4. Leere E-Mail → keine Domain → kein Auto-Match.
5. Mehrere E-Mails in einer Zelle (Trenner `;` / `,` / Whitespace): **Vorschlag** erste
   syntaktisch gültige Adresse nutzen; weitere im Report als Warnung – **O** falls PO
   streng „nur genau eine“ will.
6. Ungültige E-Mail → Warnung/Fehler laut Importregeln; Domain nicht raten.

### 3.3 Allgemeine Maildomains (**B** + **T**)

Pflegbare Ausschlussliste (Seed-Mindestsatz z. B. `gmail.com`, `gmx.de`, `outlook.com` –
synthetisch/erweiterbar). Treffer → **nie** Auto-Zuordnung, immer Prüfliste.
Pflege rechtlich/adminseitig: **O** (wer darf Liste ändern).

### 3.4 Konflikte und Importintegrität (**T**/teils **B**)

| Fall | Verhalten |
|---|---|
| Doppelte Account-ID in einer Datei, widersprüchliche Felder | Import der Datei blockieren oder Zeilen als Fehler; keine stille Wahl |
| Doppelte Account-ID, identische Nutzdaten | idempotent, eine logische Zeile |
| Domainmehrdeutigkeit (mehrere SF-Accounts gleichen Typs) | kein Auto-Match; Prüfliste |
| Bestehender SF-Account außerhalb neuer Zeilen macht Domain mehrdeutig | Eindeutigkeit über **gesamten** Bestand (**B**) |
| Wiederholter Import unverändert | keine neue Version; kein Meridian-Nachtrag-Event (**B**) |
| Veraltete Datei / ältere Checksum nach neuerem Import | **O** – Empfehlung: warnen, nicht still zurückschreiben ohne Bestätigung |
| Parallelität zweier Imports | Optimistic Lock / Import-Job-Sperre analog Preislisten (**T**) |
| Leere Meridian-ID | „noch nicht vorhanden“; **löscht keine** bekannte Nummer (**B**) |
| Fehlende Accounts im Export | **nicht** automatisch deaktivieren (**B**) |
| Abweichende Meridian-Nummer bei bekannter ID | Konflikt, kein stilles Überschreiben (**B**) |

### 3.5 Vorschau, Fehlerbericht, Audit (**T**, Muster AT-21)

- Upload → Parse → Vorschau (neu / Update / unverändert / Konflikt / Match-Kandidaten)
- Atomare Übernahme nur nach Bestätigung; Report persistieren (Checksum, Dateiname,
  Akteur, Zeit, Zähler)
- Audit je Importlauf und je materieller Stammdaten-/Zuordnungs-/Nachtragsaktion
  (`ADM-001`, `AUD-001`/`AUD-002`)
- Private Ablage der Importdatei wie Preislisten; **keine** Echtdaten ins Git

---

## 4. Versionierung und Dispo-Nachtrag (Vertrag)

### 4.1 Stammdatenversion (**B** + **T**)

- Änderung von Firmierung, Rechnungs-E-Mail (als Stammdatenattribut), Typ oder anderen
  versionierten Feldern → **neue Version**.
- Unveränderter Import → **keine** neue Version.
- Aktuelle Version = Basis für **neue** Kalkulationen/Dispoaufträge.
- Bestehende Aufträge behalten Snapshots (Firmierung, Meridian-Stand zum Freeze-Zeitpunkt,
  IDs) – analog `VER-004` / `DSP-003`.

### 4.2 Vorläufig → Salesforce-Zuordnung (**B** + **T**)

- Vorläufiger Datensatz erhält Salesforce-Account-ID bei Erstzuordnung.
- Bestehende Calc-/Dispo-Beziehungen auf den internen Stammdatensatz bleiben erhalten
  (kein Re-Create der Aufträge).
- Firmierungsänderung durch SF-Daten erzeugt neue Stammdatenversion für die Zukunft;
  historische Snapshots bleiben.
- Nach Zuordnung: Matching nur noch über Salesforce-ID (**B**).

### 4.3 Meridian-Nachtrag (**B**)

- Gezielt und idempotent: fehlende Nummer ergänzen, wenn Auftrag die betreffende
  Kunden-/Agentur-Identität referenziert (bzw. Snapshot-ID).
- Auch bei `disposed` / `completed` / `cancelled` zulässig.
- Keine Neuberechnung von Preisen, Produktionszeilen, Firmierung, Rabatt/AE.
- Bereits abweichende Nummer → Konfliktfall + Audit, kein Override.
- Wiederholter Import mit gleicher Nummer → kein zweites Nachtragsereignis.

### 4.4 Freigaben (**O** – ausdrücklich)

Soll-APR listet Änderungen an Kunde/Agentur/Rechnungsempfänger als invalidierend.
Allgemeine Invalidierung ist im Code weitgehend **nicht** umgesetzt (außer CC).

Für den bestätigten Meridian-Nachtrag gilt fachlich: reine Identitätsergänzung ohne
Snapshot-Mutation der Firmierung/Preise. **Ob** das Freigaben invalidiert, darf nicht
still entschieden werden → **PO-Frage F** in PO-BLP203-1.
Empfehlung (nicht verbindlich): **keine** Invalidierung bei reinem Meridian-Nachtrag;
Invalidierung nur bei echtem Wechsel von Kunde/Agentur/Rechnungsempfänger oder
Firmierungs-Snapshot-Änderung am Auftrag.

### 4.5 Rechnungsempfänger getrennt (**B**/Soll)

Kunde, Agentur und Rechnungsempfänger sind fachlich getrennt (`CRM-002`/`CRM-003`).
Rechnungsempfänger **nicht** ungeprüft mit Kunde gleichsetzen. Meridian-Anzeige folgt
dem gewählten Rechnungsempfänger. Im Erst-Slice muss mindestens ein klares Minimalmodell
existieren (siehe Slice); volle Kontaktpflege (`CRM-004`) kann folgen.

---

## 5. Empfohlener kleinster vollständiger Slice (`BL-P2-03a`)

### In Scope

1. **Dateiimport** Salesforce-Export (manuell; CSV und/oder XLSX laut PO) mit Vorschau,
   Fehlerbericht, Audit; Accounts **mit und ohne** Meridian.
2. **Stammdaten** Kunde und Agentur inkl. Salesforce-ID, Firmierung, Domain/E-Mail,
   Datensatztyp, Meridian nullable, Versionshistorie.
3. **Vorläufige Anlage** Kunde/Agentur (Firmierung, Typ, Domain/E-Mail) und normale
   Nutzung in Calc/Dispo.
4. **Erstzuordnung** Domain+Typ: Auto bei Eindeutigkeit; sonst Prüfliste (Mehrdeutigkeit,
   Allgemeindomain, kein Treffer).
5. **Calc/Dispo-Anbindung:** Referenz auf Stammdaten + Snapshot von Firmierung und
   Meridian-Stand (nullable); Freitext-Zwischenstand ablösen **im Slice-Umfang**
   (keine Parallel-Welt dauerhaft).
6. **Meridian-Nachtrag** aus späterem Import auf zugeordnete Dispoaufträge, statusunabhängig,
   mit Audit; Konflikte sichtbar.
7. Rechte gemäß PO; UX nur im freigegebenen Gate-Teilumfang.

### Außerhalb / Folgeslices

- Automatischer E-Mail-Ingest der Exportmails
- Salesforce-/Meridian-API
- Numbers-Import
- Vollständige Kontaktverwaltung (`CRM-004`) und alle Dispo-Pflichtregeln dazu
- AE-Agenturstandard-Hierarchie (`COM-006` Folge)
- Massen-Deaktivierung / Lifecycle „Account fehlt im Export“
- Pauschale CRM-Admin-Oberfläche jenseits des Slice
- Deploy

### Abhängigkeiten

| Abhängigkeit | Bewertung |
|---|---|
| `BL-P1-02` (Backlog) | formale Abhängigkeit; technische Basis Calc/Dispo vorhanden |
| Preislisten-Importmuster | wiederverwendbar als Architekturvorbild |
| UX-GATE-D Teilfreigabe | **Vorbedingung** Implementierung UI |
| Exportspalten Agentur + Format | **Vorbedingung** robustes Mapping |
| Kontakte / volle Rechnungsempfänger-UX | nicht blockierend für Import+Meridian-Nachtrag, wenn Minimal-Rechnungsempfänger geklärt |

---

## 6. Tests und Abnahme (geplant, hier nicht ausgeführt)

Isolierte Test-DB; Browser auf eigenem Port (**nicht** 8000). Keine Tests in diesem Auftrag.

| # | Fall |
|---|---|
| 1 | Account ohne Meridian importieren und in Calc/Dispo verwenden |
| 2 | Dispo mit „Meridian-Nummer folgt“ durch alle erlaubten Statusschritte |
| 3 | Vorläufiger Kunde und vorläufige Agentur anlegbar und nutzbar |
| 4 | Eindeutiger Domain-/Typ-Treffer verknüpft automatisch |
| 5 | Mehrdeutige Domain, fehlende Domain, allgemeine Maildomain → keine Auto-Zuordnung |
| 6 | Kunde und Agentur mit gleicher Domain bleiben nach Typ getrennt |
| 7 | Bestehender Account außerhalb neuer Importzeilen verhindert falsche Eindeutigkeit |
| 8 | Späterer Import ergänzt Meridian einmalig, auch in finalen Status |
| 9 | Wiederholter Import ohne zusätzliche Version / ohne zweiten Nachtrag |
| 10 | Geänderte Firmierung/E-Mail → neue Version; historische Auftragswerte bleiben |
| 11 | Abweichende Meridian-Nummer → Konflikt, kein stilles Überschreiben |
| 12 | Rechte positiv/negativ je PO; Audit Import/Zuordnung/Nachtrag |
| 13 | Keine Änderung an Preisen, Produktionszeilen, sonstigen Auftragssnapshots durch Nachtrag |
| 14 | Leere Meridian-ID löscht keine bekannte Nummer; fehlende Export-Accounts deaktivieren nicht |
| 15 | Regression: Freitext-Ablösung bricht Adopt/Notifications/Export nicht still |

---

## 7. Statusdokumente

Geprüft: `docs/backlog-v1.md` führt `BL-P2-03` als **offen**; `fortschritt.md` nennt CRM
als Rest – **kein Drift**. Keine Statuskorrektur in diesem Auftrag.
Neu: Readiness, PO-/UX-Entscheidungsdokument (Vorgeschlagen + bestätigte Regeln),
Review-README; Index in `docs/README.md` und `docs/entscheidungen/README.md`.

---

## 8. Bewusst nicht getan

- Keine Implementierung, Migration, Seeding, Browser-Flows, DB-Zugriff
- Keine personenbezogenen Echtdaten oder Original-Exporte im Repo
- Keine erfundene Rolle „Projektmanagement“ und keine stillen Rechte dafür
- Keine stille Freigabeinvalidierungs-Ausnahme ohne PO
- Keine pauschale CRM-/Gate-/Implementierungsfreigabe
- Kein Commit/PR/Merge/Deploy
