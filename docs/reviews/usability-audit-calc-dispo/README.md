# Nutzbarkeits-Audit: Kalkulation → Dispo (Tagesgeschäft)

Stand: 11. Oktober 2026 (Nachzug Implementierungs-Slice)  
Auditbasis: `origin/main` @ `d4ff19607ebcb4a45f6381c40d9a568ed8257e27`
(Merge PR #135; Post-Merge-CI [`38074149530`](https://github.com/MORE-m/dispo/actions/runs/38074149530) SUCCESS)  
Audit-Branch: `docs/usability-audit-calc-dispo` @ `22722a4`  
Umsetzungs-Branch: `feat/crm-wizard-picker` (Worktree `dispo-wt-feat-crm-wizard-picker`)

## 0. Urteil in einem Satz

Technisch sind CRM-Slice, Spot-Methoden und Calc→Dispo auf `main` und lokal
datenbereit – im Vertriebsalltag blockiert vor allem die **hybride CRM-Bedienung
(Freitext + Account-IDs)** den durchgängigen Fluss, nicht fehlende Engine-Methoden.

## 1. Basis und Prüfgrenzen

| | |
|---|---|
| Code | Worktree = `d4ff196…`; `dispo-main` unverändert @ gleiche SHA |
| Dev Port 8000 / `.env` | unberührt |
| Lesend | MySQL `dispo_mat_core` (SELECT); Browser Port **8062** |
| Schreibend | isolierte SQLite `database/e2e-usability-audit.sqlite`, Port **8063** |
| Nicht | CRM-Import/Upload/Preview, neue Abnahmeaufträge in `dispo_mat_core`, Deploy |
| Separatserver | nach Prüfung beendet |

Lokale CRM-Abnahme (vorbestehend, bestätigt per SELECT): Import-ID **2** applied
(SHA-256 Prefix `0c424903…`, 3453 gültige Zeilen). Gesamtbestand Accounts **3477**
(= operativ + spätere synthetische Abnahme-Imports; nicht mit Erstimport-Mengen
verwechseln). Alte Freitext-Calcs **1–12** ohne Account-FK.

## 2. Statusdrift (Dokument vs. Code auf `main`)

| Thema | Doku-Stand (lokal gelesen) | Beleg Ist |
|---|---|---|
| CRM Daten-Readiness „Docs-Draft noch nicht auf main“ | `fortschritt.md`, `readiness/BL-P2-03a-…` | **überholt** – PR **#135** gemergt (`d4ff196…`) |
| PO-CALC-DISPO-HINT-1 „Draft-PR / Merge offen“ | `fortschritt.md`, `backlog-v1.md` | **überholt** – PR **#120** MERGED; Isolationstexte in Create-Dialog + Dispo-Show |
| PO-AUTH-SPECIAL-APPROVE-1 „Draft-PR #119“ | `backlog-v1.md` | **überholt** – PR **#119** MERGED |
| BL-P2-03 / CRM-004 | teilweise / Kontakte offen | **stimmt** |
| Standardangebote | pausiert laut Auftrag/Status | **nicht** erneut empfohlen |

Keine Backlog-Punkte in diesem Auftrag als erledigt umgeschrieben.

## 3. Operative Methodenabdeckung (Config × Engine × UI × Daten)

| Kombination | Config/Buchbarkeit | Engine | Browser-Nutzbarkeit | Operative Daten `dispo_mat_core` |
|---|---|---|---|---|
| Spot Classic × **Average** | Kategorie `spots` active | Released v1 | Ja (Wizard) | 8 aktive Preislisten 2026 / 501 Items |
| Spot Classic × **Calendar** | Kategorie `spots` active | Released v1 | Ja (Wizard) | dieselben Listen; 75 Preiszellen fail-closed |
| Spot Classic × Settlement **Normal/Festpreis** | UI `pricing_settlement_mode` | kein Method-Key | Ja; klar getrennt von Methode | – |
| Registry-Methode **`fixed_price`** | Kategorie-Zuordnung existiert | **Planned** | **nicht** als Methodenradio | LiveBookability blockiert |
| Hauptspot+Allonge | Medium/Inventarregel | Average+Calendar | Ja (02c) | inventarabhängig |
| Tandem/Tridem | `component_profile` | Average+Calendar+Settlement | Ja (02e/03k) | Matrix |
| Trailer × Average | UX-GATE-C Teilfreigabe | `swf_trailer` / average only | Ja (E2E/Prior-Abnahme) | 4 Inventare 20 s / 30 % |
| Trailer × Calendar/Festpreis | – | nicht released | gesperrt | – |
| Spotproduktion @ Average | Admin-Listen | Calc-Zusatzzeilen | Ja im Scope | 8 active Listen 2026 (600/400 €) |
| Spotproduktion @ Festpreis | – | bewusst ausgeschlossen | UI blockt | – |
| Weitere SWF / Influencer / Social | UX-GATE-C Rest | – | nicht freigegeben | – |

**SQLite-Browser (8063):** Methodenradios nur „Durchschnitt“ / „Kalenderplaner“;
Settlement „Normal“ / „Festpreis (N/N)“ separat; Average-Eingabe
Preiszeitraum/Spots/Tagesgruppe sichtbar.

## 4. Befunde (priorisiert)

### P1 – CRM-Picker im Wizard ist alltagsuntauglich (Bedienungsproblem)

| | |
|---|---|
| Ablauf | Vertrieb wählt Kunde/Agentur in Grunddaten; sieht Freitext + **Kunden-/Agentur-Account-ID** (Spinbutton) + Suche + Matching-Domain/E-Mail + Vorläufig-Checkboxen |
| Wirkung | Technische IDs dominieren; kein „Auswahl entfernen“; Freitext ohne Account bleibt speicherbar → Rechnungsempfänger oft leer; Matching-Felder belasten den Standardfall |
| Code | `resources/js/pages/calculations/wizard.tsx` (~3141–3499); Binder `CrmOrderHeaderBinder.php` (Freitext erlaubt) |
| Tests | E2E `bl-p2-03a-…spec.ts` deckt provisional→RE→Dispo→Meridian ab; Happy-Path `dispo-order.spec.ts` oft **ohne** Account |
| Browser | Port **8062** Calc **24**: Name + Account-ID gesetzt, Meridian angezeigt; Calc **1**: Freitext, leere IDs, RE „nicht gesetzt“. Port **8063** Neu-Calc: gleiche Hybrid-UI |
| Typ | Bedienungsproblem (+ teilweise CRM-001-Lücke) |
| Kleinste Korrektur | Account-Picker (Suche→Chip/Name); IDs nur hidden/`data-test`; Clear/Wechsel; Matching-Felder hinter „Erweitert“ |

### P2 – Isolation Calc↔Dispo fehlt im Wizard (Bedienungsproblem / fehlende Oberfläche)

| | |
|---|---|
| Ablauf | Nach Dispo-Create bleibt Calc editierbar (PO Option A); Hinweise nur im Create-Dialog und Dispo-Show |
| Wirkung | Vertrieb ändert Konditionen im Wizard und nimmt an, der bestehende Dispo folge mit – muss außerhalb des Tools nachhalten oder „weiß es“ |
| Code | Hinweise: `dispo-order-create-dialog.tsx`, `dispo-orders/show.tsx`; Wizard: `calc-dispo-isolation-banner.tsx` + Prop `linkedDispoOrders` |
| Tests | Pest Isolation + `CalcDispoIsolationBannerTest`; Vitest/E2E Create/Show + Wizard-Banner Port **8065** |
| Browser | Create/Show-Hinweise (PR #120); Wizard-Banner nach Dispo-Create (HINT-1 Folgeslice) |
| Typ | Bedienungsproblem (Lifecycle fachlich entschieden; Wizard-Oberfläche nachgezogen) |
| Status | **umgesetzt** im HINT-1-Folgeslice (Draft-PR) |

### P3 – Stammdaten-Liste ohne Namenssuche; Prüfliste zeigt Roh-IDs (Bedienungsproblem)

| | |
|---|---|
| Ablauf | Nav „Stammdaten“ → `/crm/accounts`; Filter nur Typ / „Nur vorläufig“; Prüfliste erreichbar |
| Wirkung | Auffinden außerhalb des Wizards erschwert; Prüfliste zeigt z. B. „ID … · keine Domain“ |
| Code | `crm/accounts/index.tsx` (`q` + Pagination); `match-queue.tsx` Labels; Nav Stammdaten |
| Tests | `CrmMasterdataSearchTest`; E2E Port **8066** |
| Browser | Suche/Filter/Labels im Folgeslice (Draft-PR) |
| Typ | Bedienungsproblem |
| Status | **umgesetzt** im Stammdaten-Such-Folgeslice |

### Weitere belegte Punkte (niedriger als Top 3)

| ID | Typ | Kurz |
|---|---|---|
| F4 | Doku-Abweichung / Anzeige | Meridian getrennt Kunde+Agentur (Calc/Dispo); nicht RE-gesteuert (CRM-003 light fehlt) – **8062** Dispo 17 |
| F5 | Datenlage | Calc 1–12 bewusst ohne Account-FK; 12/24 Calcs mit Account – Freitext-Altbestand |
| F6 | Bedienung | Dispo-Show zeigt teils „Preisliste &lt;id/name&gt;“; Buchungskennzeichen sichtbar (Scope PO-MAT-BOOKING-VIS) |
| F7 | Fehlende Funktion (dokumentiert) | `CRM-004` Kontakte – **kein** Top-3-Vorrang aus diesem Audit |
| F8 | Konsistent | Festpreis-Settlement ≠ Method-Key `fixed_price` (Planned) – UI trennt korrekt |
| F9 | Datenlücke Deploy | CRM/Trailer/Produktion lokal eingerichtet; Deploy/andere Umgebungen offen – kein Folgeslice hier |
| F10 | Testlücke | Teilübernahme-Summen stark in Pest (`DispoOrderPartialTotalsTest`); Browser-E2E Dispo oft alle Positionen / ohne CRM |

## 5. Calc → Dispo (Kurzbeleg)

| Schritt | Ist | Beleg |
|---|---|---|
| Preview/Summen im Wizard | vorhanden | Zusammenfassung 8063: Brutto/Rabatt/N/N |
| Create nur Zusammenfassung | Button dort | `wizard.tsx` step 3 |
| Positionsauswahl / bereits übernommen | ja; Default ohne Adopted | Dialog 8063 |
| Isolation Create+Show | ja (HINT-1) | Textbelege 8062/8063 |
| Zweiter Create ohne Auto-Ersatz | ja | SQLite `DA-…-01` → `DA-…-02` |
| Kundendaten/RE/Meridian im Dispo | Snapshot; RE nur wenn gesetzt | Dispo 17 (agency+beide Meridian); Dispo 2 SQLite (Freitext, Meridian folgt) |
| Sonderfreigabe sichtbar | ja im Scope | SQLite Dispo 2 |
| Manuell außerhalb Tool | Kontakte; Alt-Dispo nach Calc-Edit; ggf. Account-Zuordnung Freitext-Bestand | CRM-004 offen; P2 |

## 6. Top 3 nächste Schritte

### 1) CRM-Wizard-Picker — **umgesetzt** (PO-BLP203-1 I1, Draft-PR)

- **Nutzen:** Jede neue Kalkulation; senkt Fehler bei RE/Agentur/Meridian.
- **Slice erledigt:** Wizard-Picker Suche/Auswahl/Freitext/Clear; Freitext→vorläufiger Account beim Speichern; Bestandsschutz historischer Freitext; klarer RE; E2E Port 8064 in CI.
- **PO-Entscheidung I1:** Freitext bleibt erlaubt; Auto-Provisional beim Speichern; keine Account-Auswahlpflicht.
- **Nicht** erledigt hier: Calc-Banner, Stammdaten-Textsuche, CRM-004, Deploy.

### 2) Calc-Banner Isolation nach Dispo — **umgesetzt** (PO-CALC-DISPO-HINT-1 Folgeslice)

- **Nutzen:** Verhindert falsche Annahme „Dispo aktualisiert sich“.
- **Slice:** Prop `linkedDispoOrders` → Banner nur Wizard; freigegebene Singular-/Plural-Texte; Links bei Dispo-Ansichtsrecht; keine Lifecycle-/Policy-Änderung.
- **Abnahme:** Nach Create erscheint Banner; Mehrfachaufträge Plural; Dispo-Snapshot unverändert nach Calc-Edit.

### 3) Stammdaten-Textsuche (+ Prüfliste-Labels) — **umgesetzt** (Draft-PR)

- **Nutzen:** Auffinden/Zuordnung außerhalb Wizard; seltener als (1), aber häufig bei Konflikten.
- **Slice:** Index-`q` + Pagination; Prüfliste ohne Roh-ID als Primärlabel; Matching unverändert.
- **Abnahme:** Suche Name/Meridian/SF-ID/Domain; Filterkombi; Labels Quelle/Ziel unterscheidbar.

**Nicht** als nächster Hebel aus diesem Audit: pauschal „CRM fertigbauen“, `CRM-004`, weitere SWF-Träger, weitere Methoden, Standardangebote, Deploy.

## 7. Offene Entscheidungen (unverändert / neu sichtbar)

1. CRM-001-Durchsetzung: Pflicht-Kundenaccount jetzt vs. Übergangsphase (soft).
2. CRM-003-Anzeige: RE-primärer Meridian-Block vs. Status quo beide Seiten.
3. ~~Calc-Banner-Wortlaut / Prop-Umfang (HINT-1 Rest).~~ → Folgeslice umgesetzt.
4. Deploy CRM/Trailer/Produktion – separat, nicht Teil dieses Audits.
5. `CRM-004` Kontakte – weiter Folgeslice, hier nicht priorisiert.

## 8. Geänderte Dateien

Nur dieser Bericht:

- `docs/reviews/usability-audit-calc-dispo/README.md`

Lokale Hilfsartefakte (nicht für Git): Worktree-`.env`-Kopie, Symlinks
`vendor`/`node_modules`/`public/build`, SQLite `database/e2e-usability-audit.sqlite`.
