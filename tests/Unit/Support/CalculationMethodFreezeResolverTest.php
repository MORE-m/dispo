<?php

namespace Tests\Unit\Support;

use App\Support\Calculation\CalculationMethodFreezeDescriptor;
use App\Support\Calculation\CalculationMethodFreezeResolver;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADV-001c2: Dual-Read Descriptor-/Freeze-Auflösung (ohne DB).
 */
class CalculationMethodFreezeResolverTest extends TestCase
{
    private CalculationMethodFreezeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CalculationMethodFreezeResolver;
    }

    public function test_legacy_spot_classic_average_fallback_yields_descriptor(): void
    {
        $descriptor = $this->resolver->resolveStoredPosition((object) [
            'kind' => 'spot_classic',
            'spot_method' => 'average',
            'engine_profile_key' => null,
            'calculation_method_key' => null,
            'calculation_method_name' => null,
            'algorithm_version' => null,
        ]);

        $this->assertSame(EngineProfileRegistry::PROFILE_SPOT_CLASSIC, $descriptor->engineProfileKey);
        $this->assertSame('average', $descriptor->calculationMethodKey);
        $this->assertSame('Durchschnitt', $descriptor->calculationMethodName);
        $this->assertSame('v1', $descriptor->algorithmVersion);
        $this->assertTrue($descriptor->equals(new CalculationMethodFreezeDescriptor(
            engineProfileKey: CalculationMethodFreezeResolver::LEGACY_SPOT_CLASSIC_AVERAGE['engine_profile_key'],
            calculationMethodKey: CalculationMethodFreezeResolver::LEGACY_SPOT_CLASSIC_AVERAGE['calculation_method_key'],
            calculationMethodName: CalculationMethodFreezeResolver::LEGACY_SPOT_CLASSIC_AVERAGE['calculation_method_name'],
            algorithmVersion: CalculationMethodFreezeResolver::LEGACY_SPOT_CLASSIC_AVERAGE['algorithm_version'],
        )));
    }

    public function test_partial_freeze_is_rejected(): void
    {
        try {
            $this->resolver->resolveStoredPosition((object) [
                'kind' => 'spot_classic',
                'spot_method' => 'average',
                'engine_profile_key' => 'spot_classic',
                'calculation_method_key' => 'average',
                'calculation_method_name' => null,
                'algorithm_version' => 'v1',
            ]);
            $this->fail('Erwartete ValidationException bei partiellem Freeze.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die eingefrorenen Berechnungsdaten der Position sind unvollständig und können nicht verwendet werden.'],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    public function test_complete_freeze_is_used_as_is(): void
    {
        $descriptor = $this->resolver->resolveStoredPosition((object) [
            'kind' => 'spot_classic',
            'spot_method' => 'average',
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt (Freeze)',
            'algorithm_version' => 'v1',
        ]);

        $this->assertSame('spot_classic', $descriptor->engineProfileKey);
        $this->assertSame('average', $descriptor->calculationMethodKey);
        $this->assertSame('Durchschnitt (Freeze)', $descriptor->calculationMethodName);
        $this->assertSame('v1', $descriptor->algorithmVersion);
    }

    public function test_legacy_freeze_contradiction_is_rejected(): void
    {
        try {
            $this->resolver->resolveStoredPosition((object) [
                'kind' => 'spot_classic',
                'spot_method' => 'calendar',
                'engine_profile_key' => 'spot_classic',
                'calculation_method_key' => 'average',
                'calculation_method_name' => 'Durchschnitt',
                'algorithm_version' => 'v1',
            ]);
            $this->fail('Erwartete ValidationException bei Freeze/Legacy-Widerspruch.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Eingefrorene Berechnungsdaten widersprechen den Legacy-Feldern der Position.'],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    public function test_assert_method_unchanged_rejects_spot_method_change(): void
    {
        $existing = (object) [
            'kind' => 'spot_classic',
            'spot_method' => 'average',
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => 'v1',
        ];

        $this->resolver->assertMethodUnchangedOnExisting($existing, 'average');
        $this->resolver->assertMethodUnchangedOnExisting($existing, null);
        $this->resolver->assertMethodUnchangedOnExisting($existing, '');

        try {
            $this->resolver->assertMethodUnchangedOnExisting($existing, 'calendar');
            $this->fail('Erwartete ValidationException bei Methodenwechsel.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die Berechnungsmethode einer bestehenden Position kann in diesem Umfang nicht geändert werden.'],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    public function test_unknown_algorithm_version_blocks_execution_but_allows_read(): void
    {
        $position = (object) [
            'kind' => 'spot_classic',
            'spot_method' => 'average',
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => 'v999',
        ];

        try {
            $this->resolver->resolveStoredPosition($position, forExecution: true);
            $this->fail('Erwartete ValidationException bei unbekannter Version für Execution.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die eingefrorene Berechnungsmethode oder Algorithmusversion ist unbekannt und kann nicht ausgeführt werden.'],
                $exception->errors()['positions'] ?? null,
            );
        }

        $read = $this->resolver->resolveStoredPosition($position, forExecution: false);
        $this->assertSame('v999', $read->algorithmVersion);
        $this->assertSame('average', $read->calculationMethodKey);
    }

    public function test_planned_pair_freeze_with_unknown_version_is_not_executable(): void
    {
        // calendar ist pair_status=planned ohne versions-Eintrag → statusForVersion fail-closed.
        $position = (object) [
            'kind' => 'spot_classic',
            'spot_method' => 'calendar',
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'calendar',
            'calculation_method_name' => 'Kalenderplaner',
            'algorithm_version' => 'v1',
        ];

        try {
            $this->resolver->resolveStoredPosition($position, forExecution: true);
            $this->fail('Erwartete ValidationException für nicht ausführbare geplante Methode.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die eingefrorene Berechnungsmethode oder Algorithmusversion ist unbekannt und kann nicht ausgeführt werden.'],
                $exception->errors()['positions'] ?? null,
            );
        }

        $read = $this->resolver->resolveStoredPosition($position, forExecution: false);
        $this->assertSame('calendar', $read->calculationMethodKey);
        $this->assertSame('v1', $read->algorithmVersion);
    }

    public function test_unknown_legacy_combo_without_freeze_is_rejected(): void
    {
        try {
            $this->resolver->resolveStoredPosition((object) [
                'kind' => 'spot_classic',
                'spot_method' => 'calendar',
                'engine_profile_key' => null,
                'calculation_method_key' => null,
                'calculation_method_name' => null,
                'algorithm_version' => null,
            ]);
            $this->fail('Erwartete ValidationException ohne Freeze und ohne Legacy-Fallback.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die Position besitzt keinen gültigen Berechnungs-Freeze und keinen bekannten Legacy-Fallback.'],
                $exception->errors()['positions'] ?? null,
            );
        }
    }

    public function test_freeze_completeness_helper(): void
    {
        $this->assertSame('empty', $this->resolver->freezeCompleteness([
            'engine_profile_key' => null,
            'calculation_method_key' => null,
            'calculation_method_name' => null,
            'algorithm_version' => null,
        ]));
        $this->assertSame('complete', $this->resolver->freezeCompleteness([
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => 'v1',
        ]));
        $this->assertSame('partial', $this->resolver->freezeCompleteness([
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => null,
            'calculation_method_name' => null,
            'algorithm_version' => null,
        ]));
        $this->assertSame('partial', $this->resolver->freezeCompleteness([
            'engine_profile_key' => '',
            'calculation_method_key' => '',
            'calculation_method_name' => '',
            'algorithm_version' => '',
        ]));
        $this->assertSame('partial', $this->resolver->freezeCompleteness([
            'engine_profile_key' => 'spot_classic',
            'calculation_method_key' => '',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => 'v1',
        ]));
    }

    public function test_blank_and_whitespace_freeze_fields_are_partial_without_silent_remap(): void
    {
        $legacyCompatible = [
            'kind' => 'spot_classic',
            'spot_method' => 'average',
        ];

        foreach ([
            [
                'engine_profile_key' => '',
                'calculation_method_key' => '',
                'calculation_method_name' => '',
                'algorithm_version' => '',
            ],
            [
                'engine_profile_key' => '   ',
                'calculation_method_key' => "\t",
                'calculation_method_name' => '  ',
                'algorithm_version' => "\n",
            ],
            [
                'engine_profile_key' => 'spot_classic',
                'calculation_method_key' => 'average',
                'calculation_method_name' => 'Durchschnitt',
                'algorithm_version' => '  ',
            ],
            [
                'engine_profile_key' => 'spot_classic',
                'calculation_method_key' => null,
                'calculation_method_name' => null,
                'algorithm_version' => null,
            ],
        ] as $freeze) {
            try {
                $this->resolver->resolveStoredPosition((object) array_merge($legacyCompatible, $freeze));
                $this->fail('Erwartete ValidationException bei Blank-/Partial-Freeze ohne stilles Remap.');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    ['Die eingefrorenen Berechnungsdaten der Position sind unvollständig und können nicht verwendet werden.'],
                    $exception->errors()['positions'] ?? null,
                );
            }
        }
    }
}
