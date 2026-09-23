<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use Illuminate\Foundation\Http\FormRequest;

class AnswerSalesInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('answerSalesInquiry', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'answer' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'answer' => 'Antwort',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answer.required' => 'Eine Antwort ist erforderlich.',
            'answer.min' => 'Eine Antwort ist erforderlich.',
            'answer.max' => 'Die Antwort darf maximal 2000 Zeichen haben.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('answer')) {
            $this->merge([
                'answer' => trim((string) $this->input('answer')),
            ]);
        }
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    public function answer(): string
    {
        return (string) $this->validated('answer');
    }
}
