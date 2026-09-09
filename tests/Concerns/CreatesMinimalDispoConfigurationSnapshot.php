<?php

namespace Tests\Concerns;

use App\Models\Calculation;
use App\Models\ConfigurationSnapshot;
use App\Models\DispoOrder;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\DynamicField\DispoConfigurationSnapshotComposer;

trait CreatesMinimalDispoConfigurationSnapshot
{
    protected function minimalDispoConfigurationSnapshotId(Calculation $calculation): int
    {
        $calculation->loadMissing([
            'configurationSnapshot.fieldDefinitions',
            'configurationSnapshot.rules',
        ]);

        $calcSnapshot = $calculation->configurationSnapshot;
        if ($calcSnapshot === null) {
            throw new \RuntimeException('Test-Kalkulation ohne configuration_snapshot_id.');
        }

        if ((int) $calcSnapshot->format_version === ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            $calculation->loadMissing('positions');
            $positionIds = $calculation->positions
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
            $snapshot = app(ConfigurationSnapshotFreezeService::class)
                ->freezeDispoV3($calcSnapshot, $positionIds);

            return (int) $snapshot->id;
        }

        $snapshot = app(DispoConfigurationSnapshotComposer::class)->composeFromCalculationSnapshot(
            $calcSnapshot,
            expectedCalculationSnapshotId: (int) $calculation->configuration_snapshot_id,
        );

        return (int) $snapshot->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createDispoOrderWithMinimalSnapshot(
        Calculation $calculation,
        array $attributes,
    ): DispoOrder {
        return DispoOrder::query()->create(array_merge([
            'configuration_snapshot_id' => $this->minimalDispoConfigurationSnapshotId($calculation),
        ], $attributes));
    }
}
