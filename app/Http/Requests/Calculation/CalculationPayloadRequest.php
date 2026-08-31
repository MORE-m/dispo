<?php

namespace App\Http\Requests\Calculation;

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
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'calculation_id' => ['nullable', 'integer', 'min:1'],
            'positions' => ['array'],
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

            foreach ($this->input('positions', []) as $index => $position) {
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
                } elseif (! $isPreview && (! is_array($planRows) || $planRows === [])) {
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

        return $payload;
    }
}
