<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use App\Services\DispoOrder\DispoOrderUploadService;
use Illuminate\Foundation\Http\FormRequest;

class UploadDispoOrderDynamicFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('uploadMaterial', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) (DispoOrderUploadService::MAX_BYTES / 1024);

        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'field_key' => ['required', 'string', 'max:64'],
            'position_id' => ['nullable', 'integer', 'min:1'],
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
            'field_key' => 'Feld',
            'position_id' => 'Position',
            'file' => 'Datei',
        ];
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    public function fieldKey(): string
    {
        return trim((string) $this->validated('field_key'));
    }

    public function positionId(): ?int
    {
        $value = $this->validated('position_id');

        return $value === null ? null : (int) $value;
    }
}
