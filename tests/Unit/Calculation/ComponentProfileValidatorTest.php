<?php

namespace Tests\Unit\Calculation;

use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentProfile;
use App\Services\Calculation\ComponentValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BL-P4-02e: ComponentValidator für erzwungene Profile und optionales Allonge.
 */
class ComponentProfileValidatorTest extends TestCase
{
    private ComponentValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ComponentValidator;
    }

    public function test_tandem_valid_components_normalized(): void
    {
        $normalized = $this->validator->validateAndNormalize(
            $this->positionPayload(30, [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
            ]),
            0,
            SpotCalculationMethod::Average,
            null,
            SpotComponentProfile::Tandem,
        );

        $this->assertCount(2, $normalized);
        $this->assertSame('main_spot', $normalized[0]['role']);
        $this->assertSame(1, $normalized[0]['sort']);
        $this->assertSame('reminder', $normalized[1]['role']);
        $this->assertSame(2, $normalized[1]['sort']);
    }

    public function test_tandem_rejects_too_few_components(): void
    {
        $this->expectValidation('positions.0.components', function (): void {
            $this->validator->validateAndNormalize(
                $this->positionPayload(20, [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                ]),
                0,
                SpotCalculationMethod::Average,
                null,
                SpotComponentProfile::Tandem,
            );
        });
    }

    public function test_tandem_rejects_too_many_components(): void
    {
        $this->expectValidation('positions.0.components', function (): void {
            $this->validator->validateAndNormalize(
                $this->positionPayload(40, [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                    ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
                    ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 3],
                ]),
                0,
                SpotCalculationMethod::Average,
                null,
                SpotComponentProfile::Tandem,
            );
        });
    }

    public function test_tandem_rejects_allonge_role(): void
    {
        $this->expectValidation('positions.0.components.1.role', function (): void {
            $this->validator->validateAndNormalize(
                $this->positionPayload(30, [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                    ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 2],
                ]),
                0,
                SpotCalculationMethod::Average,
                null,
                SpotComponentProfile::Tandem,
            );
        });
    }

    public function test_tandem_rejects_unknown_role(): void
    {
        $this->expectValidation('positions.0.components.1.role', function (): void {
            $this->validator->validateAndNormalize(
                $this->positionPayload(25, [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                    ['role' => 'abbinder', 'label' => 'Abbinder', 'length_seconds' => 5, 'sort' => 2],
                ]),
                0,
                SpotCalculationMethod::Average,
                null,
                SpotComponentProfile::Tandem,
            );
        });
    }

    public function test_tandem_rejects_two_reminders(): void
    {
        $this->expectValidation('positions.0.components', function (): void {
            $this->validator->validateAndNormalize(
                $this->positionPayload(30, [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                    ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 5, 'sort' => 2],
                    ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 5, 'sort' => 3],
                ]),
                0,
                SpotCalculationMethod::Average,
                null,
                SpotComponentProfile::Tandem,
            );
        });
    }

    public function test_tridem_accepts_two_reminders(): void
    {
        $normalized = $this->validator->validateAndNormalize(
            $this->positionPayload(40, [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
                ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 3],
            ]),
            0,
            SpotCalculationMethod::Average,
            null,
            SpotComponentProfile::Tridem,
        );

        $this->assertCount(3, $normalized);
        $this->assertSame([1, 2, 3], array_column($normalized, 'sort'));
    }

    public function test_tridem_canonicalizes_double_reminder_sort_order(): void
    {
        $normalized = $this->validator->validateAndNormalize(
            $this->positionPayload(40, [
                ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 3],
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
            ]),
            0,
            SpotCalculationMethod::Average,
            null,
            SpotComponentProfile::Tridem,
        );

        $this->assertSame(['main_spot', 'reminder', 'reminder'], array_column($normalized, 'role'));
        $this->assertSame([1, 2, 3], array_column($normalized, 'sort'));
    }

    public function test_tridem_rejects_one_reminder(): void
    {
        $this->expectValidation('positions.0.components', function (): void {
            $this->validator->validateAndNormalize(
                $this->positionPayload(30, [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                    ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
                ]),
                0,
                SpotCalculationMethod::Average,
                null,
                SpotComponentProfile::Tridem,
            );
        });
    }

    public function test_optional_allonge_path_still_works_with_null_profile(): void
    {
        $normalized = $this->validator->validateAndNormalize(
            $this->positionPayload(30, [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
            ], 'shared_total_length'),
            0,
            SpotCalculationMethod::Average,
            null,
            null,
        );

        $this->assertCount(2, $normalized);
        $this->assertSame('main_spot', $normalized[0]['role']);
        $this->assertSame('allonge', $normalized[1]['role']);
    }

    public function test_forced_profile_rejects_empty_components(): void
    {
        $this->expectValidation('positions.0.components', function (): void {
            $this->validator->validateAndNormalize(
                ['length_seconds' => 30, 'components' => []],
                0,
                SpotCalculationMethod::Average,
                null,
                SpotComponentProfile::Tandem,
            );
        });
    }

    /**
     * @param  list<array{role: string, label: string, length_seconds: int, sort: int}>  $components
     * @return array<string, mixed>
     */
    private function positionPayload(int $lengthSeconds, array $components, ?string $strategy = null): array
    {
        $payload = [
            'length_seconds' => $lengthSeconds,
            'components' => $components,
        ];

        if ($strategy !== null) {
            $payload['component_calculation_strategy'] = $strategy;
        }

        return $payload;
    }

    private function expectValidation(string $key, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
