<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceEndMonthsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('updateInvoiceEndMonths', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'months' => ['present', 'array'],
            'months.*' => ['integer', 'distinct', 'min:1', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'months' => 'Rechnungsmonate',
            'months.*' => 'Rechnungsmonat',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'months.required' => 'Die Monatsauswahl ist erforderlich.',
            'months.present' => 'Die Monatsauswahl ist erforderlich.',
            'months.array' => 'Die Monatsauswahl ist erforderlich.',
            'months.*.integer' => 'Rechnungsmonate müssen ganze Zahlen sein.',
            'months.*.distinct' => 'Rechnungsmonate dürfen nicht doppelt vorkommen.',
            'months.*.min' => 'Rechnungsmonate müssen zwischen 1 und 12 liegen.',
            'months.*.max' => 'Rechnungsmonate müssen zwischen 1 und 12 liegen.',
        ];
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    /**
     * @return list<int>
     */
    public function months(): array
    {
        /** @var list<int> $months */
        $months = array_map(
            static fn (mixed $month): int => (int) $month,
            $this->validated('months'),
        );

        return $months;
    }
}
