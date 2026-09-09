<?php

namespace Tests;

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
            $freeze = app(ConfigurationSnapshotFreezeService::class);

            foreach ($payload['positions'] as $index => $position) {
                $mediumId = (int) ($position['advertising_medium_id'] ?? 0);
                if ($mediumId < 1) {
                    continue;
                }
                $payload['positions'][$index]['schema_fingerprint'] = (string) $freeze
                    ->resolveLivePositionSchema($mediumId)['schema_fingerprint'];
            }
        }

        return $payload;
    }
}
