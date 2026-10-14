# Readiness: SWF-Medium Allonge × Durchschnitt

Urteil: **auf diesem Branch umgesetzt.** Nicht gemergt. Nicht deployed.
Operativ nicht eingerichtet. Startwerte bleiben offen. `BL-P5-01` bleibt teilweise.
Stand: 14. Oktober 2026
Basis: `origin/main` @ `722bc5571a88cbeec64685a3ba9a87eb2327e5e4`
Entscheidung: [`PO-ALLONGE-1`](../entscheidungen/PO-ALLONGE-1-kombinationspflege.md)

Keine Aussage, die vier Sender seien lokal buchbar. Dafür fehlen die
bestätigenden Admin-Speicherungen je Inventar. Dieser Slice ändert
`dispo_mat_core` nicht.

## Umsetzung

- Kind `swf_allonge`, Profil nur Average released v1.
- Nur das Medium `allonge` steht auf Override mit dieser Zuordnung.
- Kategorie und Trailer bleiben `swf_trailer`.
- Spalte `swf_allonge_confirmed_at`, nullable, ohne Wertänderung bestehender Zeilen.
- Detail-Speichern setzt oder leert den Zeitpunkt und schreibt ihn ins Audit.
- Matrix-Create schreibt Länge, Aufschlag und Zeitpunkt `null`.
- Matrix-Update lässt diese drei Felder stehen.
- Live-Auflösung verlangt Länge, Aufschlag und Zeitpunkt.
- Snapshot-Pfad prüft die aktuelle Bestätigung nicht erneut.
- Wizard: nur Durchschnitt, Länge editierbar, kein Aufschlagsfeld, keine Spot-Komponenten.
- Standardangebote bleiben auf Spot Classic begrenzt (From-Calc, Draft, Publish).
- Der Wizard-Hinweis sperrt nur den Live-Pfad, nicht einen gültigen Snapshot.

## Belege

Synthetisch, keine Startwerte: 10 × 2,00 × 15 × 1,30 = 390,00 €.
Weiteres Inventar: 4 × 3,00 × 20 × 1,10 = 264,00 €.
Preisjahr nach Admin-Aufschlag 50 % und Sekundenpreis 4,00 bei Länge 20 s und
10 Spots: 1.200,00 €. Der gespeicherte Snapshot davor bleibt 390,00 €.

Browser: isolierte SQLite, Port 8067, `--retries=0`,
`tests/e2e/po-allonge-1-swf-allonge-average.spec.ts`.

## Offen

Startwerte je Sender. Operative Speicherung auf der Detailseite.
Merge und Deploy dieses Branches. Weitere SWF und andere Methoden.
