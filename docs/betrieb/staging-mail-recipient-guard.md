# Staging-Mail-Empfänger-Guard (EPIC-01-MAIL-1)

Zentrale serverseitige Empfängerbeschränkung für SpeedIt-Staging.

## Konfiguration

| Variable | Werte | Default |
|---|---|---|
| `DISPO_MAIL_RECIPIENT_GUARD` | `off` \| `allowlist` | `off` |
| `DISPO_MAIL_RECIPIENT_ALLOWLIST` | Komma-getrennte **exakte** E-Mail-Adressen | leer |

Config-Spiegel: `config/dispo.php` → `mail_recipient_guard`, `mail_recipient_allowlist`.

### Allowlist-Syntax

- Nur vollständige Adressen (`name@domain.tld`).
- Normalisierung: Trim + Lowercase.
- **Keine** Domain-Wildcards (`*@firma.de` ist ungültig und fail-closed).
- Leere Liste bei Mode `allowlist` = **gesamter Versand gesperrt**.
- Ein ungültiger Eintrag in der Liste = gesamte Allowlist ungültig = gesperrt.

### Aktivierung

- **Produktion:** Guard `off` lassen – kein Versandblock.
- **Staging mit Test-SMTP:** `allowlist` setzen **bevor** `MAIL_MAILER=smtp`.
- Empfohlenes Staging-Minimum:

```env
MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=no-reply@dispo.more-marketing.de
DISPO_MAIL_RECIPIENT_GUARD=allowlist
DISPO_MAIL_RECIPIENT_ALLOWLIST=niklas@example.test,po@example.test
```

## Technik

- Listener `EnforceStagingMailRecipientAllowlist` auf `Illuminate\Mail\Events\MessageSending`.
- Greift alle Laravel-Mail-Pfade: Mailable, Notification-MailChannel (Passwort-Reset), Outbox → `Mail::send`.
- Prüft To, Cc und Bcc; bei Verstoß Exception (nicht `return false`), damit Aufrufer keinen Erfolg annehmen.
- Outbox: `StagingMailRecipientBlockedException` → Status `failed` **ohne** Retry (konfigurativ, nicht transient).
- Logs: Warning ohne Betreff/Body; nur Zähler.

## Abgedeckte Pfade

| Pfad | Abgedeckt |
|---|---|
| `SalesInquiryOutboxMail` / Outbox-Delivery / Queue-Job | ja |
| Fortify Passwort-Reset (`ResetPassword` Notification) | ja |
| Zukünftige `Mail::` / Mailables über Laravel Mailer | ja |
| Direkte Symfony-Transport-Aufrufe außerhalb Laravel | nicht vorhanden; würden Guard umgehen |

## Fail-closed / Fehlverhalten

| Situation | Ergebnis |
|---|---|
| Guard `off` | Kein Eingriff |
| Guard `allowlist`, leere Liste | Exception, kein Transport |
| Guard `allowlist`, ungültige Config | Exception |
| Gemischte Empfänger / Cc / Bcc nicht erlaubt | Gesamte Nachricht blockiert |
| Outbox blockiert | `failed`, kein `sent` |
| Passwort-Reset an Nicht-Allowlist | Exception (HTTP-Fehler) – Staging-User müssen Allowlist-Adressen nutzen |

## Testverfahren (CI)

Pest: `tests/Feature/Mail/StagingMailRecipientGuardTest.php` mit `MAIL_MAILER=array` (kein Netzwerk).

## Staging-SMTP-Smoke (nach Deploy, manuell)

1. Guard `allowlist` + bekannte Tester-Adresse.
2. Passwort-Reset an Allowlist-Adresse → Mail kommt an.
3. Outbox-Test an Allowlist → `sent`.
4. Kurz Allowlist ohne Zieladresse → Versand `failed` / Exception; **kein** Versand an Dritte.
5. Logs prüfen: keine Mail-Bodies.

## Rollback

1. `DISPO_MAIL_RECIPIENT_GUARD=off` und Config-Cache neu bauen **oder**
2. `MAIL_MAILER=log` bis Guard korrigiert ist.
3. Kein DB-Rollback nötig (keine Migration).
