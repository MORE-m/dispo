# SEC-COMPOSER-01 – Composer-Audit nach PR #197

**Status:** Feature-Branch / Draft-PR – **STOP vor Merge ohne PO-Abnahme.**  
Kein Deploy, kein Import, keine Registry-Freigabe, keine OA-TKP-Fachänderung.

## Ursache

Post-Merge-CI `40762346230` auf `3ef37f2…` schlug im Step `Composer audit` fehl.

| Feld | Wert |
|---|---|
| Paket | `symfony/mime` |
| Gelockte Version | `v7.4.19` |
| Advisory | PKSA-wtxr-p26d-nn42 / CVE-2026-45070 |
| Audit-Range (7.4.x) | `>=7.4.0,<7.4.22` |
| Severity | medium |

`7.4.19` liegt klar im betroffenen Bereich. Nur dieses eine Advisory war aktiv.

## Abgleich CVE-2026-45070

Öffentliche Quellen nannten zeitweise **7.4.12** als Fix. Der offizielle Symfony-Blog
(https://symfony.com/cve-2026-45070) korrigiert das ausdrücklich:

> Versions 5.4.52, 6.4.40, 7.4.12 and 8.0.12 … don't contain the fix.  
> The fix first shipped in **5.4.55, 6.4.49, 7.4.22, 8.1.10**.

Composer-Audit (FriendsOfPHP/security-advisories) und der korrigierte Blog stimmen
überein. NVD/ältere GHSA-Ranges können noch die irrtümlichen Fix-Versionen zeigen –
für die CI gilt der Composer-Advisory-Output.

## Korrektur

Gezieltes Update:

```
composer update symfony/mime --with-dependencies
```

| Paket | Vorher | Nachher |
|---|---|---|
| `symfony/mime` | v7.4.19 | **v7.4.22** |
| `symfony/polyfill-intl-idn` | v1.42.0 | v1.43.0 (Transitiv) |
| `symfony/polyfill-mbstring` | v1.43.0 | v1.43.3 (Transitiv) |

`composer.json` unverändert. Kein globales `composer update`. Kein Audit-Ignore.
Nach Update: `composer audit` → keine Advisories.

## OUT

- OA-TKP-Engine / Sonderfreigabe / Registry
- CI-Audit deaktivieren / `--no-audit`
- Deploy / Fachimport / Admin-UI
