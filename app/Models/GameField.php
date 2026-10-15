<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameField extends Model
{
    protected $fillable = [
        'provider_field_name',
        'label',
        'type',
        'required',
        'validation_rules',
        'options',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'options' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}