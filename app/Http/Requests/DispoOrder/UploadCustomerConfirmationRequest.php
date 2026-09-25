<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use App\Services\DispoOrder\DispoOrderUploadService;
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
        // Laravel file max is kilobytes; align exactly with service MAX_BYTES (UPL-006).
        $maxKilobytes = (int) (DispoOrderUploadService::MAX_BYTES / 1024);

        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file', 'max:'.$maxKilobytes],
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
