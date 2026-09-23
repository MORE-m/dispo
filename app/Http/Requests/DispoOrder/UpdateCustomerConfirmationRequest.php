<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use App\Services\DispoOrder\DispoOrderCustomerConfirmationService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('updateCustomerConfirmation', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'confirmation_without_upload' => ['required', 'boolean'],
            'exception_reason' => [
                'nullable',
                'string',
                'max:'.DispoOrderCustomerConfirmationService::EXCEPTION_REASON_MAX,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'confirmation_without_upload' => 'Bestätigung ohne Upload',
            'exception_reason' => 'Ausnahmegrund',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'exception_reason.max' => 'Der Ausnahmegrund darf maximal 2000 Zeichen haben.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('exception_reason') && is_string($this->input('exception_reason'))) {
            $this->merge([
                'exception_reason' => trim((string) $this->input('exception_reason')),
            ]);
        }

        if ($this->has('confirmation_without_upload')) {
            $raw = $this->input('confirmation_without_upload');
            if (is_string($raw)) {
                $this->merge([
                    'confirmation_without_upload' => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $raw,
                ]);
            }
        }
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    public function confirmationWithoutUpload(): bool
    {
        return (bool) $this->validated('confirmation_without_upload');
    }

    public function exceptionReason(): ?string
    {
        $reason = $this->validated('exception_reason') ?? null;

        if (! is_string($reason) || $reason === '') {
            return null;
        }

        return $reason;
    }
}
