<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveDispoOrderUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('archiveUpload', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
        ];
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }
}
