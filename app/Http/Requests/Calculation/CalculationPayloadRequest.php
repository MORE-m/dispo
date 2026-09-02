<?php

namespace App\Http\Requests\Calculation;

use App\Enums\BudgetProposalStatus;
use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\DiscountType;
use App\Enums\PlanningMode;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\DiscountValidator;
use App\Services\Calculation\TimeRangeValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class CalculationPayloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'target_budget_nn' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
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
            'positions' => ['sometimes', 'array'],
            'positions.*.id' => ['nullable', 'integer', 'min:1'],
            'positions.*.client_key' => ['nullable', 'uuid'],
            'positions.*.inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'positions.*.advertising_medium_id' => ['required', 'integer', 'exists:advertising_media,id'],
            'positions.*.spot_method' => ['nullable', Rule::enum(SpotCalculationMethod::class)],
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
            'positions.*.position_discounts' => ['sometimes', 'array'],
            'positions.*.position_discounts.*.type' => ['nullable', Rule::enum(DiscountType::class)],
            'positions.*.position_discounts.*.custom_label' => ['nullable', 'string', 'max:120'],
            'positions.*.position_discounts.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $isPreview = $this->routeIs('calculations.preview');
            $rangeValidator = new TimeRangeValidator;
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
                        'Zielbudget N/N ist im Budgetmodus erforderlich und muss größer als 0 sein.',
                    );
                }
            }

            foreach ($positions as $index => $position) {
                $ranges = $position['time_ranges'] ?? [];
                $planRows = $position['plan_rows'] ?? [];

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
