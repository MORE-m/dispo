<?php

namespace Tests\Unit\Crm;

use App\Enums\CrmAccountType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmAccountTypeMappingTest extends TestCase
{
    #[Test]
    #[DataProvider('knownExportTypes')]
    public function maps_known_salesforce_record_types(string $raw, CrmAccountType $expected): void
    {
        $this->assertSame($expected, CrmAccountType::fromExportRecordType($raw));
    }

    /**
     * @return list<array{0: string, 1: CrmAccountType}>
     */
    public static function knownExportTypes(): array
    {
        return [
            ['Account KUNDE', CrmAccountType::Customer],
            ['Account GESELLSCHAFTER', CrmAccountType::Customer],
            ['Account SONSTIGE', CrmAccountType::Customer],
            ['Account AGENTUR', CrmAccountType::Agency],
            ['  Account KUNDE  ', CrmAccountType::Customer],
        ];
    }

    #[Test]
    #[DataProvider('unknownExportTypes')]
    public function unknown_types_remain_null(string $raw): void
    {
        $this->assertNull(CrmAccountType::fromExportRecordType($raw));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unknownExportTypes(): array
    {
        return [
            [''],
            ['Account SONSTIGES'],
            ['Kunde'],
            ['GESELLSCHAFTER'],
            ['Account PARTNER'],
        ];
    }
}
