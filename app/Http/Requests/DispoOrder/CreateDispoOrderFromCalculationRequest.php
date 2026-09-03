<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\Calculation;
use App\Models\DispoOrder;
use Illuminate\Foundation\Http\FormRequest;

class CreateDispoOrderFromCalculationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Calculation $calculation */
        $calculation = $this->route('calculation');

        return $this->user()?->can('create', [DispoOrder::class, $calculation]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'position_ids' => ['required', 'array', 'min:1'],
            'position_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function positionIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', $this->input('position_ids', [])));

        return $ids;
    }
}
