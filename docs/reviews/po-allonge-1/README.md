# Review: PO-ALLONGE-1

Stand: 14. Oktober 2026. Branch `feat/swf-allonge-average`.
Basis `origin/main` @ `722bc5571a88cbeec64685a3ba9a87eb2327e5e4`.

Umgesetzt ist nur SWF-Medium Allonge × Durchschnitt. Die Spot-Allonge-Komponente,
Trailer, Calendar, Festpreis, Produktion, weitere SWF, Standardangebote, Budget
und Deploy sind nicht Teil der Freigabe.

Die Bestätigung ist ein Zeitpunkt, keine Zahlenheuristik und keine Checkbox.
Unbestätigte Altwerte 30 s / 0 % bleiben fail-closed. Ein späterer Matrix-Import
überschreibt eine bestätigte Zeile nicht.

Operative Startwerte sind nicht gesetzt. Die vier Sender sind damit nicht als
lokal buchbar festgestellt. `BL-P5-01` bleibt teilweise.

## Review-Nachzug

P1: From-Calc, direkte Draft-Anlage, Draft-Update und Publish prüfen den
Spot-Classic-Vertrag serverseitig. Maßgeblich sind der gespeicherte
Positions-Freeze bzw. das aufgelöste Werbemittel. `kind` und
`engine_profile_key` aus dem Payload werden nicht übernommen. Der
Frozen-Vertrag der Standardangebote wird für Allonge nicht erweitert.

P2: Der Wizard-Hinweis folgt dem Auflösungspfad. Eine unveränderte
gespeicherte Position bleibt ohne Sperrmeldung, auch nach Entzug der
Admin-Bestätigung. Neue Positionen sowie Inventar-, Medium- und
Preisjahr-Wechsel zeigen die Sperre, solange die Kombination unbestätigt ist.
Der Snapshot-Pfad prüft die aktuelle Bestätigung nicht erneut.
