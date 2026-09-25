<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use Illuminate\Foundation\Http\FormRequest;

class UploadCustomerConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('uploadCustomerConfirmation', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'file' => 'Datei',
        ];
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }
}
