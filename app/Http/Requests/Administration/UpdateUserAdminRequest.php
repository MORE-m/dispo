<?php

namespace App\Http\Requests\Administration;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role' => ['required', 'string', Rule::enum(Role::class)],
            'discount_limit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'can_special_approve' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Name',
            'email' => 'E-Mail',
            'role' => 'Rolle',
            'discount_limit_percent' => 'Rabattgrenze',
            'can_special_approve' => 'Sonderfreigaberecht',
            'is_active' => 'Aktivstatus',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }

        if ($this->input('discount_limit_percent') === '' || $this->input('discount_limit_percent') === null) {
            $this->merge(['discount_limit_percent' => null]);
        }

        foreach (['can_special_approve', 'is_active'] as $field) {
            if ($this->has($field)) {
                $raw = $this->input($field);
                if (is_string($raw)) {
                    $this->merge([
                        $field => filter_var(
                            $raw,
                            FILTER_VALIDATE_BOOLEAN,
                            FILTER_NULL_ON_FAILURE,
                        ) ?? $raw,
                    ]);
                }
            }
        }
    }

    /**
     * @return array{
     *     name: string,
     *     email: string,
     *     role: Role,
     *     discount_limit_percent: string|null,
     *     can_special_approve: bool,
     *     is_active: bool,
     * }
     */
    public function adminPayload(): array
    {
        $validated = $this->validated();
        $discount = $validated['discount_limit_percent'] ?? null;

        return [
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            'role' => Role::from((string) $validated['role']),
            'discount_limit_percent' => $discount === null || $discount === ''
                ? null
                : (string) $discount,
            'can_special_approve' => (bool) $validated['can_special_approve'],
            'is_active' => (bool) $validated['is_active'],
        ];
    }
}
