<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use Illuminate\Foundation\Http\FormRequest;

class AskSalesInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('askSalesInquiry', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'question' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'question' => 'Rückfrage',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => 'Eine Rückfrage ist erforderlich.',
            'question.min' => 'Eine Rückfrage ist erforderlich.',
            'question.max' => 'Die Rückfrage darf maximal 2000 Zeichen haben.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('question')) {
            $this->merge([
                'question' => trim((string) $this->input('question')),
            ]);
        }
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    public function question(): string
    {
        return (string) $this->validated('question');
    }
}
