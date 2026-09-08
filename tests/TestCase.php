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
     * Live-Fingerprint für Calc-Create (HTTP und Writer).
     */
    protected function liveSchemaFingerprint(): string
    {
        return (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculation()['schema_fingerprint'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withLiveSchemaFingerprint(array $payload): array
    {
        $payload['schema_fingerprint'] = $this->liveSchemaFingerprint();

        return $payload;
    }
}
