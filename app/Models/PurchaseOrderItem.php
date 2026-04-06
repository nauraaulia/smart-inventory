<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty_system' => 'decimal:2',
        'qty_order' => 'decimal:2',
        'qty_received' => 'decimal:2',
        'pr_ids' => 'array',
        'project_ids' => 'array',
        'project_codes' => 'array',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function getQtyOrderRemainingAttribute(): float
    {
        return max(0, (float) $this->qty_order - (float) ($this->qty_received ?? 0));
    }
}
