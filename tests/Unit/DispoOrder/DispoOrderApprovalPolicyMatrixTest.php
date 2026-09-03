<?php

namespace Tests\Unit\DispoOrder;

use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\User;
use App\Policies\DispoOrderPolicy;
use Tests\TestCase;

class DispoOrderApprovalPolicyMatrixTest extends TestCase
{
    private DispoOrderPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new DispoOrderPolicy;
    }

    public function test_regular_approval_roles(): void
    {
        $creator = User::factory()->role(Role::Sales)->make(['id' => 1]);
        $order = new DispoOrder([
            'created_by_id' => 1,
            'approval_kind' => DispoOrderApprovalKind::Regular,
            'requires_special_approval' => false,
            'status' => DispoOrderStatus::AwaitingSalesApproval,
        ]);

        $this->assertTrue($this->policy->approveRegular(
            User::factory()->role(Role::Sales)->make(['id' => 2]),
            $order,
        ));
        $this->assertTrue($this->policy->approveRegular(
            User::factory()->role(Role::Admin)->make(['id' => 3]),
            $order,
        ));
        $this->assertTrue($this->policy->approveRegular(
            User::factory()->role(Role::Management)->make(['id' => 4]),
            $order,
        ));
        $this->assertFalse($this->policy->approveRegular(
            User::factory()->role(Role::Disposition)->make(['id' => 5]),
            $order,
        ));
        $this->assertFalse($this->policy->approveRegular($creator, $order));
    }

    public function test_special_approval_roles(): void
    {
        $order = new DispoOrder([
            'created_by_id' => 1,
            'approval_kind' => DispoOrderApprovalKind::Special,
            'requires_special_approval' => true,
            'status' => DispoOrderStatus::AwaitingSalesApproval,
        ]);

        $this->assertFalse($this->policy->approveSpecial(
            User::factory()->role(Role::Sales)->make(['id' => 2]),
            $order,
        ));
        $this->assertTrue($this->policy->approveSpecial(
            User::factory()->role(Role::Admin)->make(['id' => 3]),
            $order,
        ));
        $this->assertTrue($this->policy->approveSpecial(
            User::factory()->role(Role::Management)->make(['id' => 4]),
            $order,
        ));
        $this->assertFalse($this->policy->approve(
            User::factory()->role(Role::Admin)->make(['id' => 1]),
            $order,
        ));
    }

    public function test_submit_roles(): void
    {
        $order = new DispoOrder(['status' => DispoOrderStatus::Draft]);

        $this->assertTrue($this->policy->submit(User::factory()->role(Role::Sales)->make(), $order));
        $this->assertTrue($this->policy->submit(User::factory()->role(Role::Admin)->make(), $order));
        $this->assertTrue($this->policy->submit(User::factory()->role(Role::Management)->make(), $order));
        $this->assertFalse($this->policy->submit(User::factory()->role(Role::Disposition)->make(), $order));
        $this->assertFalse($this->policy->submit(User::factory()->role(Role::ProductManagement)->make(), $order));
    }
}
