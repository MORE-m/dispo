<?php

namespace Tests;

use App\Models\Calculation;
use App\Models\ConfigurationSnapshot;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Live-Fingerprint der Basis für Calc-Create (HTTP und Writer).
     */
    protected function liveSchemaFingerprint(): string
    {
        return (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
    }

    /**
     * DF-3.3a2β: Basis-Fingerprint plus je Position der Fingerprint im
     * gewählten Werbemittelkontext.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withLiveSchemaFingerprint(array $payload): array
    {
        $payload['schema_fingerprint'] = $this->liveSchemaFingerprint();

        if (is_array($payload['positions'] ?? null)) {
            $payload['positions'] = $this->withPositionSchemaFingerprints(null, $payload['positions']);
        }

        return $payload;
    }

    /**
     * Positions-Fingerprint aus Live-Auflösung (Create) oder Gen-3-Basis (Update).
     */
    protected function positionSchemaFingerprintFor(?Calculation $calculation, int $mediumId): string
    {
        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $base = $calculation?->configurationSnapshot;

        if ($base !== null
            && (int) $base->format_version === ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE
        ) {
            return (string) $freeze->resolvePositionSchemaFromBase($base, $mediumId)['schema_fingerprint'];
        }

        return (string) $freeze->resolveLivePositionSchema($mediumId)['schema_fingerprint'];
    }

    /**
     * Setzt für jede Position mit Werbemittel den kanonischen Schema-Fingerprint.
     *
     * @param  list<array<string, mixed>>  $positions
     * @return list<array<string, mixed>>
     */
    protected function withPositionSchemaFingerprints(?Calculation $calculation, array $positions): array
    {
        $calculation?->loadMissing('configurationSnapshot');

        foreach ($positions as $index => $position) {
            if (! is_array($position)) {
                continue;
            }

            $mediumId = (int) ($position['advertising_medium_id'] ?? 0);
            if ($mediumId < 1) {
                continue;
            }

            $positions[$index]['schema_fingerprint'] = $this->positionSchemaFingerprintFor(
                $calculation,
                $mediumId,
            );
        }

        return $positions;
    }
}
