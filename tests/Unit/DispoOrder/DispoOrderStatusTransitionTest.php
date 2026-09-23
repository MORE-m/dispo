<?php

namespace Tests\Unit\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;
use App\Services\DispoOrder\DispoOrderStatusTransition;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return list<array{0: DispoOrderStatus, 1: DispoOrderStatus}>
     */
    public static function allowedOperationalProvider(): array
    {
        return [
            [DispoOrderStatus::AtDisposition, DispoOrderStatus::InProgress],
            [DispoOrderStatus::InProgress, DispoOrderStatus::MaterialMissing],
            [DispoOrderStatus::InProgress, DispoOrderStatus::MaterialReceived],
            [DispoOrderStatus::MaterialMissing, DispoOrderStatus::MaterialReceived],
            [DispoOrderStatus::MaterialMissing, DispoOrderStatus::InProgress],
            [DispoOrderStatus::MaterialReceived, DispoOrderStatus::InProgress],
            [DispoOrderStatus::MaterialReceived, DispoOrderStatus::MaterialMissing],
            [DispoOrderStatus::InProgress, DispoOrderStatus::Disposed],
            [DispoOrderStatus::MaterialReceived, DispoOrderStatus::Disposed],
            [DispoOrderStatus::Disposed, DispoOrderStatus::InProgress],
        ];
    }

    #[DataProvider('allowedOperationalProvider')]
    public function test_allowed_operational_transitions(
        DispoOrderStatus $from,
        DispoOrderStatus $to,
    ): void {
        $this->assertTrue(DispoOrderStatusTransition::canTransition($from, $to));
        $this->assertTrue(DispoOrderStatusTransition::isOperationalTransition($from, $to));
    }

    /**
     * @return list<array{0: DispoOrderStatus, 1: DispoOrderStatus}>
     */
    public static function forbiddenOperationalProvider(): array
    {
        return [
            [DispoOrderStatus::AtDisposition, DispoOrderStatus::Disposed],
            [DispoOrderStatus::AtDisposition, DispoOrderStatus::Completed],
            [DispoOrderStatus::InProgress, DispoOrderStatus::Completed],
            [DispoOrderStatus::InProgress, DispoOrderStatus::Cancelled],
            [DispoOrderStatus::MaterialMissing, DispoOrderStatus::Disposed],
            [DispoOrderStatus::Disposed, DispoOrderStatus::Completed],
            [DispoOrderStatus::Disposed, DispoOrderStatus::Cancelled],
            [DispoOrderStatus::Completed, DispoOrderStatus::InProgress],
            [DispoOrderStatus::ApprovalRejected, DispoOrderStatus::InProgress],
            [DispoOrderStatus::Draft, DispoOrderStatus::InProgress],
            [DispoOrderStatus::AwaitingSalesApproval, DispoOrderStatus::InProgress],
            [DispoOrderStatus::InProgress, DispoOrderStatus::InProgress],
        ];
    }

    #[DataProvider('forbiddenOperationalProvider')]
    public function test_forbidden_operational_transitions(
        DispoOrderStatus $from,
        DispoOrderStatus $to,
    ): void {
        $this->assertFalse(DispoOrderStatusTransition::canTransition($from, $to));
        $this->assertFalse(DispoOrderStatusTransition::isOperationalTransition($from, $to));
    }

    /**
     * @return list<array{0: DispoOrderStatus, 1: DispoOrderStatus}>
     */
    public static function allowedSalesInquiryProvider(): array
    {
        return [
            [DispoOrderStatus::AtDisposition, DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::InProgress, DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::MaterialMissing, DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::MaterialReceived, DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::SalesInquiry, DispoOrderStatus::AtDisposition],
        ];
    }

    #[DataProvider('allowedSalesInquiryProvider')]
    public function test_allowed_sales_inquiry_transitions(
        DispoOrderStatus $from,
        DispoOrderStatus $to,
    ): void {
        $this->assertTrue(DispoOrderStatusTransition::canTransition($from, $to));
        $this->assertTrue(DispoOrderStatusTransition::isSalesInquiryTransition($from, $to));
        $this->assertFalse(DispoOrderStatusTransition::isOperationalTransition($from, $to));
    }

    /**
     * @return list<array{0: DispoOrderStatus, 1: DispoOrderStatus}>
     */
    public static function forbiddenSalesInquiryProvider(): array
    {
        return [
            [DispoOrderStatus::Draft, DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::AwaitingSalesApproval, DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::ApprovalRejected, DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::Disposed, DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::Completed, DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::Cancelled, DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::SalesInquiry, DispoOrderStatus::InProgress],
            [DispoOrderStatus::SalesInquiry, DispoOrderStatus::MaterialMissing],
            [DispoOrderStatus::SalesInquiry, DispoOrderStatus::MaterialReceived],
            [DispoOrderStatus::SalesInquiry, DispoOrderStatus::Disposed],
            [DispoOrderStatus::SalesInquiry, DispoOrderStatus::Completed],
            [DispoOrderStatus::SalesInquiry, DispoOrderStatus::Cancelled],
        ];
    }

    #[DataProvider('forbiddenSalesInquiryProvider')]
    public function test_forbidden_sales_inquiry_transitions(
        DispoOrderStatus $from,
        DispoOrderStatus $to,
    ): void {
        $this->assertFalse(DispoOrderStatusTransition::canTransition($from, $to));
        $this->assertFalse(DispoOrderStatusTransition::isSalesInquiryTransition($from, $to));
    }

    public function test_reopen_requires_reason_flag(): void
    {
        $this->assertTrue(DispoOrderStatusTransition::requiresReason(
            DispoOrderStatus::Disposed,
            DispoOrderStatus::InProgress,
        ));
        $this->assertTrue(DispoOrderStatusTransition::isReopen(
            DispoOrderStatus::Disposed,
            DispoOrderStatus::InProgress,
        ));
        $this->assertFalse(DispoOrderStatusTransition::requiresReason(
            DispoOrderStatus::AtDisposition,
            DispoOrderStatus::InProgress,
        ));
    }

    public function test_assert_throws_on_forbidden(): void
    {
        $this->expectException(DispoOrderConflictException::class);
        DispoOrderStatusTransition::assertCanTransition(
            DispoOrderStatus::AtDisposition,
            DispoOrderStatus::Disposed,
        );
    }

    public function test_approval_slice_forbidden_still_hold(): void
    {
        $this->assertFalse(DispoOrderStatusTransition::canTransition(
            DispoOrderStatus::Draft,
            DispoOrderStatus::AtDisposition,
        ));
        $this->assertFalse(DispoOrderStatusTransition::canTransition(
            DispoOrderStatus::ApprovalRejected,
            DispoOrderStatus::Draft,
        ));
    }
}
