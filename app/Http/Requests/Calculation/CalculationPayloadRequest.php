<?php

namespace App\Http\Requests\Calculation;

use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\PlanningMode;
use App\Enums\SpotCalculationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'positions.*.total_spot_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'positions.*.position_discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'positions.*.ae_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'positions.*.plan_rows' => ['array', 'min:1'],
            'positions.*.plan_rows.*.hour' => ['required', 'integer', 'min:0', 'max:23'],
            'positions.*.plan_rows.*.day_group' => ['required', Rule::enum(DayGroup::class)],
            'positions.*.plan_rows.*.second_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}
