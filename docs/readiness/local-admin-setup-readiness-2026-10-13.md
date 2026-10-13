# Readiness: lokale Admin-Einrichtung

Status: **lokal eingerichtet**, Calc→Dispo-Smoke **ausgeführt** (13. Oktober 2026). Fachabnahme und Deploy sind davon getrennt und **nicht** erfolgt.
Code: `origin/main` @ `11dcc522608965b82b3204de87dad23f0d975631` (PR #139, Post-Merge-CI `38433789254` SUCCESS). Kein Code-Diff, kein Build, kein Deploy.
DB: `dispo_mat_core` @ `127.0.0.1:3306`
Bericht: [`reviews/local-admin-setup-readiness`](../reviews/local-admin-setup-readiness/README.md)
Sicherung: nur Verweis, außerhalb Git, `local-backups/local-admin-setup-20261013-204510/` (`RESTORE.md`)

Code auf `main` bleibt umgesetzt. Frühere Feature-Abnahmen bleiben der damalige Stand und machen `BL-P2-03`, `BL-P5-01` und `BL-P5-02` nicht vollständig. `CRM-004` bleibt offen. Die Browser-Abnahme zu PR #136/#137/#138 auf der Kopie `dispo_accept_crm_calc_20261013` ist eine synthetische Enablement-Prüfung und keine Bestandsabnahme dieser Einrichtung. Die Ursache des vorherigen Leerstands ist unbekannt und gilt nicht als behoben. Historische Dumps wurden nicht zurückgespielt.

| Prüfpunkt | Ergebnis |
|---|---|
| Migrationen | 62/62, unverändert; kein `migrate:fresh`, kein Wipe |
| Katalog | 14 Inventare, 42 Werbemittel, 201 Kombinationen |
| Spot Classic / Tandem / Tridem | `spot_classic`; Tandem und Tridem mit Komponentenprofil |
| Trailer | eigenes `swf_trailer`, SWF-Average-Zuordnung; vier Regeln 20 s / 30 % |
| Übrige Medien | 38 mit `kind` null, nicht buchbar |
| Spotlisten 2026 | 8 aktiv, Zellen gegen Workbook geprüft, keine 0-Füllung |
| Produktionspreise 2026 | 8 aktiv, rabatt- und AE-unfähig |
| Salesforce | Import **11** applied; Re-Import **12** unverändert; 4-Zeilen-Import **9** erhalten |
| Benutzer, Entwurf, Feldsets | 8 Benutzer / Feldsets 2 unverändert; Kalkulation 1 unverändert; Smoke hat fünf weitere Entwürfe angelegt |
| Calc→Dispo-Smoke | ausgeführt, Soll = Ist, Abschnitt unten; keine Fachabnahme |
| Deploy | nicht erfolgt |

## Calc→Dispo-Smoke

Prüfport `127.0.0.1:8075`, danach beendet. Port 8000 weiter PID 3130. `.env` unverändert, SHA-256 `0594cc619f0a0b1a9078dde6bff4eb24c175c2039d451250567a26263e9bf2e2`. Code weiter `11dcc522608965b82b3204de87dad23f0d975631`. Neue Sätze nur mit Präfix „Lokaler Smoke 20261013“, Testdaten bleiben. Kein Import, keine Preis-/Regelpflege, keine Freigabe, kein Versand.

Zeitstempel sind UTC. Fingerprints des Vorbestands danach identisch: Kalkulation 1 `09b647134eb964f0710ebcb814e52a36c4ef0b9ec5d61343e80987c3bf9b3c9a`, Accounts 1–3454 `b8bac910e64421f462176c34f9ff931af18e4935141af0b4209c6f26184f00b9`.

Preise vor dem Preview: Radio Hamburg Ø 40,0000 €/s (56+43+31+30), ROCK Ø 14,7500 €/s (17+16+13+13), Mo–Fr 08:00–11:59. Spot = Ø × 30 × Anzahl. Trailer = Anzahl × Ø × 20 × 1,30.

| Ablauf | Soll | Ist | IDs |
|---|---:|---:|---|
| Bestand, 4 Spots, ohne Rabatt/AE | 4.800,00 + 1.770,00 = 6.570,00 | 6.570,00 | Kalkulation 2, Dispo 1; Kunde Account 1, Stammdaten unverändert |
| Dieselbe Kalkulation, beide Mengen 2 | 2.400,00 + 885,00 = 3.285,00 | Calc 3.285,00; Dispo 1 bleibt 6.570,00 | Dispo 2 = 3.285,00; Plural-Banner, beide Links |
| Freitext ohne Domain, 2 Spots Radio Hamburg | 2.400,00 | 2.400,00 | Account 3455, Kalkulation 3, Dispo 3; Preview ohne Account; zweiter Save ohne weiteren Account; „Meridian-Nummer folgt“ |
| Agentur als Rechnungsempfänger, danach gelöst | 2.400,00 | 2.400,00 | Account 3456, Kalkulation 4; Dispo 4 bleibt Agentur/Rechnungsempfänger Agentur |
| Trailer, je 4 × 20 s / 30 % | 4.160,00 + 1.534,00 = 5.694,00 | 5.694,00 | Kalkulation 5, Dispo 5; Regeln 184 und 147; Kalender/Festpreis nicht wählbar |
| Produktion 2×600 + 0×600 + 3×400 | 2.400,00 zusätzlich; N/N 5.685,00 | 5.685,00 | Kalkulation 6, Dispo 6; S-Zeilen, Menge 0 = 0,00; Listen 1–8 unverändert |
| Dieselbe Kalkulation, Auftrags-AE 15 % | AE 492,75 nur auf 3.285,00 Medien; N/N 5.192,25; Produktion 2.400,00 | 5.192,25; Dispo 6 bleibt 5.685,00 | Produktionszeilen AE-fähig nein, `ae_amount` 0 |

Zweiter Save ohne Reload hat bei Kalkulation 2 und 3 keine zusätzlichen Accounts erzeugt. Dispo-Snapshots bleiben nach Mengenänderung, Agentur-Lösen und AE-Aktivierung auf dem jeweiligen früheren Stand.

Offen und nicht Teil dieses Smokes: `max_allowed_packet` derzeit nur temporär 16 MiB, nach einem MySQL-Neustart wieder 1 MiB; der CRM-Importhinweis nennt weiter nicht alle vier erlaubten Salesforce-Typen. Fachabnahme, andere Umgebungen und Deploy bleiben offen. `BL-P2-03`, `BL-P5-01` und `BL-P5-02` bleiben teilweise. `CRM-004` bleibt offen.
