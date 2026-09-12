<?php

namespace App\Http\Controllers\E2E;

use App\Models\Calculation;
use App\Models\CalculationFieldValue;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionFieldValue;
use App\Models\DispoOrder;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPosition;
use App\Models\DispoOrderPositionFieldValue;
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
            'calculation_id' => ['required_without:dispo_order_id', 'nullable', 'integer'],
            'dispo_order_id' => ['required_without:calculation_id', 'nullable', 'integer'],
            'field_key' => ['required', 'string'],
            'option_key' => ['required', 'string'],
            'is_active' => ['required', 'boolean'],
            'position_id' => ['sometimes', 'nullable', 'integer'],
            'dispo_order_position_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $snap = $this->resolveSnapshotFieldFromRequest($validated);

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
            'calculation_id' => ['required_without:dispo_order_id', 'nullable', 'integer'],
            'dispo_order_id' => ['required_without:calculation_id', 'nullable', 'integer'],
            'field_key' => ['required', 'string'],
            'visible' => ['required', 'boolean'],
            'position_id' => ['sometimes', 'nullable', 'integer'],
            'dispo_order_position_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $snap = $this->resolveSnapshotFieldFromRequest($validated);

        $snap->visible = (bool) $validated['visible'];
        $snap->save();

        return response()->json(['ok' => true]);
    }

    public function choiceValue(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'calculation_id' => ['required_without:dispo_order_id', 'nullable', 'integer'],
            'dispo_order_id' => ['required_without:calculation_id', 'nullable', 'integer'],
            'field_key' => ['required', 'string'],
            'position_id' => ['sometimes', 'nullable', 'integer'],
            'dispo_order_position_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (! empty($validated['dispo_order_id'])) {
            return $this->dispoChoiceValueResponse($validated);
        }

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

    public function dispoChoiceValue(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'dispo_order_id' => ['required', 'integer'],
            'field_key' => ['required', 'string'],
            'dispo_order_position_id' => ['sometimes', 'nullable', 'integer'],
            'position_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        return $this->dispoChoiceValueResponse($validated);
    }

    public function dispoPositions(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'dispo_order_id' => ['required', 'integer'],
        ]);

        $order = $this->dispoOrderOrFail((int) $validated['dispo_order_id']);
        $rows = DispoOrderPosition::query()
            ->where('dispo_order_id', $order->id)
            ->orderBy('id')
            ->get(['id', 'calculation_position_id', 'effective_configuration_snapshot_id']);

        return response()->json([
            'lock_version' => (int) $order->lock_version,
            'positions' => $rows->map(static fn (DispoOrderPosition $position): array => [
                'id' => $position->id,
                'calculation_position_id' => $position->calculation_position_id,
                'effective_configuration_snapshot_id' => $position->effective_configuration_snapshot_id,
            ])->all(),
        ]);
    }

    public function replaceDispoChoiceOptions(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'dispo_order_id' => ['required', 'integer'],
            'dispo_order_position_id' => ['required', 'integer'],
            'field_key' => ['required', 'string'],
            'options' => ['required', 'array', 'min:1'],
            'options.*.key' => ['required', 'string'],
            'options.*.label' => ['required', 'string'],
            'options.*.sort' => ['required', 'integer'],
            'options.*.is_active' => ['required', 'boolean'],
        ]);

        $order = $this->dispoOrderOrFail((int) $validated['dispo_order_id']);
        $snap = $this->resolveDispoSnapshotField(
            $order,
            $validated['field_key'],
            (int) $validated['dispo_order_position_id'],
        );

        /** @var list<array{key: string, label: string, sort: int, is_active: bool}> $options */
        $options = array_values(array_map(
            static fn (array $option): array => [
                'key' => (string) $option['key'],
                'label' => (string) $option['label'],
                'sort' => (int) $option['sort'],
                'is_active' => (bool) $option['is_active'],
            ],
            $validated['options'],
        ));

        $snap->options_json = $options;
        $snap->save();

        return response()->json(['ok' => true]);
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function dispoChoiceValueResponse(array $validated): JsonResponse
    {
        $order = $this->dispoOrderOrFail((int) $validated['dispo_order_id']);
        $positionId = isset($validated['dispo_order_position_id'])
            ? (int) $validated['dispo_order_position_id']
            : (isset($validated['position_id']) ? (int) $validated['position_id'] : null);
        $snap = $this->resolveDispoSnapshotField(
            $order,
            $validated['field_key'],
            $positionId,
        );

        if ($positionId !== null) {
            $row = DispoOrderPositionFieldValue::query()
                ->where('dispo_order_position_id', $positionId)
                ->where('snapshot_field_definition_id', $snap->id)
                ->first();
        } else {
            $row = DispoOrderFieldValue::query()
                ->where('dispo_order_id', $order->id)
                ->where('snapshot_field_definition_id', $snap->id)
                ->first();
        }

        return response()->json([
            'value_json' => $row?->value_json,
            'exists' => $row !== null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveSnapshotFieldFromRequest(array $validated): SnapshotFieldDefinition
    {
        if (! empty($validated['dispo_order_id'])) {
            $order = $this->dispoOrderOrFail((int) $validated['dispo_order_id']);
            $positionId = isset($validated['dispo_order_position_id'])
                ? (int) $validated['dispo_order_position_id']
                : (isset($validated['position_id']) ? (int) $validated['position_id'] : null);

            return $this->resolveDispoSnapshotField(
                $order,
                $validated['field_key'],
                $positionId,
            );
        }

        $calculation = $this->calculationOrFail((int) $validated['calculation_id']);

        return $this->resolveSnapshotField(
            $calculation,
            $validated['field_key'],
            isset($validated['position_id']) ? (int) $validated['position_id'] : null,
        );
    }

    private function calculationOrFail(int $calculationId): Calculation
    {
        return Calculation::query()->whereKey($calculationId)->firstOrFail();
    }

    private function dispoOrderOrFail(int $dispoOrderId): DispoOrder
    {
        return DispoOrder::query()->whereKey($dispoOrderId)->firstOrFail();
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

    private function resolveDispoSnapshotField(
        DispoOrder $order,
        string $fieldKey,
        ?int $positionId,
    ): SnapshotFieldDefinition {
        if ($positionId !== null) {
            $position = DispoOrderPosition::query()
                ->where('dispo_order_id', $order->id)
                ->whereKey($positionId)
                ->firstOrFail();
            $snapshotId = $position->effective_configuration_snapshot_id
                ?? $order->configuration_snapshot_id;

            return SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $snapshotId)
                ->where('key', $fieldKey)
                ->firstOrFail();
        }

        $header = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $fieldKey)
            ->first();
        if ($header !== null) {
            return $header;
        }

        $effectiveIds = DispoOrderPosition::query()
            ->where('dispo_order_id', $order->id)
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
