<?php

namespace App\Models;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldSetAssignmentTargetLayer;
use Database\Factories\FieldSetAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * DF-3.3a1 / DYN-002 – Zuordnung eines freien Feldsets zu einem Zielkontext.
 *
 * @property int $id
 * @property int $field_set_id
 * @property FieldSetAssignmentTargetLayer $target_layer
 * @property int|null $advertising_category_id
 * @property int|null $advertising_medium_id
 * @property string $target_identity
 * @property FieldAppliesTo $applies_to_process
 * @property bool $is_active
 * @property int $sort
 * @property int $lock_version
 */
class FieldSetAssignment extends Model
{
    /** @use HasFactory<FieldSetAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'field_set_id',
        'target_layer',
        'advertising_category_id',
        'advertising_medium_id',
        'target_identity',
        'applies_to_process',
        'is_active',
        'sort',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_layer' => FieldSetAssignmentTargetLayer::class,
            'applies_to_process' => FieldAppliesTo::class,
            'is_active' => 'boolean',
            'sort' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /**
     * Normalisierte Zielidentität für portable DB-Eindeutigkeit (NOT NULL).
     */
    public static function buildTargetIdentity(
        FieldSetAssignmentTargetLayer $layer,
        ?int $advertisingCategoryId,
        ?int $advertisingMediumId,
    ): string {
        return match ($layer) {
            FieldSetAssignmentTargetLayer::Global => 'g',
            FieldSetAssignmentTargetLayer::AdvertisingCategory => 'c:'.self::requirePositiveId(
                $advertisingCategoryId,
                'advertising_category_id',
            ),
            FieldSetAssignmentTargetLayer::AdvertisingMedium => 'm:'.self::requirePositiveId(
                $advertisingMediumId,
                'advertising_medium_id',
            ),
        };
    }

    /**
     * @return BelongsTo<FieldSet, $this>
     */
    public function fieldSet(): BelongsTo
    {
        return $this->belongsTo(FieldSet::class);
    }

    /**
     * @return BelongsTo<AdvertisingCategory, $this>
     */
    public function advertisingCategory(): BelongsTo
    {
        return $this->belongsTo(AdvertisingCategory::class, 'advertising_category_id');
    }

    /**
     * @return BelongsTo<AdvertisingMedium, $this>
     */
    public function advertisingMedium(): BelongsTo
    {
        return $this->belongsTo(AdvertisingMedium::class, 'advertising_medium_id');
    }

    public function appliesToProcess(FieldAppliesTo $process): bool
    {
        if ($this->applies_to_process === FieldAppliesTo::Both) {
            return true;
        }

        return $this->applies_to_process === $process;
    }

    private static function requirePositiveId(?int $id, string $label): int
    {
        if ($id === null || $id < 1) {
            throw new InvalidArgumentException("{$label} muss gesetzt sein.");
        }

        return $id;
    }
}
