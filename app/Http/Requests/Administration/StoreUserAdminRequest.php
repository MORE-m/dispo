<?php

namespace App\Http\Requests\Administration;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserAdminRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', Rule::enum(Role::class)],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'discount_limit_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'can_special_approve' => ['sometimes', 'boolean'],
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
            'password' => 'Passwort',
            'discount_limit_percent' => 'Rabattgrenze',
            'can_special_approve' => 'Sonderfreigaberecht',
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

        if ($this->has('can_special_approve')) {
            $raw = $this->input('can_special_approve');
            if (is_string($raw)) {
                $this->merge([
                    'can_special_approve' => filter_var(
                        $raw,
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE,
                    ) ?? $raw,
                ]);
            }
        } else {
            $this->merge(['can_special_approve' => false]);
        }
    }

    /**
     * @return array{
     *     name: string,
     *     email: string,
     *     role: Role,
     *     password: string,
     *     discount_limit_percent: string|null,
     *     can_special_approve: bool,
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
            'password' => (string) $validated['password'],
            'discount_limit_percent' => $discount === null || $discount === ''
                ? null
                : (string) $discount,
            'can_special_approve' => (bool) ($validated['can_special_approve'] ?? false),
        ];
    }
}
