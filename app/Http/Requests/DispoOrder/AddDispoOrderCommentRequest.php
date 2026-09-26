<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use App\Services\DispoOrder\DispoOrderGeneralCommentService;
use Illuminate\Foundation\Http\FormRequest;

class AddDispoOrderCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('addComment', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => [
                'required',
                'string',
                'min:1',
                'max:'.DispoOrderGeneralCommentService::BODY_MAX,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'Kommentar',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Ein Kommentartext ist erforderlich.',
            'body.min' => 'Ein Kommentartext ist erforderlich.',
            'body.max' => sprintf(
                'Der Kommentar darf maximal %d Zeichen haben.',
                DispoOrderGeneralCommentService::BODY_MAX,
            ),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('body')) {
            $this->merge([
                'body' => trim((string) $this->input('body')),
            ]);
        }
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }
}
