<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One verified delivery from a provider. Written only by FazerCardsWebhook.
 */
class WebhookEvent extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}