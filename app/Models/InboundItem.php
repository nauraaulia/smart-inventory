<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
        'panjang_per_piece' => 'decimal:2',
        'is_potongan' => 'boolean',
    ];

    // ── Relasi ──────────────────────────────────

    public function inbound(): BelongsTo
    {
        return $this->belongsTo(Inbound::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function shelfLocation(): BelongsTo
    {
        return $this->belongsTo(ShelfLocation::class);
    }
}