<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local mirror of a provider category. Written only by CatalogSyncService, never from user input.
 */
class ProviderCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}