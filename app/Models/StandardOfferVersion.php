<?php

namespace App\Models;

use App\Enums\StandardOfferVersionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\StandardOfferVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $standard_offer_id
 * @property int $version_number
 * @property StandardOfferVersionStatus $status
 * @property string $title
 * @property int $author_id
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $archived_at
 * @property array<string, mixed>|null $draft_payload
 * @property array<string, mixed>|null $frozen_materialization
 * @property int|null $configuration_snapshot_id
 * @property int $lock_version
 */
class StandardOfferVersion extends Model
{
    /** @use HasFactory<StandardOfferVersionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'standard_offer_id',
        'version_number',
        'status',
        'title',
        'author_id',
        'published_at',
        'archived_at',
        'draft_payload',
        'frozen_materialization',
        'configuration_snapshot_id',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StandardOfferVersionStatus::class,
            'version_number' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'draft_payload' => 'array',
            'frozen_materialization' => 'array',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StandardOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(StandardOffer::class, 'standard_offer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<ConfigurationSnapshot, $this>
     */
    public function configurationSnapshot(): BelongsTo
    {
        return $this->belongsTo(ConfigurationSnapshot::class);
    }
}
