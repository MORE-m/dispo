<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\OaTargetingCategory;
use App\Models\OaTargetingDefinition;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-4a: MySQL-Persistenz für minimum_tkp_surcharge.
 */
class PoCalcTkp1d4c4aMysqlSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function minimum_tkp_surcharge_persists_on_mysql_with_default_zero(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only schema persistence');
        }

        $this->assertTrue(Schema::hasColumn('oa_targeting_definitions', 'minimum_tkp_surcharge'));

        OaTargetingDefinition::query()->create([
            'code' => 'mysql_min_default',
            'name' => 'MySQL Default',
            'category' => OaTargetingCategory::Dmp,
            'tkp_surcharge' => '25.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        $defaultRow = OaTargetingDefinition::query()->where('code', 'mysql_min_default')->firstOrFail();
        $this->assertSame('0.0000', (string) $defaultRow->minimum_tkp_surcharge);

        OaTargetingDefinition::query()->create([
            'code' => 'mysql_min_set',
            'name' => 'MySQL Set',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '15.0000',
            'minimum_tkp_surcharge' => '3.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        $setRow = OaTargetingDefinition::query()->where('code', 'mysql_min_set')->firstOrFail();
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $setRow->minimum_tkp_surcharge, '3.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $setRow->tkp_surcharge, '15.0000'));
    }
}
