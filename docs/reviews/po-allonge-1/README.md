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
