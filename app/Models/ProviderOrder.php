<?php

namespace App\Models;

use App\Enums\ProviderOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderOrder extends Model
{
    /** Written only by OrderService / OrderProcessor. */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => ProviderOrderStatus::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
            'attempts' => 'integer',
            'submitted_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}