<?php

namespace App\Http\Requests\DispoOrder;

use App\Models\DispoOrder;
use App\Support\DispoOrder\OperationalCampaignPeriodContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateDispoOrderHeaderRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DispoOrder $order */
        $order = $this->route('dispoOrder');

        return $this->user()?->can('update', $order) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'agency_name' => ['nullable', 'string', 'max:255'],
            'agency_account_id' => ['nullable', 'integer', 'min:1'],
            'agency_billing_email' => ['nullable', 'string', 'max:255'],
            'agency_matching_domain' => ['nullable', 'string', 'max:255'],
            'agency_create_provisional' => ['sometimes', 'boolean'],
            'ensure_provisional_agency' => ['sometimes', 'boolean'],
            'invoice_recipient' => ['nullable', 'string', 'in:customer,agency'],
            'briefing' => ['nullable', 'string', 'max:20000'],
            OperationalCampaignPeriodContract::START_KEY => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            OperationalCampaignPeriodContract::END_KEY => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ([
                'customer_name',
                'customer_account_id',
                'customer_create_provisional',
                'ensure_provisional_customer',
                'customer_billing_email',
                'customer_matching_domain',
            ] as $forbidden) {
                if ($this->exists($forbidden)) {
                    $validator->errors()->add(
                        $forbidden,
                        'Der Kunde kann im Dispoauftrag nicht geändert werden.',
                    );
                }
            }

            $startPresent = $this->exists(OperationalCampaignPeriodContract::START_KEY);
            $endPresent = $this->exists(OperationalCampaignPeriodContract::END_KEY);

            if ($startPresent xor $endPresent) {
                $validator->errors()->add(
                    OperationalCampaignPeriodContract::START_KEY,
                    OperationalCampaignPeriodContract::LABEL.': Start- und Enddatum müssen gemeinsam gesendet werden.',
                );
                $validator->errors()->add(
                    OperationalCampaignPeriodContract::END_KEY,
                    OperationalCampaignPeriodContract::LABEL.': Start- und Enddatum müssen gemeinsam gesendet werden.',
                );

                return;
            }

            if (! $startPresent && ! $endPresent) {
                return;
            }

            try {
                OperationalCampaignPeriodContract::canonicalizeForPersist(
                    $this->input(OperationalCampaignPeriodContract::START_KEY),
                    $this->input(OperationalCampaignPeriodContract::END_KEY),
                );
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            }
        });
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('lock_version');
    }

    /**
     * @return array<string, mixed>
     */
    public function headerPayload(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        unset($validated['lock_version']);

        // invoice_recipient und briefing immer explizit setzen (auch null),
        // damit der Dispo-Pfad keine Calc-Defaults übernimmt.
        if (! array_key_exists('invoice_recipient', $validated)) {
            $validated['invoice_recipient'] = $this->input('invoice_recipient');
        }
        if (! array_key_exists('briefing', $validated)) {
            $validated['briefing'] = $this->input('briefing');
        }

        // Zeitraum: fehlende Keys = Preserve; explizit vorhanden (inkl. null) = setzen.
        $startKey = OperationalCampaignPeriodContract::START_KEY;
        $endKey = OperationalCampaignPeriodContract::END_KEY;
        if ($this->exists($startKey) && $this->exists($endKey)) {
            $pair = OperationalCampaignPeriodContract::canonicalizeForPersist(
                $this->input($startKey),
                $this->input($endKey),
            );
            $validated[$startKey] = $pair['start'];
            $validated[$endKey] = $pair['end'];
        } else {
            unset($validated[$startKey], $validated[$endKey]);
        }

        return $validated;
    }
}
