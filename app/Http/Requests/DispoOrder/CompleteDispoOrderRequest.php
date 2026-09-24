<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use App\Services\DispoOrder\DispoOrderCompletionService;
use Illuminate\Foundation\Http\FormRequest;

class CompleteDispoOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('complete', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'override_reason' => [
                'nullable',
                'string',
                'max:'.DispoOrderCompletionService::OVERRIDE_REASON_MAX,
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
            'override_reason' => 'Override-Begründung',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'override_reason.max' => 'Die Begründung darf maximal 2000 Zeichen haben.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('override_reason')) {
            $this->merge([
                'override_reason' => trim((string) $this->input('override_reason')),
            ]);
        }
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    public function overrideReason(): ?string
    {
        $reason = $this->validated('override_reason') ?? null;

        if (! is_string($reason) || $reason === '') {
            return null;
        }

        return $reason;
    }
}
