<?php

namespace Tests\Unit\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;
use App\Services\DispoOrder\DispoOrderStatusTransition;
use Tests\TestCase;

class DispoOrderStatusTransitionTest extends TestCase
{
    public function test_allowed_transitions_for_approval_slice(): void
    {
        $this->assertTrue(DispoOrderStatusTransition::canTransition(
            DispoOrderStatus::Draft,
            DispoOrderStatus::AwaitingSalesApproval,
        ));
        $this->assertTrue(DispoOrderStatusTransition::canTransition(
            DispoOrderStatus::AwaitingSalesApproval,
            DispoOrderStatus::AtDisposition,
        ));
        $this->assertTrue(DispoOrderStatusTransition::canTransition(
            DispoOrderStatus::AwaitingSalesApproval,
            DispoOrderStatus::ApprovalRejected,
        ));
    }

    public function test_forbidden_transitions_are_rejected(): void
    {
        $this->assertFalse(DispoOrderStatusTransition::canTransition(
            DispoOrderStatus::Draft,
            DispoOrderStatus::AtDisposition,
        ));
        $this->assertFalse(DispoOrderStatusTransition::canTransition(
            DispoOrderStatus::Draft,
            DispoOrderStatus::ApprovalRejected,
        ));
        $this->assertFalse(DispoOrderStatusTransition::canTransition(
            DispoOrderStatus::ApprovalRejected,
            DispoOrderStatus::Draft,
        ));
        $this->assertFalse(DispoOrderStatusTransition::canTransition(
            DispoOrderStatus::AtDisposition,
            DispoOrderStatus::Draft,
        ));

        $this->expectException(DispoOrderConflictException::class);
        DispoOrderStatusTransition::assertCanTransition(
            DispoOrderStatus::Draft,
            DispoOrderStatus::AtDisposition,
        );
    }
}
