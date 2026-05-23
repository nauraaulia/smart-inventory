<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty' => 'decimal:2',
        'selected_piece_ids' => 'array',
    ];

    public function outbound(): BelongsTo
    {
        return $this->belongsTo(Outbound::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
