<?php

namespace Tests\Unit\OnlineAudio;

use App\Enums\OaTargetingCategory;
use App\Models\OaTargetingDefinition;
use App\Services\OnlineAudio\Import\OaTargetingDefinitionImporter;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-4a: Targeting minimum_tkp_surcharge Importvertrag.
 *
 * Engine/Under-Min unverändert – nur Stammdaten + Importer.
 */
class PoCalcTkp1d4c4aTargetingMinimumImportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function schema_has_minimum_tkp_surcharge_default_zero(): void
    {
        $this->assertTrue(Schema::hasColumn('oa_targeting_definitions', 'minimum_tkp_surcharge'));

        $row = OaTargetingDefinition::query()->create([
            'code' => 'd4c4a_default',
            'name' => 'Default Min',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        $fresh = $row->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $fresh->minimum_tkp_surcharge, '0.0000'));
    }

    #[Test]
    public function import_without_minimum_field_defaults_to_zero(): void
    {
        $importer = app(OaTargetingDefinitionImporter::class);

        $result = $importer->import([[
            'code' => 'legacy_tgt',
            'name' => 'Legacy Without Min',
            'category' => OaTargetingCategory::Dmp->value,
            'tkp_surcharge' => '25.0000',
        ]]);

        $this->assertSame(1, $result['created']);
        $row = OaTargetingDefinition::query()->firstOrFail();
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $row->tkp_surcharge, '25.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $row->minimum_tkp_surcharge, '0.0000'));
    }

    #[Test]
    public function import_with_list_and_minimum_surcharges(): void
    {
        $importer = app(OaTargetingDefinitionImporter::class);

        $result = $importer->import([[
            'code' => 'dmp',
            'name' => 'DMP',
            'category' => OaTargetingCategory::Dmp->value,
            'tkp_surcharge' => '25.0000',
            'minimum_tkp_surcharge' => '5.0000',
        ]]);

        $this->assertSame(1, $result['created']);
        $row = OaTargetingDefinition::query()->firstOrFail();
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $row->tkp_surcharge, '25.0000'));
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $row->minimum_tkp_surcharge, '5.0000'));
    }

    #[Test]
    public function import_rejects_negative_minimum_and_excess_scale(): void
    {
        $importer = app(OaTargetingDefinitionImporter::class);

        try {
            $importer->import([[
                'code' => 'bad_neg',
                'name' => 'Bad',
                'category' => OaTargetingCategory::Technisch->value,
                'tkp_surcharge' => '15.0000',
                'minimum_tkp_surcharge' => '-1.0000',
            ]]);
            $this->fail('Expected negative rejection');
        } catch (OaTkpStammdatenImportException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn (string $msg): bool => str_contains($msg, 'minimum_tkp_surcharge')),
            );
        }

        try {
            $importer->import([[
                'code' => 'bad_scale',
                'name' => 'Bad Scale',
                'category' => OaTargetingCategory::Technisch->value,
                'tkp_surcharge' => '15.0000',
                'minimum_tkp_surcharge' => '3.00001',
            ]]);
            $this->fail('Expected scale rejection');
        } catch (OaTkpStammdatenImportException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn (string $msg): bool => str_contains($msg, 'minimum_tkp_surcharge')),
            );
        }

        $this->assertSame(0, OaTargetingDefinition::query()->count());
    }

    #[Test]
    public function import_rejects_minimum_above_list_surcharge(): void
    {
        $importer = app(OaTargetingDefinitionImporter::class);

        try {
            $importer->import([[
                'code' => 'bad_order',
                'name' => 'Bad Order',
                'category' => OaTargetingCategory::Technisch->value,
                'tkp_surcharge' => '3.0000',
                'minimum_tkp_surcharge' => '5.0000',
            ]]);
            $this->fail('Expected min > list rejection');
        } catch (OaTkpStammdatenImportException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn (string $msg): bool => str_contains($msg, 'minimum_tkp_surcharge')),
            );
        }

        $this->assertSame(0, OaTargetingDefinition::query()->count());
    }

    #[Test]
    public function import_rejects_float_and_allows_equal_minimum(): void
    {
        $importer = app(OaTargetingDefinitionImporter::class);

        try {
            $importer->import([[
                'code' => 'bad_float',
                'name' => 'Float',
                'category' => OaTargetingCategory::Technisch->value,
                'tkp_surcharge' => '15.0000',
                'minimum_tkp_surcharge' => 3.0,
            ]]);
            $this->fail('Expected float rejection');
        } catch (OaTkpStammdatenImportException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn (string $msg): bool => str_contains($msg, 'minimum_tkp_surcharge')),
            );
        }

        $ok = $importer->import([[
            'code' => 'equal_min',
            'name' => 'Equal',
            'category' => OaTargetingCategory::Technisch->value,
            'tkp_surcharge' => '15.0000',
            'minimum_tkp_surcharge' => '15.0000',
        ]]);
        $this->assertSame(1, $ok['created']);
        $this->assertSame(0, OaTkpImportDecimal::compare(
            (string) OaTargetingDefinition::query()->firstOrFail()->minimum_tkp_surcharge,
            '15.0000',
        ));
    }

    #[Test]
    public function dry_run_does_not_mutate(): void
    {
        $importer = app(OaTargetingDefinitionImporter::class);

        $dry = $importer->import([[
            'code' => 'dry_tgt',
            'name' => 'Dry',
            'category' => OaTargetingCategory::Dmp->value,
            'tkp_surcharge' => '25.0000',
            'minimum_tkp_surcharge' => '5.0000',
        ]], dryRun: true);

        $this->assertTrue($dry['dry_run']);
        $this->assertSame(0, $dry['created']);
        $this->assertSame(0, OaTargetingDefinition::query()->count());
    }
}
