<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use Illuminate\Foundation\Http\FormRequest;

class SyncDispoOrderCalculationDynamicFieldsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('update', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }
}
