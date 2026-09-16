<?php

namespace App\Http\Requests\Calculation;

use App\Enums\BudgetProposalStatus;
use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\DiscountType;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\PlanningMode;
use App\Enums\SpotCalculationMethod;
use App\Exceptions\FieldSetAssignmentConflictException;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\ConfigurationSnapshot;
use App\Models\SnapshotFieldDefinition;
use App\Services\Calculation\DiscountValidator;
use App\Services\Calculation\PlannerEntryValidator;
use App\Services\Calculation\TimeRangeValidator;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\DynamicField\ConfigurationSnapshotIntegrity;
use App\Support\Calculation\CalculationPositionMethodKeyNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class CalculationPayloadRequest extends FormRequest
{
    /** @var array<string, mixed>|null */
    private ?array $liveSchemaCache = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * DF-3.3a2α: Schema-Drift schlägt beim Anlegen als 409 durch, noch bevor
     * dynamische Feldwerte gegen das Schema geprüft werden (409 vor 422).
     * Formal ungültige Fingerprints bleiben der Regel-Validierung (422) überlassen.
     *
     * DF-3.3a2β: dieselbe Reihenfolge gilt für Basis- und Positions-Fingerprints
     * bei Create und normalen Gen-3-Updates (gegen den eingefrorenen Basissnapshot).
     */
    protected function prepareForValidation(): void
    {
        if ($this->routeIs('calculations.store')) {
            $this->assertLiveFingerprintDriftOrDefer();

            return;
        }

        if ($this->routeIs('calculations.update')) {
            $this->assertGen3UpdateFingerprintDriftOrDefer();
        }
    }

    private function assertLiveFingerprintDriftOrDefer(): void
    {
        $expected = $this->input('schema_fingerprint');
        if (! ConfigurationSnapshotIntegrity::isValidFingerprint($expected)) {
            return;
        }

        if (! hash_equals((string) $this->liveSchema()['schema_fingerprint'], $expected)) {
            throw new FieldSetAssignmentConflictException(
                'Die Feldkonfiguration hat sich geändert. Bitte neu laden und erneut speichern.',
            );
        }

        $freeze = app(ConfigurationSnapshotFreezeService::class);
        foreach ($this->input('positions', []) as $index => $position) {
            if (! is_array($position)) {
                continue;
            }

            $fingerprint = $position['schema_fingerprint'] ?? null;
            if (! ConfigurationSnapshotIntegrity::isValidFingerprint($fingerprint)) {
                continue;
            }

            $mediumId = (int) ($position['advertising_medium_id'] ?? 0);
            if ($mediumId < 1) {
                continue;
            }

            $actual = (string) $freeze->resolveLivePositionSchema($mediumId)['schema_fingerprint'];
            if (! hash_equals((string) $fingerprint, $actual)) {
                throw new FieldSetAssignmentConflictException(
                    'Die Feldkonfiguration hat sich geändert. Bitte neu laden und erneut speichern.',
                );
            }
        }
    }

    private function assertGen3UpdateFingerprintDriftOrDefer(): void
    {
        $base = $this->gen3BaseSnapshotForUpdate();
        if ($base === null) {
            return;
        }

        $expectedBase = $this->input('schema_fingerprint');
        if (ConfigurationSnapshotIntegrity::isValidFingerprint($expectedBase)
            && ! hash_equals((string) $base->schema_fingerprint, (string) $expectedBase)
        ) {
            throw new FieldSetAssignmentConflictException(
                'Die Feldkonfiguration hat sich geändert. Bitte neu laden und erneut speichern.',
            );
        }

        $freeze = app(ConfigurationSnapshotFreezeService::class);
        foreach ($this->input('positions', []) as $position) {
            if (! is_array($position)) {
                continue;
            }

            $mediumId = (int) ($position['advertising_medium_id'] ?? 0);
            if ($mediumId < 1) {
                continue;
            }

            $fingerprint = $position['schema_fingerprint'] ?? null;
            if (! ConfigurationSnapshotIntegrity::isValidFingerprint($fingerprint)) {
                continue;
            }

            $actual = (string) $freeze
                ->resolvePositionSchemaFromBase($base, $mediumId)['schema_fingerprint'];
            if (! hash_equals((string) $fingerprint, $actual)) {
                throw new FieldSetAssignmentConflictException(
                    'Die Feldkonfiguration hat sich geändert. Bitte neu laden und erneut speichern.',
                );
            }
        }
    }

    private function gen3BaseSnapshotForUpdate(): ?ConfigurationSnapshot
    {
        if (! $this->routeIs('calculations.update')) {
            return null;
        }

        $calculation = $this->route('calculation');
        if (! $calculation instanceof Calculation) {
            return null;
        }

        $calculation->loadMissing('configurationSnapshot');
        $base = $calculation->configurationSnapshot;
        if ($base === null
            || (int) $base->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE
        ) {
            return null;
        }

        return $base;
    }

    private function requiresGen3UpdateBaseFingerprint(): bool
    {
        return $this->gen3BaseSnapshotForUpdate() !== null;
    }

    private function requiresGen3UpdatePositionFingerprints(): bool
    {
        return $this->gen3BaseSnapshotForUpdate() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planning_mode' => ['required', Rule::enum(PlanningMode::class)],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'agency_name' => ['nullable', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:255'],
            'product_title' => ['nullable', 'string', 'max:255'],
            'briefing' => ['nullable', 'string', 'max:20000'],
            'order_discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'order_discounts' => ['sometimes', 'array'],
            'order_discounts.*.type' => ['nullable', Rule::enum(DiscountType::class)],
            'order_discounts.*.custom_label' => ['nullable', 'string', 'max:120'],
            'order_discounts.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ae_enabled' => ['sometimes', 'boolean'],
            'target_budget_nn' => ['nullable', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'budget_strategy' => ['nullable', Rule::enum(BudgetStrategy::class)],
            'budget_wish_inventory_ids' => ['sometimes', 'array'],
            'budget_wish_inventory_ids.*' => ['integer', 'min:1'],
            'budget_elements' => ['sometimes', 'array'],
            'budget_elements.*.client_id' => ['nullable', 'string', 'max:120'],
            'budget_elements.*.inventory_id' => ['nullable', 'integer', 'min:1'],
            'budget_elements.*.spot_length_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'budget_elements.*.distribution_ranges' => ['sometimes', 'array'],
            'budget_elements.*.distribution_ranges.*.start_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'budget_elements.*.distribution_ranges.*.end_hour_exclusive' => ['nullable', 'integer', 'min:1', 'max:24'],
            'budget_elements.*.distribution_ranges.*.day_group' => ['nullable', Rule::enum(DayGroup::class)],
            'budget_elements.*.position_discounts' => ['sometimes', 'array'],
            'budget_spot_length_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'budget_distribution_ranges' => ['sometimes', 'array'],
            'budget_distribution_ranges.*.start_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'budget_distribution_ranges.*.end_hour_exclusive' => ['nullable', 'integer', 'min:1', 'max:24'],
            'budget_distribution_ranges.*.day_group' => ['nullable', Rule::enum(DayGroup::class)],
            'budget_position_discounts_by_inventory' => ['sometimes', 'array'],
            'budget_position_discounts_by_inventory.*.inventory_id' => ['required', 'integer', 'min:1'],
            'budget_position_discounts_by_inventory.*.discounts' => ['sometimes', 'array'],
            'budget_position_discounts_by_inventory.*.discounts.*.type' => ['nullable', Rule::enum(DiscountType::class)],
            'budget_position_discounts_by_inventory.*.discounts.*.custom_label' => ['nullable', 'string', 'max:120'],
            'budget_position_discounts_by_inventory.*.discounts.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'budget_proposal_manual' => ['sometimes', 'boolean'],
            'budget_proposal_status' => ['nullable', Rule::enum(BudgetProposalStatus::class)],
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'calculation_id' => ['nullable', 'integer', 'min:1'],
            'schema_fingerprint' => $this->routeIs('calculations.store') || $this->requiresGen3UpdateBaseFingerprint()
                ? ['required', 'string', 'size:64', 'regex:'.ConfigurationSnapshotIntegrity::FINGERPRINT_PATTERN]
                : ['nullable', 'string', 'max:64'],
            'dynamic_field_values' => ['sometimes', 'array'],
            'dynamic_field_values.campaign_period' => ['nullable', 'array'],
            'dynamic_field_values.campaign_period.start' => ['nullable', 'date'],
            'dynamic_field_values.campaign_period.end' => ['nullable', 'date'],
            'dynamic_field_values.*' => ['nullable'],
            'positions' => ['sometimes', 'array'],
            'positions.*.id' => ['nullable', 'integer', 'min:1'],
            'positions.*.client_key' => ['nullable', 'uuid'],
            'positions.*.schema_fingerprint' => $this->routeIs('calculations.store') || $this->requiresGen3UpdatePositionFingerprints()
                ? ['required', 'string', 'size:64', 'regex:'.ConfigurationSnapshotIntegrity::FINGERPRINT_PATTERN]
                : ['nullable', 'string', 'size:64', 'regex:'.ConfigurationSnapshotIntegrity::FINGERPRINT_PATTERN],
            'positions.*.inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'positions.*.advertising_medium_id' => ['required', 'integer', 'exists:advertising_media,id'],
            'positions.*.price_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'positions.*.expected_price_list_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'positions.*.price_list_id' => ['prohibited'],
            'positions.*.price_list_version' => ['prohibited'],
            'positions.*.spot_method' => ['nullable', Rule::enum(SpotCalculationMethod::class)],
            'positions.*.calculation_method_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'positions.*.engine_profile_key' => ['prohibited'],
            'positions.*.calculation_method_name' => ['prohibited'],
            'positions.*.algorithm_version' => ['prohibited'],
            'positions.*.length_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'positions.*.total_spot_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'positions.*.position_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'positions.*.ae_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'positions.*.plan_rows' => ['sometimes', 'array'],
            'positions.*.plan_rows.*.hour' => ['required', 'integer', 'min:0', 'max:23'],
            'positions.*.plan_rows.*.day_group' => ['required', Rule::enum(DayGroup::class)],
            'positions.*.time_ranges' => ['sometimes', 'array'],
            'positions.*.time_ranges.*.start_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'positions.*.time_ranges.*.end_hour_exclusive' => ['nullable', 'integer', 'min:1', 'max:24'],
            'positions.*.time_ranges.*.day_group' => ['nullable', Rule::enum(DayGroup::class)],
            'positions.*.time_ranges.*.spot_count' => ['nullable'],
            'positions.*.planner_entries' => ['sometimes', 'array'],
            'positions.*.planner_entries.*.date' => ['nullable', 'date_format:Y-m-d'],
            'positions.*.planner_entries.*.hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'positions.*.planner_entries.*.spot_count' => ['nullable'],
            'positions.*.position_discounts' => ['sometimes', 'array'],
            'positions.*.position_discounts.*.type' => ['nullable', Rule::enum(DiscountType::class)],
            'positions.*.position_discounts.*.custom_label' => ['nullable', 'string', 'max:120'],
            'positions.*.position_discounts.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'positions.*.dynamic_field_values' => ['sometimes', 'array'],
            'positions.*.dynamic_field_values.period_open' => ['sometimes', 'boolean'],
            'positions.*.dynamic_field_values.position_flight_period' => ['nullable', 'array'],
            'positions.*.dynamic_field_values.position_flight_period.start' => ['nullable', 'date'],
            'positions.*.dynamic_field_values.position_flight_period.end' => ['nullable', 'date'],
            'positions.*.dynamic_field_values.*' => ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (array) trans('validation.attributes', [], 'de');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'budget_elements.*.inventory_id.min' => 'Bitte wähle einen Sender aus.',
            'target_budget_nn.min' => 'Das Zielbudget muss größer als 0 sein.',
            'dynamic_field_values.campaign_period.start.date' => 'Kampagnenbeginn ist kein gültiges Datum.',
            'dynamic_field_values.campaign_period.end.date' => 'Kampagnenende ist kein gültiges Datum.',
            'positions.*.dynamic_field_values.position_flight_period.start.date' => 'Flugzeitraum-Beginn ist kein gültiges Datum.',
            'positions.*.dynamic_field_values.position_flight_period.end.date' => 'Flugzeitraum-Ende ist kein gültiges Datum.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateDynamicPeriods($validator);
            $this->validateDynamicFieldKeys($validator);
            $this->validatePositionMethodKeys($validator);
            // PO-32b-1: leere Custom-Pflichtfelder blockieren Calc Create/Update
            // nicht allein deshalb; Snapshot-Regeln/Typvalidierung bleiben aktiv.

            $isPreview = $this->routeIs('calculations.preview');
            $rangeValidator = new TimeRangeValidator;
            $plannerValidator = new PlannerEntryValidator;
            $methodNormalizer = app(CalculationPositionMethodKeyNormalizer::class);
            $discountValidator = new DiscountValidator;

            try {
                $discountValidator->validated($this->input('order_discounts', []), 'order_discounts');
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            }

            $budgetRanges = $this->input('budget_distribution_ranges', []);
            if (is_array($budgetRanges) && $budgetRanges !== []) {
                try {
                    $rangeValidator->validated(
                        $budgetRanges,
                        'budget_distribution_ranges',
                        requireAtLeastOne: false,
                        requireSpotCount: false,
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                }
            }

            $isBudgetMode = $this->input('planning_mode') === PlanningMode::Budget->value;
            $positions = $this->input('positions', []);

            foreach ($this->input('budget_position_discounts_by_inventory', []) as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                try {
                    $discountValidator->validated(
                        $row['discounts'] ?? [],
                        "budget_position_discounts_by_inventory.{$index}.discounts",
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                }
            }

            if (! $isPreview && $isBudgetMode) {
                $targetBudget = $this->input('target_budget_nn');
                if ($targetBudget === null || $targetBudget === '' || (float) $targetBudget <= 0) {
                    $validator->errors()->add(
                        'target_budget_nn',
                        'Das Zielbudget muss größer als 0 sein.',
                    );
                }
            }

            foreach ($positions as $index => $position) {
                if (! is_array($position)) {
                    continue;
                }

                $methodKey = 'average';
                try {
                    $normalized = $methodNormalizer->normalize($position);
                    $methodKey = $normalized['present'] ? (string) $normalized['key'] : 'average';
                } catch (ValidationException) {
                    continue;
                }

                $isCalendar = $methodKey === SpotCalculationMethod::Calendar->value;
                $ranges = $position['time_ranges'] ?? [];
                $planRows = $position['plan_rows'] ?? [];
                $plannerEntries = $position['planner_entries'] ?? [];

                if ($isCalendar) {
                    if (is_array($plannerEntries) && $plannerEntries !== []) {
                        try {
                            $plannerValidator->validated(
                                $plannerEntries,
                                "positions.{$index}.planner_entries",
                                requireAtLeastOne: ! $isPreview && ! $isBudgetMode,
                            );
                        } catch (ValidationException $exception) {
                            foreach ($exception->errors() as $key => $messages) {
                                foreach ($messages as $message) {
                                    $validator->errors()->add($key, $message);
                                }
                            }
                        }
                    } elseif (! $isPreview && ! $isBudgetMode) {
                        $validator->errors()->add(
                            "positions.{$index}.planner_entries",
                            'Mindestens ein vollständiger Kalendereintrag mit mindestens einem Spot ist erforderlich.',
                        );
                    }

                    if (is_array($ranges) && $ranges !== []) {
                        $validator->errors()->add(
                            "positions.{$index}.time_ranges",
                            'Preiszeiträume sind für den Kalenderplaner nicht zulässig.',
                        );
                    }
                } else {
                    if (is_array($plannerEntries) && $plannerEntries !== []) {
                        $validator->errors()->add(
                            "positions.{$index}.planner_entries",
                            'Kalendereinträge sind für die Durchschnittsplanung nicht zulässig.',
                        );
                    }

                    if (is_array($ranges) && $ranges !== []) {
                        try {
                            $rangeValidator->validated(
                                $ranges,
                                "positions.{$index}.time_ranges",
                                requireAtLeastOne: ! $isPreview,
                            );
                        } catch (ValidationException $exception) {
                            foreach ($exception->errors() as $key => $messages) {
                                foreach ($messages as $message) {
                                    $validator->errors()->add($key, $message);
                                }
                            }
                        }
                    } elseif (! $isPreview && ! $isBudgetMode && (! is_array($planRows) || $planRows === [])) {
                        $validator->errors()->add(
                            "positions.{$index}.time_ranges",
                            'Mindestens ein vollständiger Preiszeitraum mit mindestens einem Spot ist erforderlich.',
                        );
                    }
                }

                try {
                    $discountValidator->validated(
                        $position['position_discounts'] ?? [],
                        "positions.{$index}.position_discounts",
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                }
            }
        });
    }

    /**
     * ADV-001c4a: presence-aware calculation_method_key / spot_method-Normalisierung.
     * Fehlend ≠ explizit null/leer; widersprüchliche Alias-Paare → 422.
     */
    private function validatePositionMethodKeys(Validator $validator): void
    {
        $normalizer = app(CalculationPositionMethodKeyNormalizer::class);

        foreach ($this->input('positions', []) as $index => $position) {
            if (! is_array($position)) {
                continue;
            }

            try {
                $normalizer->normalize($position);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add("positions.{$index}.calculation_method_key", $message);
                    }
                }
            }
        }
    }

    private function validateDynamicFieldKeys(Validator $validator): void
    {
        $snapshot = $this->resolveConfigurationSnapshot();
        if ($snapshot !== null
            && (int) $snapshot->format_version === ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE
        ) {
            $this->validateDynamicFieldKeysForContextualFreeze($validator, $snapshot);

            return;
        }

        // Gen-3-Neuanlage / Preview ohne Snapshot: Header gegen Live-Basis,
        // Positionsfelder gegen das Live-Positionsschema je Werbemittel.
        if ($snapshot === null) {
            $this->validateDynamicFieldKeysForLiveContextualFreeze($validator);

            return;
        }

        $schema = $this->resolveDynamicFieldSchema();
        $headerAllowed = $schema['header'];
        $positionAllowed = $schema['position'];

        $header = $this->input('dynamic_field_values', []);
        if (is_array($header)) {
            foreach ($header as $key => $raw) {
                $key = (string) $key;
                if (! isset($headerAllowed[$key])) {
                    $validator->errors()->add(
                        'dynamic_field_values.'.$key,
                        isset($positionAllowed[$key])
                            ? 'Dieses Feld gehört nicht in diesen Bereich.'
                            : 'Unbekanntes dynamisches Feld.',
                    );

                    continue;
                }

                $this->validateTextValue(
                    $validator,
                    'dynamic_field_values.'.$key,
                    $raw,
                    $headerAllowed[$key],
                );
            }
        }

        foreach ($this->input('positions', []) as $index => $position) {
            if (! is_array($position)) {
                continue;
            }
            $values = $position['dynamic_field_values'] ?? null;
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $key => $raw) {
                $key = (string) $key;
                if (! isset($positionAllowed[$key])) {
                    $validator->errors()->add(
                        "positions.{$index}.dynamic_field_values.{$key}",
                        isset($headerAllowed[$key])
                            ? 'Dieses Feld gehört nicht in diesen Bereich.'
                            : 'Unbekanntes dynamisches Feld.',
                    );

                    continue;
                }

                $this->validateTextValue(
                    $validator,
                    "positions.{$index}.dynamic_field_values.{$key}",
                    $raw,
                    $positionAllowed[$key],
                );
            }
        }
    }

    /**
     * Live Gen 3 ohne persistierte Basis: Header = Core + global,
     * Position = Primary-Core → global → Oberkategorie → Werbemittel.
     */
    private function validateDynamicFieldKeysForLiveContextualFreeze(Validator $validator): void
    {
        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $baseSchema = $this->schemaFromLiveFreezeSchema();
        $headerAllowed = $baseSchema['header'];
        $unionPositionAllowed = [];
        /** @var array<int, array<string, array{field_type: string, max_length: int, label: string, required: bool, visible: bool}>> $positionSchemaByMedium */
        $positionSchemaByMedium = [];

        $header = $this->input('dynamic_field_values', []);
        if (is_array($header)) {
            foreach ($header as $key => $raw) {
                $key = (string) $key;
                if (! isset($headerAllowed[$key])) {
                    $validator->errors()->add(
                        'dynamic_field_values.'.$key,
                        'Unbekanntes dynamisches Feld.',
                    );

                    continue;
                }

                $this->validateTextValue(
                    $validator,
                    'dynamic_field_values.'.$key,
                    $raw,
                    $headerAllowed[$key],
                );
            }
        }

        foreach ($this->input('positions', []) as $index => $position) {
            if (! is_array($position)) {
                continue;
            }

            $mediumId = (int) ($position['advertising_medium_id'] ?? 0);
            if ($mediumId > 0) {
                if (! isset($positionSchemaByMedium[$mediumId])) {
                    $resolved = $freeze->resolveLivePositionSchema($mediumId);
                    $positionSchemaByMedium[$mediumId] = $this->positionMetaFromResolvedFields(
                        $resolved['fields'],
                    );
                }
                $positionAllowed = $positionSchemaByMedium[$mediumId];
            } else {
                $positionAllowed = $baseSchema['position'];
            }

            foreach ($positionAllowed as $key => $meta) {
                $unionPositionAllowed[$key] = $meta;
            }

            $values = $position['dynamic_field_values'] ?? null;
            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $key => $raw) {
                $key = (string) $key;
                if (! isset($positionAllowed[$key])) {
                    $validator->errors()->add(
                        "positions.{$index}.dynamic_field_values.{$key}",
                        isset($headerAllowed[$key])
                            ? 'Dieses Feld gehört nicht in diesen Bereich.'
                            : 'Unbekanntes dynamisches Feld.',
                    );

                    continue;
                }

                $this->validateTextValue(
                    $validator,
                    "positions.{$index}.dynamic_field_values.{$key}",
                    $raw,
                    $positionAllowed[$key],
                );
            }
        }

        if (is_array($header)) {
            foreach ($header as $key => $raw) {
                $key = (string) $key;
                if (! isset($headerAllowed[$key]) && isset($unionPositionAllowed[$key])) {
                    $validator->errors()->add(
                        'dynamic_field_values.'.$key,
                        'Dieses Feld gehört nicht in diesen Bereich.',
                    );
                }
            }
        }
    }

    /**
     * Generation 3: Header gegen die Basis, Positionsfelder gegen den
     * jeweiligen Effektiv- bzw. aus der Basis abgeleiteten Kontext.
     */
    private function validateDynamicFieldKeysForContextualFreeze(
        Validator $validator,
        ConfigurationSnapshot $base,
    ): void {
        $schema = $this->schemaFromSnapshot($base);
        $headerAllowed = $schema['header'];
        $unionPositionAllowed = [];

        $header = $this->input('dynamic_field_values', []);
        if (is_array($header)) {
            foreach ($header as $key => $raw) {
                $key = (string) $key;
                if (! isset($headerAllowed[$key])) {
                    $validator->errors()->add(
                        'dynamic_field_values.'.$key,
                        'Unbekanntes dynamisches Feld.',
                    );

                    continue;
                }

                $this->validateTextValue(
                    $validator,
                    'dynamic_field_values.'.$key,
                    $raw,
                    $headerAllowed[$key],
                );
            }
        }

        $calculation = $this->route('calculation');
        $positionsById = collect();
        if ($calculation instanceof Calculation) {
            $calculation->loadMissing('positions.effectiveConfigurationSnapshot.fieldDefinitions');
            $positionsById = $calculation->positions->keyBy('id');
        }

        $freeze = app(ConfigurationSnapshotFreezeService::class);

        foreach ($this->input('positions', []) as $index => $position) {
            if (! is_array($position)) {
                continue;
            }

            $positionAllowed = $this->positionSchemaForContextualPayload(
                $freeze,
                $base,
                $positionsById,
                $position,
            );
            foreach ($positionAllowed as $key => $meta) {
                $unionPositionAllowed[$key] = $meta;
            }

            $values = $position['dynamic_field_values'] ?? null;
            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $key => $raw) {
                $key = (string) $key;
                if (! isset($positionAllowed[$key])) {
                    $validator->errors()->add(
                        "positions.{$index}.dynamic_field_values.{$key}",
                        isset($headerAllowed[$key])
                            ? 'Dieses Feld gehört nicht in diesen Bereich.'
                            : 'Unbekanntes dynamisches Feld.',
                    );

                    continue;
                }

                $this->validateTextValue(
                    $validator,
                    "positions.{$index}.dynamic_field_values.{$key}",
                    $raw,
                    $positionAllowed[$key],
                );
            }
        }

        // Unbekannte Header-Keys, die nur in Positions-Schemas existieren
        if (is_array($header)) {
            foreach ($header as $key => $raw) {
                $key = (string) $key;
                if (! isset($headerAllowed[$key]) && isset($unionPositionAllowed[$key])) {
                    $validator->errors()->add(
                        'dynamic_field_values.'.$key,
                        'Dieses Feld gehört nicht in diesen Bereich.',
                    );
                }
            }
        }
    }

    /**
     * @param  Collection<int|string, CalculationPosition>  $positionsById
     * @param  array<string, mixed>  $position
     * @return array<string, array{field_type: string, max_length: int, label: string, required: bool, visible: bool}>
     */
    private function positionSchemaForContextualPayload(
        ConfigurationSnapshotFreezeService $freeze,
        ConfigurationSnapshot $base,
        $positionsById,
        array $position,
    ): array {
        $existingId = isset($position['id']) ? (int) $position['id'] : 0;
        $existing = $existingId > 0 ? $positionsById->get($existingId) : null;
        $mediumId = (int) ($position['advertising_medium_id'] ?? 0);

        if ($existing !== null
            && $existing->effective_configuration_snapshot_id !== null
            && ($mediumId < 1 || $mediumId === (int) $existing->advertising_medium_id)
        ) {
            $existing->loadMissing('effectiveConfigurationSnapshot.fieldDefinitions');
            $effective = $existing->effectiveConfigurationSnapshot;
            if ($effective !== null) {
                return $this->schemaFromSnapshot($effective)['position'];
            }
        }

        if ($mediumId > 0) {
            $resolved = $freeze->resolvePositionSchemaFromBase($base, $mediumId);

            return $this->positionMetaFromResolvedFields($resolved['fields']);
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, array{field_type: string, max_length: int, label: string, required: bool, visible: bool}>
     */
    private function positionMetaFromResolvedFields(array $fields): array
    {
        $position = [];
        foreach ($fields as $field) {
            if (FieldScope::from((string) $field['field_scope']) !== FieldScope::Position) {
                continue;
            }
            $fieldType = (string) $field['field_type'];
            $position[(string) $field['field_key']] = [
                'field_type' => $fieldType,
                'max_length' => $this->maxLengthFromValidation($field['validation_json'] ?? null, $fieldType),
                'label' => (string) $field['label'],
                'required' => (bool) $field['effective_required'],
                'visible' => (bool) $field['effective_visible'],
            ];
        }

        return $position;
    }

    /**
     * @param  array{field_type: string, max_length: int, label: string, required?: bool, visible?: bool}  $meta
     */
    private function validateTextValue(Validator $validator, string $errorKey, mixed $raw, array $meta): void
    {
        if (! in_array($meta['field_type'], [FieldType::ShortText->value, FieldType::LongText->value], true)) {
            return;
        }

        if ($raw === null || $raw === '') {
            return;
        }

        if (! is_string($raw) && ! is_numeric($raw)) {
            $validator->errors()->add(
                $errorKey,
                $meta['label'].' muss Text sein.',
            );

            return;
        }

        $value = (string) $raw;
        if (mb_strlen($value) > $meta['max_length']) {
            $validator->errors()->add(
                $errorKey,
                $meta['label'].' darf höchstens '.$meta['max_length'].' Zeichen haben.',
            );
        }
    }

    /**
     * @return array{
     *     header: array<string, array{field_type: string, max_length: int, label: string, required: bool, visible: bool}>,
     *     position: array<string, array{field_type: string, max_length: int, label: string, required: bool, visible: bool}>
     * }
     */
    private function resolveDynamicFieldSchema(): array
    {
        $snapshot = $this->resolveConfigurationSnapshot();
        if ($snapshot !== null) {
            return $this->schemaFromSnapshot($snapshot);
        }

        return $this->schemaFromLiveFreezeSchema();
    }

    private function resolveConfigurationSnapshot(): ?ConfigurationSnapshot
    {
        /** @var Calculation|null $routeCalculation */
        $routeCalculation = $this->route('calculation');
        if ($routeCalculation instanceof Calculation) {
            $routeCalculation->loadMissing('configurationSnapshot.fieldDefinitions');

            return $routeCalculation->configurationSnapshot;
        }

        $calculationId = (int) $this->input('calculation_id', 0);
        if ($calculationId > 0) {
            $calculation = Calculation::query()
                ->with('configurationSnapshot.fieldDefinitions')
                ->find($calculationId);

            return $calculation?->configurationSnapshot;
        }

        return null;
    }

    /**
     * @return array{
     *     header: array<string, array{field_type: string, max_length: int, label: string, required: bool, visible: bool}>,
     *     position: array<string, array{field_type: string, max_length: int, label: string, required: bool, visible: bool}>
     * }
     */
    private function schemaFromSnapshot(ConfigurationSnapshot $snapshot): array
    {
        $snapshot->assertReadable();
        $snapshot->loadMissing('fieldDefinitions');
        $header = [];
        $position = [];
        foreach ($snapshot->fieldDefinitions as $def) {
            $meta = [
                'field_type' => $def->field_type->value,
                'max_length' => $this->maxLengthForDefinition($def),
                'label' => $def->label,
                'required' => (bool) $def->required,
                'visible' => (bool) $def->visible,
            ];
            if ($def->scope === FieldScope::Header) {
                $header[$def->key] = $meta;
            } elseif ($def->scope === FieldScope::Position) {
                $position[$def->key] = $meta;
            }
        }

        return ['header' => $header, 'position' => $position];
    }

    /**
     * Neuanlage: Core + aktive globale Assignments, damit neu zugewiesene
     * globale Felder sofort erlaubt sind.
     *
     * @return array{
     *     header: array<string, array{field_type: string, max_length: int, label: string, required: bool, visible: bool}>,
     *     position: array<string, array{field_type: string, max_length: int, label: string, required: bool, visible: bool}>
     * }
     */
    private function schemaFromLiveFreezeSchema(): array
    {
        $header = [];
        $position = [];

        foreach ($this->liveSchema()['fields'] as $field) {
            $fieldType = (string) $field['field_type'];
            $meta = [
                'field_type' => $fieldType,
                'max_length' => $this->maxLengthFromValidation($field['validation_json'] ?? null, $fieldType),
                'label' => (string) $field['label'],
                'required' => (bool) $field['effective_required'],
                'visible' => (bool) $field['effective_visible'],
            ];

            match (FieldScope::from((string) $field['field_scope'])) {
                FieldScope::Header => $header[(string) $field['field_key']] = $meta,
                FieldScope::Position => $position[(string) $field['field_key']] = $meta,
            };
        }

        return ['header' => $header, 'position' => $position];
    }

    /**
     * @return array<string, mixed>
     */
    private function liveSchema(): array
    {
        // DF-3.3a2β: Create zielt auf Generation 3 (Universum + Header-Basis).
        return $this->liveSchemaCache ??= app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3();
    }

    private function maxLengthForDefinition(SnapshotFieldDefinition $def): int
    {
        return $this->maxLengthFromValidation($def->validation_json, $def->field_type->value);
    }

    /**
     * @param  array<string, mixed>|null  $validation
     */
    private function maxLengthFromValidation(?array $validation, string $fieldType): int
    {
        $fromJson = is_array($validation) ? ($validation['max_length'] ?? null) : null;
        if (is_numeric($fromJson)) {
            return (int) $fromJson;
        }

        return $fieldType === FieldType::ShortText->value ? 255 : 20000;
    }

    private function validateDynamicPeriods(Validator $validator): void
    {
        $campaign = $this->input('dynamic_field_values.campaign_period');
        $campaignError = $this->periodCompletenessError($campaign);
        if ($campaignError !== null) {
            $validator->errors()->add('dynamic_field_values.campaign_period', $campaignError);
        }

        foreach ($this->input('positions', []) as $index => $position) {
            if (! is_array($position)) {
                continue;
            }
            $flight = $position['dynamic_field_values']['position_flight_period'] ?? null;
            $flightError = $this->periodCompletenessError($flight);
            if ($flightError !== null) {
                $validator->errors()->add(
                    "positions.{$index}.dynamic_field_values.position_flight_period",
                    $flightError,
                );
            }
        }
    }

    private function periodCompletenessError(mixed $period): ?string
    {
        if ($period === null || $period === '') {
            return null;
        }

        if (! is_array($period)) {
            return 'Zeitraum muss Start und Ende enthalten.';
        }

        $start = $period['start'] ?? null;
        $end = $period['end'] ?? null;
        $start = $start === '' ? null : $start;
        $end = $end === '' ? null : $end;

        if ($start === null && $end === null) {
            return null;
        }

        if ($start === null || $end === null) {
            return 'Zeitraum muss vollständig mit Beginn und Ende angegeben werden.';
        }

        if (strtotime((string) $start) === false || strtotime((string) $end) === false) {
            return 'Zeitraum enthält ungültige Datumsangaben.';
        }

        if (strtotime((string) $start) > strtotime((string) $end)) {
            return 'Der Zeitraumbeginn darf nicht nach dem Ende liegen.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = $this->validated();

        if ($this->exists('ae_enabled')) {
            $payload['ae_enabled'] = $this->boolean('ae_enabled');
        }

        if ($this->exists('budget_proposal_manual')) {
            $payload['budget_proposal_manual'] = $this->boolean('budget_proposal_manual');
        }

        return $payload;
    }
}
