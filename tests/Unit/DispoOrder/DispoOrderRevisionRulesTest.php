<?php

namespace Tests\Unit\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\User;
use App\Policies\DispoOrderPolicy;
use App\Services\DispoOrder\DispoOrderRevisionRules;
use Tests\TestCase;

class DispoOrderRevisionRulesTest extends TestCase
{
    private DispoOrderPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new DispoOrderPolicy;
    }

    public function test_only_approval_rejected_status_is_allowed(): void
    {
        $rejected = new DispoOrder(['status' => DispoOrderStatus::ApprovalRejected]);
        $draft = new DispoOrder(['status' => DispoOrderStatus::Draft]);
        $awaiting = new DispoOrder(['status' => DispoOrderStatus::AwaitingSalesApproval]);
        $atDisposition = new DispoOrder(['status' => DispoOrderStatus::AtDisposition]);

        $this->assertTrue(DispoOrderRevisionRules::predecessorStatusAllowed($rejected));
        $this->assertFalse(DispoOrderRevisionRules::predecessorStatusAllowed($draft));
        $this->assertFalse(DispoOrderRevisionRules::predecessorStatusAllowed($awaiting));
        $this->assertFalse(DispoOrderRevisionRules::predecessorStatusAllowed($atDisposition));
    }

    public function test_same_calculation_required(): void
    {
        $predecessor = new DispoOrder(['calculation_id' => 10]);
        $same = new Calculation;
        $same->id = 10;
        $other = new Calculation;
        $other->id = 11;
        $otherOrder = new DispoOrder(['calculation_id' => 11]);

        $this->assertTrue(DispoOrderRevisionRules::sameCalculation($predecessor, $same));
        $this->assertFalse(DispoOrderRevisionRules::sameCalculation($predecessor, $other));
        $this->assertFalse(DispoOrderRevisionRules::sameCalculation($predecessor, $otherOrder));
    }

    public function test_self_reference_is_detected(): void
    {
        $this->assertTrue(DispoOrderRevisionRules::isSelfReference(5, 5));
        $this->assertFalse(DispoOrderRevisionRules::isSelfReference(5, 6));
    }

    public function test_cycle_is_detected_via_predecessor_chain(): void
    {
        $root = new DispoOrder(['revises_dispo_order_id' => null]);
        $root->id = 1;

        $middle = new DispoOrder(['revises_dispo_order_id' => 1]);
        $middle->id = 2;
        $middle->setRelation('revises', $root);

        $this->assertTrue(DispoOrderRevisionRules::wouldCreateCycle($middle, 1));
        $this->assertFalse(DispoOrderRevisionRules::wouldCreateCycle($middle, 3));
        $this->assertFalse(DispoOrderRevisionRules::wouldCreateCycle($root, 2));
    }

    public function test_revise_policy_matrix(): void
    {
        $calculation = new Calculation;
        $calculation->id = 1;

        $creator = User::factory()->role(Role::Sales)->make(['id' => 1]);
        $otherSales = User::factory()->role(Role::Sales)->make(['id' => 2]);
        $disposition = User::factory()->role(Role::Disposition)->make(['id' => 3]);
        $pm = User::factory()->role(Role::ProductManagement)->make(['id' => 4]);
        $admin = User::factory()->role(Role::Admin)->make(['id' => 5]);

        $this->assertTrue($this->policy->revise($creator, $this->rejectedOrder(1, $calculation)));
        $this->assertFalse($this->policy->revise($otherSales, $this->rejectedOrder(1, $calculation)));
        $this->assertFalse($this->policy->revise($disposition, $this->rejectedOrder(1, $calculation)));
        $this->assertFalse($this->policy->revise($pm, $this->rejectedOrder(1, $calculation)));
        $this->assertFalse($this->policy->revise($admin, $this->rejectedOrder(1, $calculation)));
        $this->assertTrue($this->policy->revise($admin, $this->rejectedOrder(5, $calculation)));
        $this->assertFalse($this->policy->createRevision($otherSales, $this->rejectedOrder(1, $calculation)));
    }

    public function test_revise_policy_requires_rejected_status_and_no_successor(): void
    {
        $calculation = new Calculation;
        $calculation->id = 1;
        $creator = User::factory()->role(Role::Sales)->make(['id' => 1]);

        $draft = $this->rejectedOrder(1, $calculation);
        $draft->status = DispoOrderStatus::Draft;
        $this->assertFalse($this->policy->revise($creator, $draft));

        $withRevision = $this->rejectedOrder(1, $calculation);
        $withRevision->setRelation('revision', new DispoOrder(['id' => 99]));
        $this->assertFalse($this->policy->revise($creator, $withRevision));
    }

    private function rejectedOrder(int $creatorId, Calculation $calculation): DispoOrder
    {
        $order = new DispoOrder([
            'status' => DispoOrderStatus::ApprovalRejected,
            'created_by_id' => $creatorId,
            'calculation_id' => $calculation->id,
        ]);
        $order->setRelation('calculation', $calculation);
        $order->setRelation('revision', null);

        return $order;
    }
}
