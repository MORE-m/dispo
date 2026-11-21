<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * EPIC-01-MAIL-1: Staging-Mailversand an nicht freigegebene Empfänger abgebrochen.
 *
 * Wird aus dem MessageSending-Listener geworfen (nicht return false), damit
 * Aufrufer wie die Outbox den Versand nicht als Erfolg behandeln.
 */
final class StagingMailRecipientBlockedException extends RuntimeException {}
