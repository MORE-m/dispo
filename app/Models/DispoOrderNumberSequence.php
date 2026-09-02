<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $year
 * @property int $last_seq
 */
class DispoOrderNumberSequence extends Model
{
    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $fillable = [
        'year',
        'last_seq',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_seq' => 'integer',
        ];
    }
}
