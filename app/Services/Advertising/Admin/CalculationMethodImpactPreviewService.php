<?php

namespace App\Services\Advertising\Admin;

use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;

/**
 * ADV-001c3b1: Deaktivierungsvorschau für Berechnungsmethoden.
 *
 * Keine Mutation. Fingerprint kanonisch über Methodensnapshot und aktive
 * Zuordnungen (Kategorie + Medium), sortiert nach Assignment-ID ASC.
 */
final class CalculationMethodImpactPreviewService
{
    public const ACTION_METHOD_DEACTIVATE = 'calculation_method_deactivate';

    /**
     * @return array<string, mixed>
     */
    public function previewDeactivate(CalculationMethod $method): array
    {
        $categoryAssignments = $this->activeCategoryAssignments((int) $method->id);
        $mediumAssignments = $this->activeMediumAssignments((int) $method->id);

        $blocking = [];
        if ($categoryAssignments !== [] || $mediumAssignments !== []) {
            $blocking[] = [
                'code' => 'active_assignments',
                'message' => 'Die Berechnungsmethode kann nicht deaktiviert werden, '
                    .'solange aktive Kategorie- oder Mediumzuordnungen bestehen. '
                    .'Bitte zuerst die Zuordnungen in den jeweiligen Verwaltungsbereichen deaktivieren.',
            ];
        }

        $body = [
            'entity' => 'calculation_method',
            'action' => self::ACTION_METHOD_DEACTIVATE,
            'entity_id' => (int) $method->id,
            'lock_version' => (int) $method->lock_version,
            'intended_change' => [
                'is_active' => false,
            ],
            'current' => [
                'id' => (int) $method->id,
                'key' => $method->key,
                'name' => $method->name,
                'is_active' => (bool) $method->is_active,
                'lock_version' => (int) $method->lock_version,
            ],
            'active_category_assignments' => $categoryAssignments,
            'active_medium_assignments' => $mediumAssignments,
            'blocking_reasons' => $blocking,
            'can_proceed' => $blocking === [],
            'dependency_note' => 'Aktive fachliche Zuordnungen müssen zuerst kontrolliert deaktiviert werden. '
                .'Es gibt keine automatische Kaskade und keine Force-Option.',
        ];

        $body['fingerprint'] = $this->fingerprint($body);

        return $body;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function fingerprint(array $preview): string
    {
        $canonical = $preview;
        unset($canonical['fingerprint']);

        return hash('sha256', json_encode(
            $this->canonicalize($canonical),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function assertFingerprint(array $preview, string $expected): void
    {
        $actual = $this->fingerprint($preview);
        if (! hash_equals($actual, $expected)) {
            throw new CatalogAdminConflictException(
                'Die Auswirkungsvorschau ist veraltet. Bitte Vorschau erneut laden und bestätigen.',
            );
        }
    }

    /**
     * @return list<array{
     *     assignment_id: int,
     *     category_id: int,
     *     category_key: string,
     *     category_name: string,
     *     is_active: bool
     * }>
     */
    public function activeCategoryAssignments(int $methodId): array
    {
        $mapped = AdvertisingCategoryCalculationMethod::query()
            ->with('advertisingCategory')
            ->where('calculation_method_id', $methodId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(static function (AdvertisingCategoryCalculationMethod $row): array {
                $category = $row->advertisingCategory;

                return [
                    'assignment_id' => (int) $row->id,
                    'category_id' => (int) $row->advertising_category_id,
                    'category_key' => $category !== null ? (string) $category->key : '',
                    'category_name' => $category !== null ? (string) $category->name : '',
                    'is_active' => (bool) $row->is_active,
                ];
            })
            ->all();

        return array_values($mapped);
    }

    /**
     * @return list<array{
     *     assignment_id: int,
     *     medium_id: int,
     *     medium_code: string,
     *     medium_name: string,
     *     is_active: bool
     * }>
     */
    public function activeMediumAssignments(int $methodId): array
    {
        $mapped = AdvertisingMediumCalculationMethod::query()
            ->with('advertisingMedium')
            ->where('calculation_method_id', $methodId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(static function (AdvertisingMediumCalculationMethod $row): array {
                $medium = $row->advertisingMedium;

                return [
                    'assignment_id' => (int) $row->id,
                    'medium_id' => (int) $row->advertising_medium_id,
                    'medium_code' => $medium !== null ? (string) $medium->code : '',
                    'medium_name' => $medium !== null ? (string) $medium->name : '',
                    'is_active' => (bool) $row->is_active,
                ];
            })
            ->all();

        return array_values($mapped);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $this->canonicalize($item);
        }

        return $out;
    }
}
