<?php

namespace App\Http\Requests\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Models\DispoOrder;
use App\Services\DispoOrder\DispoOrderStatusTransition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransitionOperationalStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('transitionOperationalStatus', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        $allowed = array_map(
            fn (DispoOrderStatus $status): string => $status->value,
            DispoOrderStatusTransition::allowedOperationalTargets($order->status),
        );

        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'target_status' => ['required', 'string', Rule::in($allowed)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'target_status' => 'Zielstatus',
            'reason' => 'Begründung',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_status.in' => 'Der gewählte Zielstatus ist für den aktuellen Auftrag nicht erlaubt.',
            'reason.required' => 'Eine Begründung ist für die Wiederöffnung erforderlich.',
            'reason.min' => 'Eine Begründung ist für die Wiederöffnung erforderlich.',
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
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var DispoOrder $order */
            $order = $this->route('dispoOrder');
            $target = DispoOrderStatus::tryFrom((string) $this->input('target_status'));
            if ($target === null) {
                return;
            }

            if (! DispoOrderStatusTransition::requiresReason($order->status, $target)) {
                return;
            }

            $reason = $this->input('reason');
            if (! is_string($reason) || trim($reason) === '') {
                $validator->errors()->add('reason', 'Eine Begründung ist für die Wiederöffnung erforderlich.');
            }
        });
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    public function targetStatus(): DispoOrderStatus
    {
        return DispoOrderStatus::from((string) $this->validated('target_status'));
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason') ?? null;

        if (! is_string($reason) || $reason === '') {
            return null;
        }

        return $reason;
    }
}
