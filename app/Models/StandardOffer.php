<?php

namespace App\Models;

use App\Enums\StandardOfferVersionStatus;
use Database\Factories\StandardOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Kundenlose Kalkulationsvorlage (STD-001).
 *
 * @property int $id
 * @property string $number
 * @property int $number_year
 * @property int $number_seq
 * @property string $title
 * @property int $lock_version
 * @property int $created_by
 */
class StandardOffer extends Model
{
    /** @use HasFactory<StandardOfferFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'number_year',
        'number_seq',
        'title',
        'lock_version',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number_year' => 'integer',
            'number_seq' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<StandardOfferVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(StandardOfferVersion::class)->orderByDesc('version_number');
    }

    /**
     * @return HasOne<StandardOfferVersion, $this>
     */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(StandardOfferVersion::class)
            ->where('status', StandardOfferVersionStatus::Published->value);
    }

    /**
     * @return HasOne<StandardOfferVersion, $this>
     */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(StandardOfferVersion::class)
            ->where('status', StandardOfferVersionStatus::Draft->value);
    }
}
