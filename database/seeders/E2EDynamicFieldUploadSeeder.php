<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\DayGroup;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\DispoOrder;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderCustomerConfirmationService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * E2E-Seeder BL-P9-01c dynamische Datei-Felder (Port 8042).
 */
class E2EDynamicFieldUploadSeeder extends Seeder
{
    public const FIELD_KEY = 'e2e_dyn_anhang';

    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        foreach ([
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
            ['email' => 'sales-b@example.com', 'name' => 'E2E Vertrieb B', 'role' => Role::Sales],
            ['email' => 'disposition@example.com', 'name' => 'E2E Disposition', 'role' => Role::Disposition],
            ['email' => 'pm@example.com', 'name' => 'E2E PM', 'role' => Role::ProductManagement],
            ['email' => 'admin@example.com', 'name' => 'E2E Admin', 'role' => Role::Admin],
            ['email' => 'management@example.com', 'name' => 'E2E Management', 'role' => Role::Management],
        ] as $attrs) {
            User::query()->updateOrCreate(
                ['email' => $attrs['email']],
                [
                    'name' => $attrs['name'],
                    'password' => 'password',
                    'role' => $attrs['role'],
                ],
            );
        }

        if (Organization::query()->exists()) {
            return;
        }

        $organization = Organization::factory()->create(['name' => 'E2E BL-P9-01c']);
        $spotsCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        $classic = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic',
            'name' => 'Spot Classic',
            'category_id' => $spotsCategoryId,
            'kind' => CalculationKind::SpotClassic,
        ]);

        $inventory = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Radio Hamburg',
            'code' => 'RH901C',
            'sort' => 1,
            'logo_path' => null,
        ]);

        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $classic->id,
        ]);

        $year = PriceListCalendar::currentYear();
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => $year,
            'version' => 'e2e-blp901c-'.$inventory->code,
            'valid_from' => now()->toDateString(),
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '2.0000',
                ]);
            }
        }

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->activateFileFieldOnDispoSet($admin, self::FIELD_KEY);

        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::DISPO_ORDER_CORE);

        $sales = User::query()->where('email', 'sales@example.com')->firstOrFail();
        $approver = User::query()->where('email', 'sales-b@example.com')->firstOrFail();

        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $schemaFp = $freeze->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $classicFp = $freeze->resolveLivePositionSchema((int) $classic->id)['schema_fingerprint'];

        $ctx = compact(
            'sales',
            'approver',
            'inventory',
            'classic',
            'schemaFp',
            'classicFp',
        );

        $draft = $this->createDraftOrder('BLP901C Draft DynField GmbH', $ctx);

        $atDisposition = $this->createApprovedOrder('BLP901C At Disposition GmbH', $ctx);

        File::put(
            database_path('e2e-bl-p9-01c-orders.json'),
            json_encode([
                'draft' => ['id' => $draft->id, 'number' => $draft->number],
                'atDisposition' => [
                    'id' => $atDisposition->id,
                    'number' => $atDisposition->number,
                ],
                'field_key' => self::FIELD_KEY,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    private function activateFileFieldOnDispoSet(User $admin, string $key): FieldDefinition
    {
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'E2E Anhang Dispo',
            'key' => $key,
            'field_type' => FieldType::File,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::DispoOrder,
        ], $admin);

        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::DISPO_ORDER_CORE)->firstOrFail();
        $writer = app(FieldSetVersionAdminWriter::class);
        $draft = $writer->createDraftFromVersion(
            $fieldSet,
            FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail(),
            $admin,
            $fieldSet->lock_version,
        );
        $fieldSet->refresh();
        $writer->addCustomMembership($fieldSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 75,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
        $fieldSet->refresh();
        $writer->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);

        return $definition->fresh(['currentRevision']) ?? $definition;
    }

    /**
     * @param  array{
     *     sales: User,
     *     inventory: Inventory,
     *     classic: AdvertisingMedium,
     *     schemaFp: string,
     *     classicFp: string
     * }  $ctx
     */
    private function createDraftOrder(string $customerName, array $ctx): DispoOrder
    {
        $writer = app(CalculationWriter::class);
        $dispoWriter = app(DispoOrderWriter::class);

        $calc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => $customerName,
            'order_discount_percent' => '0',
            'schema_fingerprint' => $ctx['schemaFp'],
            'positions' => [[
                'inventory_id' => $ctx['inventory']->id,
                'advertising_medium_id' => $ctx['classic']->id,
                'schema_fingerprint' => $ctx['classicFp'],
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
            ]],
        ], $ctx['sales']);

        /** @var list<int> $positionIds */
        $positionIds = array_values($calc->positions()->pluck('id')->all());

        return $dispoWriter->createFromCalculation($calc, $positionIds, $ctx['sales'])->order;
    }

    /**
     * @param  array{
     *     sales: User,
     *     approver: User,
     *     inventory: Inventory,
     *     classic: AdvertisingMedium,
     *     schemaFp: string,
     *     classicFp: string
     * }  $ctx
     */
    private function createApprovedOrder(string $customerName, array $ctx): DispoOrder
    {
        $order = $this->createDraftOrder($customerName, $ctx);
        $confirmations = app(DispoOrderCustomerConfirmationService::class);
        $approvals = app(DispoOrderApprovalService::class);

        $order = $confirmations->update(
            $order,
            $ctx['sales'],
            $order->lock_version,
            true,
            'E2E Ausnahme für DynField-Suite',
        );
        $order = $approvals->submit($order, $ctx['sales'], $order->lock_version);

        return $approvals->approve($order, $ctx['approver'], $order->lock_version, null, true);
    }
}
