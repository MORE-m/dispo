<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDispoOrderHeaderRequest extends FormRequest
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
            'agency_name' => ['nullable', 'string', 'max:255'],
            'agency_account_id' => ['nullable', 'integer', 'min:1'],
            'agency_billing_email' => ['nullable', 'string', 'max:255'],
            'agency_matching_domain' => ['nullable', 'string', 'max:255'],
            'agency_create_provisional' => ['sometimes', 'boolean'],
            'ensure_provisional_agency' => ['sometimes', 'boolean'],
            'invoice_recipient' => ['nullable', 'string', 'in:customer,agency'],
            'briefing' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ([
                'customer_name',
                'customer_account_id',
                'customer_create_provisional',
                'ensure_provisional_customer',
                'customer_billing_email',
                'customer_matching_domain',
            ] as $forbidden) {
                if ($this->exists($forbidden)) {
                    $validator->errors()->add(
                        $forbidden,
                        'Der Kunde kann im Dispoauftrag nicht geändert werden.',
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
     * @return array<string, mixed>
     */
    public function headerPayload(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        unset($validated['lock_version']);

        // invoice_recipient und briefing immer explizit setzen (auch null),
        // damit der Dispo-Pfad keine Calc-Defaults übernimmt.
        if (! array_key_exists('invoice_recipient', $validated)) {
            $validated['invoice_recipient'] = $this->input('invoice_recipient');
        }
        if (! array_key_exists('briefing', $validated)) {
            $validated['briefing'] = $this->input('briefing');
        }

        return $validated;
    }
}
