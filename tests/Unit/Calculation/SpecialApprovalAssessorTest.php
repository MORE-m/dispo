<?php

namespace Tests\Unit\Calculation;

use App\Enums\Role;
use App\Enums\SpecialApprovalReasonCode;
use App\Models\User;
use App\Services\Calculation\SpecialApprovalAssessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class SpecialApprovalAssessorTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_position_discount_reason_is_structured(): void
    {
        $assessor = new SpecialApprovalAssessor;
        $reasons = $assessor->reasonsForRates('20', '0', '20', '10', 5, 'id:5', 'Radio Hamburg');

        $this->assertCount(1, $reasons);
        $this->assertSame(SpecialApprovalReasonCode::PositionDiscountExceedsLimit->value, $reasons[0]['code']);
        $this->assertSame('20.0000', $reasons[0]['actual_percent']);
        $this->assertSame('10.0000', $reasons[0]['limit_percent']);
    }

    public function test_layered_effective_discount_uses_effective_reason(): void
    {
        $assessor = new SpecialApprovalAssessor;
        $reasons = $assessor->reasonsForRates('8', '8', '15.3600', '10');

        $this->assertCount(1, $reasons);
        $this->assertSame(
            SpecialApprovalReasonCode::EffectiveDiscountExceedsLimit->value,
            $reasons[0]['code'],
        );
    }

    public function test_partial_adoption_drops_special_when_offending_position_excluded(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
        ]);
        $calculation = $this->createSavedCalculation($catalog, [
            [
                'inventory_id' => $catalog['hamburg']->id,
                'position_discount_percent' => '20',
            ],
            [
                'inventory_id' => $catalog['rock']->id,
                'total_spot_count' => 5,
                'hour' => 10,
            ],
        ], $user);

        $calculation->refresh()->load(['positions.inventory']);
        $positions = $calculation->positions()->orderBy('sort')->get();
        $this->assertTrue($calculation->requires_special_approval);

        $onlySecond = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calculation,
            $positions->slice(1)->values(),
        );

        $this->assertFalse($onlySecond->requiresSpecialApproval);

        $onlyFirst = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calculation,
            $positions->slice(0, 1)->values(),
        );
        $this->assertTrue($onlyFirst->requiresSpecialApproval);
    }

    public function test_unattributable_legacy_special_flag_without_reasons_is_special(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $calculation->requires_special_approval = true;
        $calculation->special_approval_reasons = [];
        $calculation->personal_discount_limit_percent = null;
        $calculation->save();

        $assessment = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calculation->fresh(['positions.inventory']),
            $calculation->positions,
        );

        $this->assertTrue($assessment->requiresSpecialApproval);
        $this->assertSame(
            SpecialApprovalReasonCode::Unattributable->value,
            $assessment->reasons[0]['code'],
        );
    }
}
