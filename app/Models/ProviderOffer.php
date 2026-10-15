<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local mirror of a provider offer. Written only by CatalogSyncService, never from user input.
 */
class ProviderOffer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider_cost' => 'decimal:4',
            'is_available' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}