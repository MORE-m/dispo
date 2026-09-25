<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use App\Services\DispoOrder\DispoOrderCompletedReopenService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReopenCompletedDispoOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('reopenCompleted', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'reason' => [
                'required',
                'string',
                'max:'.DispoOrderCompletedReopenService::REASON_MAX,
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
            'reason' => 'Begründung',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Eine Begründung ist für die Wiederöffnung erforderlich.',
            'reason.max' => 'Die Begründung darf maximal 2000 Zeichen haben.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge([
                'reason' => trim((string) $this->input('reason')),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $reason = $this->input('reason');
            if (! is_string($reason) || $reason === '') {
                return;
            }

            if (trim($reason) === '') {
                $validator->errors()->add(
                    'reason',
                    'Eine Begründung ist für die Wiederöffnung erforderlich.',
                );
            }
        });
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
