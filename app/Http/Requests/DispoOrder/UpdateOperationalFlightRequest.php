<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use App\Support\DispoOrder\OperationalFlightContract;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOperationalFlightRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('updateOperationalFlight', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'operational_flight_start' => ['nullable', 'date'],
            'operational_flight_end' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lock_version' => 'Version',
            'operational_flight_start' => 'Startdatum',
            'operational_flight_end' => 'Enddatum',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operational_flight_start.date' => OperationalFlightContract::LABEL.': Startdatum ist kein gültiges Datum.',
            'operational_flight_end.date' => OperationalFlightContract::LABEL.': Enddatum ist kein gültiges Datum.',
        ];
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    public function flightStart(): mixed
    {
        return $this->validated('operational_flight_start') ?? null;
    }

    public function flightEnd(): mixed
    {
        return $this->validated('operational_flight_end') ?? null;
    }
}
