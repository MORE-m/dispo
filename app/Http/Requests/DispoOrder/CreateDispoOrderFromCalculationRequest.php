<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\Calculation;
use App\Models\DispoOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateDispoOrderFromCalculationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Calculation $calculation */
        $calculation = $this->route('calculation');
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $revisesId = $this->revisesDispoOrderId();

        if ($revisesId !== null) {
            $predecessor = DispoOrder::query()->find($revisesId);

            if ($predecessor === null) {
                return false;
            }

            return $user->can('createRevision', $predecessor)
                && (int) $predecessor->calculation_id === (int) $calculation->id;
        }

        return $user->can('create', [DispoOrder::class, $calculation]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'position_ids' => ['required', 'array', 'min:1'],
            'position_ids.*' => ['required', 'integer', 'distinct'],
            'revises_dispo_order_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $revisesId = $this->revisesDispoOrderId();

            if ($revisesId === null) {
                return;
            }

            /** @var Calculation $calculation */
            $calculation = $this->route('calculation');
            $predecessor = DispoOrder::query()->find($revisesId);

            if ($predecessor === null) {
                $validator->errors()->add(
                    'revises_dispo_order_id',
                    'Der angegebene Vorgänger-Dispoauftrag existiert nicht.',
                );

                return;
            }

            if ((int) $predecessor->calculation_id !== (int) $calculation->id) {
                $validator->errors()->add(
                    'revises_dispo_order_id',
                    'Vorgänger und Kalkulation müssen übereinstimmen.',
                );
            }
        });
    }

    /**
     * @return list<int>
     */
    public function positionIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', $this->input('position_ids', [])));

        return $ids;
    }

    public function revisesDispoOrderId(): ?int
    {
        if (! $this->filled('revises_dispo_order_id')) {
            return null;
        }

        return (int) $this->input('revises_dispo_order_id');
    }

    public function predecessor(): ?DispoOrder
    {
        $id = $this->revisesDispoOrderId();

        if ($id === null) {
            return null;
        }

        return DispoOrder::query()->find($id);
    }
}
