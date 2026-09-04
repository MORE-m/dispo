<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDispoOrderDraftRequest extends FormRequest
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
            'dynamic_field_values' => ['required', 'array'],
            'dynamic_field_values.billing_special_features' => ['nullable', 'string', 'max:20000'],
            'dynamic_field_values.disposition_notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dynamic_field_values.billing_special_features.max' => 'Besonderheiten zur Rechnungsstellung darf höchstens 20000 Zeichen haben.',
            'dynamic_field_values.disposition_notes.max' => 'Wichtige Informationen an die Disposition darf höchstens 20000 Zeichen haben.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $values = $this->input('dynamic_field_values');
            if (! is_array($values)) {
                return;
            }

            $allowed = [
                'billing_special_features' => true,
                'disposition_notes' => true,
            ];

            foreach (array_keys($values) as $key) {
                $key = (string) $key;
                if (! isset($allowed[$key])) {
                    $validator->errors()->add(
                        "dynamic_field_values.{$key}",
                        $key === 'campaign_period' || $key === 'period_open' || $key === 'position_flight_period'
                            ? 'Dieses Feld ist im Dispoauftrag nicht bearbeitbar.'
                            : 'Unbekanntes dynamisches Feld.',
                    );
                }
            }
        });
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    /**
     * @return array{billing_special_features?: string|null, disposition_notes?: string|null}
     */
    public function dynamicFieldValues(): array
    {
        /** @var array<string, mixed> $values */
        $values = $this->input('dynamic_field_values', []);

        return array_intersect_key($values, array_flip([
            'billing_special_features',
            'disposition_notes',
        ]));
    }
}
