<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StandardOfferNumberSequence extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'year';

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
