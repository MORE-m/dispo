<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use Illuminate\Foundation\Http\FormRequest;

class ApproveDispoOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('approve', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
            'customer_confirmation_exception_acknowledged' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'note' => 'Notiz',
            'customer_confirmation_exception_acknowledged' => 'Ausnahme-Mitfreigabe',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('note')) {
            $this->merge([
                'note' => trim((string) $this->input('note')),
            ]);
        }

        if ($this->has('customer_confirmation_exception_acknowledged')) {
            $raw = $this->input('customer_confirmation_exception_acknowledged');
            if (is_string($raw)) {
                $this->merge([
                    'customer_confirmation_exception_acknowledged' => filter_var(
                        $raw,
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE,
                    ) ?? $raw,
                ]);
            }
        }
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    public function note(): ?string
    {
        $note = $this->validated('note') ?? null;

        if (! is_string($note) || $note === '') {
            return null;
        }

        return $note;
    }

    public function customerConfirmationExceptionAcknowledged(): bool
    {
        return (bool) ($this->validated('customer_confirmation_exception_acknowledged') ?? false);
    }
}
