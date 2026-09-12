<?php

namespace App\Http\Controllers\E2E;

use App\Models\Calculation;
use App\Models\CalculationFieldValue;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionFieldValue;
use App\Models\SnapshotFieldDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Nur aktiv wenn APP_ENV=testing und E2E_SERVER=1. Keine Produktionsroute.
 */
class E2EChoiceSnapshotController
{
    public function setOptionActive(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'calculation_id' => ['required', 'integer'],
            'field_key' => ['required', 'string'],
            'option_key' => ['required', 'string'],
            'is_active' => ['required', 'boolean'],
            'position_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $calculation = $this->calculationOrFail((int) $validated['calculation_id']);
        $snap = $this->resolveSnapshotField(
            $calculation,
            $validated['field_key'],
            isset($validated['position_id']) ? (int) $validated['position_id'] : null,
        );

        $options = $snap->options_json;
        if (! is_array($options)) {
            throw new RuntimeException('options_json fehlt.');
        }

        $found = false;
        foreach ($options as &$option) {
            if ($option['key'] === $validated['option_key']) {
                $option['is_active'] = (bool) $validated['is_active'];
                $found = true;
            }
        }
        unset($option);

        if (! $found) {
            throw new RuntimeException('Option nicht gefunden.');
        }

        $snap->options_json = $options;
        $snap->save();

        return response()->json(['ok' => true]);
    }

    public function setFieldVisible(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'calculation_id' => ['required', 'integer'],
            'field_key' => ['required', 'string'],
            'visible' => ['required', 'boolean'],
            'position_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $calculation = $this->calculationOrFail((int) $validated['calculation_id']);
        $snap = $this->resolveSnapshotField(
            $calculation,
            $validated['field_key'],
            isset($validated['position_id']) ? (int) $validated['position_id'] : null,
        );

        $snap->visible = (bool) $validated['visible'];
        $snap->save();

        return response()->json(['ok' => true]);
    }

    public function choiceValue(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'calculation_id' => ['required', 'integer'],
            'field_key' => ['required', 'string'],
            'position_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $calculation = $this->calculationOrFail((int) $validated['calculation_id']);
        $positionId = isset($validated['position_id']) ? (int) $validated['position_id'] : null;
        $snap = $this->resolveSnapshotField(
            $calculation,
            $validated['field_key'],
            $positionId,
        );

        if ($positionId !== null) {
            $row = CalculationPositionFieldValue::query()
                ->where('calculation_position_id', $positionId)
                ->where('snapshot_field_definition_id', $snap->id)
                ->first();
        } else {
            $row = CalculationFieldValue::query()
                ->where('calculation_id', $calculation->id)
                ->where('snapshot_field_definition_id', $snap->id)
                ->first();
        }

        return response()->json([
            'value_json' => $row?->value_json,
            'exists' => $row !== null,
        ]);
    }

    public function positions(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'calculation_id' => ['required', 'integer'],
        ]);

        $calculation = $this->calculationOrFail((int) $validated['calculation_id']);
        $rows = CalculationPosition::query()
            ->where('calculation_id', $calculation->id)
            ->orderBy('id')
            ->get(['id', 'client_key', 'effective_configuration_snapshot_id']);

        return response()->json([
            'positions' => $rows->map(static fn (CalculationPosition $position): array => [
                'id' => $position->id,
                'client_key' => $position->client_key,
                'effective_configuration_snapshot_id' => $position->effective_configuration_snapshot_id,
            ])->all(),
        ]);
    }

    private function calculationOrFail(int $calculationId): Calculation
    {
        return Calculation::query()->whereKey($calculationId)->firstOrFail();
    }

    private function resolveSnapshotField(
        Calculation $calculation,
        string $fieldKey,
        ?int $positionId,
    ): SnapshotFieldDefinition {
        if ($positionId !== null) {
            $position = CalculationPosition::query()
                ->where('calculation_id', $calculation->id)
                ->whereKey($positionId)
                ->firstOrFail();
            $snapshotId = $position->effective_configuration_snapshot_id
                ?? $calculation->configuration_snapshot_id;

            return SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $snapshotId)
                ->where('key', $fieldKey)
                ->firstOrFail();
        }

        $header = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $fieldKey)
            ->first();
        if ($header !== null) {
            return $header;
        }

        $effectiveIds = CalculationPosition::query()
            ->where('calculation_id', $calculation->id)
            ->whereNotNull('effective_configuration_snapshot_id')
            ->pluck('effective_configuration_snapshot_id')
            ->all();

        if ($effectiveIds === []) {
            abort(404, 'Snapshot-Feld nicht gefunden.');
        }

        return SnapshotFieldDefinition::query()
            ->whereIn('configuration_snapshot_id', $effectiveIds)
            ->where('key', $fieldKey)
            ->firstOrFail();
    }

    private function assertAuthenticated(Request $request): void
    {
        if ($request->user() === null) {
            abort(401);
        }
    }

    private function assertE2E(): void
    {
        if (! app()->environment('testing') || ! config('app.e2e_server')) {
            abort(404);
        }
    }
}
