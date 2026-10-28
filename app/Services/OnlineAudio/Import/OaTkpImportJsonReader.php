<?php

namespace App\Services\OnlineAudio\Import;

/**
 * PO-CALC-TKP-1D4c-2: JSON-Datei für OA-Stammdaten-CLI lesen (fail-closed).
 */
final class OaTkpImportJsonReader
{
    /**
     * @return array<mixed>
     */
    public function read(string $path): array
    {
        $resolved = trim($path);
        if ($resolved === '') {
            throw new OaTkpStammdatenImportException('JSON-Pfad fehlt.', ['Pfad zur JSON-Datei fehlt.']);
        }

        if (! is_file($resolved) || ! is_readable($resolved)) {
            throw new OaTkpStammdatenImportException(
                'JSON-Datei nicht lesbar.',
                ["Datei nicht gefunden oder nicht lesbar: {$resolved}"],
            );
        }

        $raw = file_get_contents($resolved);
        if ($raw === false) {
            throw new OaTkpStammdatenImportException(
                'JSON-Datei nicht lesbar.',
                ["Datei konnte nicht gelesen werden: {$resolved}"],
            );
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new OaTkpStammdatenImportException(
                'JSON ungültig.',
                ['JSON-Parsefehler: '.$exception->getMessage()],
            );
        }

        if (! is_array($decoded)) {
            throw new OaTkpStammdatenImportException(
                'JSON ungültig.',
                ['JSON-Wurzel muss ein Objekt oder Array sein.'],
            );
        }

        return $decoded;
    }
}
