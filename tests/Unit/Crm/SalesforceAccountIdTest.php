<?php

namespace Tests\Unit\Crm;

use App\Support\Crm\SalesforceAccountId;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SalesforceAccountIdTest extends TestCase
{
    #[Test]
    public function fifteen_and_eighteen_char_forms_share_canonical_identity(): void
    {
        $fifteen = '001xx000003DGbq';
        $eighteen = SalesforceAccountId::toEighteen($fifteen);

        $this->assertSame(18, strlen($eighteen));
        $this->assertSame(
            SalesforceAccountId::normalize($fifteen)['canonical'],
            SalesforceAccountId::normalize($eighteen)['canonical'],
        );
        $this->assertTrue(SalesforceAccountId::sameIdentity($fifteen, $eighteen));
    }

    #[Test]
    public function case_sensitive_fifteen_char_ids_differ(): void
    {
        $a = '001xx000003DGbq';
        $b = '001xx000003dgbq';
        $this->assertNotSame(
            SalesforceAccountId::normalize($a)['canonical'],
            SalesforceAccountId::normalize($b)['canonical'],
        );
        $this->assertFalse(SalesforceAccountId::sameIdentity($a, $b));
    }

    #[Test]
    public function invalid_eighteen_char_checksum_rejected(): void
    {
        $this->assertNull(SalesforceAccountId::normalize('001xx000003DGbqAAA'));
    }
}
