<?php

namespace App\Services\InventoryMediumRule\Catalog;

use App\Enums\CalculationKind;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PO-REFOCUS-6: Gezielte Reparatur nur für allonge + promo_moderation.
 * Kein vollständiger Initialkatalog-Bootstrap.
 */
final class SwfMediumMethodOverrideRepairer
{
    /** @var list<string> */
    public const TARGET_CODES = ['allonge', 'promo_moderation'];

    public function __construct(
        private readonly SwfLengthMediumOverrideEnsurer $ensurer,
    ) {}

    /**
     * @return array{
     *     status: 'PASS'|'STOP',
     *     mode: 'dry-run'|'apply',
     *     media: list<array<string, mixed>>,
     *     unchanged: list<string>,
     *     changed: list<string>,
     *     conflicts: list<string>,
     *     message: string
     * }
     */
    public function repair(bool $dryRun): array
    {
        $mode = $dryRun ? 'dry-run' : 'apply';
        $media = [];
        $unchanged = [];
        $changed = [];

        try {
            DB::transaction(function () use ($dryRun, &$media, &$unchanged, &$changed): void {
                foreach ($this->targets() as $target) {
                    $result = $this->ensurer->ensure(
                        mediumCode: $target['code'],
                        expectedKind: $target['kind'],
                        engineProfile: $target['profile'],
                        label: $target['label'],
                        dryRun: $dryRun,
                        requireMedium: true,
                    );

                    $media[] = [
                        'code' => $target['code'],
                        'label' => $target['label'],
                        'medium_id' => $result['medium_id'],
                        'outcome' => $result['outcome'],
                        'before' => $result['before'],
                        'after' => $result['after'],
                        'changes' => $result['changes'],
                    ];

                    if ($result['outcome'] === SwfLengthMediumOverrideEnsurer::OUTCOME_UNCHANGED) {
                        $unchanged[] = $target['code'];
                    } else {
                        $changed[] = $target['code'];
                    }
                }
            });
        } catch (Throwable $e) {
            return [
                'status' => 'STOP',
                'mode' => $mode,
                'media' => $media,
                'unchanged' => $unchanged,
                'changed' => $changed,
                'conflicts' => [$e->getMessage()],
                'message' => 'STOP: '.$e->getMessage(),
            ];
        }

        return [
            'status' => 'PASS',
            'mode' => $mode,
            'media' => $media,
            'unchanged' => $unchanged,
            'changed' => $changed,
            'conflicts' => [],
            'message' => $dryRun
                ? 'PASS: Dry-Run ohne Datenänderung.'
                : 'PASS: Reparatur angewendet.',
        ];
    }

    /**
     * @return list<array{code: string, kind: CalculationKind, profile: string, label: string}>
     */
    private function targets(): array
    {
        return [
            [
                'code' => 'allonge',
                'kind' => CalculationKind::SwfAllonge,
                'profile' => EngineProfileRegistry::PROFILE_SWF_ALLONGE,
                'label' => 'Allonge',
            ],
            [
                'code' => 'promo_moderation',
                'kind' => CalculationKind::SwfPromo,
                'profile' => EngineProfileRegistry::PROFILE_SWF_PROMO,
                'label' => 'Promo',
            ],
        ];
    }
}
