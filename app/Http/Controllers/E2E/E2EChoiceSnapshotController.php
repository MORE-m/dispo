<?php

namespace App\Http\Controllers\E2E;

use App\Models\Calculation;
use App\Models\CalculationFieldValue;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionFieldValue;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\ConfigurationSnapshotSourceRule;
use App\Models\DispoOrder;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPosition;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Services\DynamicField\SnapshotFieldRuleDedupeKey;
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

    public function upsertRule(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'calculation_id' => ['required_without:dispo_order_id', 'nullable', 'integer'],
            'dispo_order_id' => ['required_without:calculation_id', 'nullable', 'integer'],
            'condition' => ['required', 'array'],
            'action' => ['required', 'array'],
            'sort' => ['sometimes', 'integer'],
        ]);

        $snapshot = $this->resolveTargetSnapshot($validated);
        $condition = $validated['condition'];
        $action = $validated['action'];
        $dedupe = SnapshotFieldRuleDedupeKey::from($condition, $action);
        $sort = (int) ($validated['sort'] ?? 100);
        $source = $this->coreSourceOrFail($snapshot);

        $existingRule = SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->where('dedupe_key', $dedupe)
            ->first();
        $existingSourceRule = ConfigurationSnapshotSourceRule::query()
            ->where('configuration_snapshot_source_id', $source->id)
            ->where('dedupe_key', $dedupe)
            ->first();
        if ($existingRule !== null) {
            $sourceFieldRuleId = (int) $existingRule->source_field_rule_id;
        } elseif ($existingSourceRule !== null) {
            $sourceFieldRuleId = (int) $existingSourceRule->source_field_rule_id;
        } else {
            $sourceFieldRuleId = $this->allocateE2ESourceFieldRuleId($snapshot);
        }

        $sourceRule = ConfigurationSnapshotSourceRule::query()->updateOrCreate(
            [
                'configuration_snapshot_source_id' => $source->id,
                'dedupe_key' => $dedupe,
            ],
            [
                'source_field_rule_id' => $sourceFieldRuleId,
                'sort' => $sort,
                'condition_json' => $condition,
                'action_json' => $action,
            ],
        );

        $rule = SnapshotFieldRule::query()->updateOrCreate(
            [
                'configuration_snapshot_id' => $snapshot->id,
                'dedupe_key' => $dedupe,
            ],
            [
                'sort' => $sort,
                'condition_json' => $condition,
                'action_json' => $action,
                'provenance_source_id' => $source->id,
                'source_field_rule_id' => (int) $sourceRule->source_field_rule_id,
            ],
        );

        return response()->json(['ok' => true, 'rule_id' => $rule->id]);
    }

    public function corruptRules(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'calculation_id' => ['required_without:dispo_order_id', 'nullable', 'integer'],
            'dispo_order_id' => ['required_without:calculation_id', 'nullable', 'integer'],
        ]);

        $snapshot = $this->resolveTargetSnapshot($validated);
        $corruptCondition = ['op' => '__corrupt__', 'field_key' => 'campaign_period'];
        $corruptAction = ['op' => 'require_field', 'field_key' => 'campaign_period'];

        $rule = SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->orderBy('sort')
            ->first();

        if ($rule === null) {
            $source = $this->coreSourceOrFail($snapshot);
            $sourceFieldRuleId = $this->allocateE2ESourceFieldRuleId($snapshot);
            $dedupe = 'e2e-corrupt-'.uniqid('', true);
            ConfigurationSnapshotSourceRule::query()->create([
                'configuration_snapshot_source_id' => $source->id,
                'source_field_rule_id' => $sourceFieldRuleId,
                'sort' => 999,
                'condition_json' => $corruptCondition,
                'action_json' => $corruptAction,
                'dedupe_key' => $dedupe,
            ]);
            $rule = SnapshotFieldRule::query()->create([
                'configuration_snapshot_id' => $snapshot->id,
                'sort' => 999,
                'condition_json' => $corruptCondition,
                'action_json' => $corruptAction,
                'dedupe_key' => $dedupe,
                'provenance_source_id' => $source->id,
                'source_field_rule_id' => $sourceFieldRuleId,
            ]);
        } else {
            $oldDedupe = (string) $rule->dedupe_key;
            $newDedupe = 'e2e-corrupt-'.uniqid('', true);
            $rule->condition_json = $corruptCondition;
            $rule->action_json = $corruptAction;
            $rule->dedupe_key = $newDedupe;
            $rule->save();

            if ($rule->provenance_source_id !== null) {
                $sourceRule = ConfigurationSnapshotSourceRule::query()
                    ->where('configuration_snapshot_source_id', $rule->provenance_source_id)
                    ->where(function ($query) use ($oldDedupe, $rule): void {
                        $query->where('dedupe_key', $oldDedupe);
                        if ($rule->source_field_rule_id !== null) {
                            $query->orWhere('source_field_rule_id', $rule->source_field_rule_id);
                        }
                    })
                    ->first();
                if ($sourceRule !== null) {
                    $sourceRule->condition_json = $corruptCondition;
                    $sourceRule->action_json = $corruptAction;
                    $sourceRule->dedupe_key = $newDedupe;
                    $sourceRule->save();
                }
            }
        }

        return response()->json(['ok' => true, 'rule_id' => $rule->id]);
    }

    public function textValue(Request $request): JsonResponse
    {
        $this->assertE2E();
        $this->assertAuthenticated($request);

        $validated = $request->validate([
            'calculation_id' => ['required_without:dispo_order_id', 'nullable', 'integer'],
            'dispo_order_id' => ['required_without:calculation_id', 'nullable', 'integer'],
            'field_key' => ['required', 'string'],
        ]);

        if (! empty($validated['dispo_order_id'])) {
            $order = $this->dispoOrderOrFail((int) $validated['dispo_order_id']);
            $snap = $this->resolveDispoSnapshotField($order, $validated['field_key'], null);
            $row = DispoOrderFieldValue::query()
                ->where('dispo_order_id', $order->id)
                ->where('snapshot_field_definition_id', $snap->id)
                ->first();

            return response()->json([
                'exists' => $row !== null,
                'value' => $row === null ? null : ($row->value_string ?? $row->value_text),
            ]);
        }

        $calculation = $this->calculationOrFail((int) $validated['calculation_id']);
        $snap = $this->resolveSnapshotField($calculation, $validated['field_key'], null);
        $row = CalculationFieldValue::query()
            ->where('calculation_id', $calculation->id)
            ->where('snapshot_field_definition_id', $snap->id)
            ->first();

        return response()->json([
            'exists' => $row !== null,
            'value' => $row === null ? null : ($row->value_string ?? $row->value_text),
        ]);
    }

    private function coreSourceOrFail(ConfigurationSnapshot $snapshot): ConfigurationSnapshotSource
    {
        $snapshot->loadMissing('sources');
        $source = $snapshot->sources->firstWhere('role', ConfigurationSnapshotSource::ROLE_CORE);
        if (! $source instanceof ConfigurationSnapshotSource) {
            throw new RuntimeException('E2E: Core-Source fehlt am Snapshot.');
        }

        return $source;
    }

    private function allocateE2ESourceFieldRuleId(ConfigurationSnapshot $snapshot): int
    {
        $maxSnapshot = (int) SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->max('source_field_rule_id');
        $sourceIds = $snapshot->sources()->pluck('id');
        $maxSource = (int) ConfigurationSnapshotSourceRule::query()
            ->whereIn('configuration_snapshot_source_id', $sourceIds)
            ->max('source_field_rule_id');

        return max($maxSnapshot, $maxSource, 900_000) + 1;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveTargetSnapshot(array $validated): ConfigurationSnapshot
    {
        if (! empty($validated['dispo_order_id'])) {
            $order = $this->dispoOrderOrFail((int) $validated['dispo_order_id']);

            return ConfigurationSnapshot::query()->findOrFail($order->configuration_snapshot_id);
        }

        $calculation = $this->calculationOrFail((int) $validated['calculation_id']);

        return ConfigurationSnapshot::query()->findOrFail($calculation->configuration_snapshot_id);
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
