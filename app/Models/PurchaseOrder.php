<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'po_date' => 'date',
        'published_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (PurchaseOrder $purchaseOrder): void {
            if (! auth()->check()) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Create',
                'module' => 'Purchase Order',
                'description' => 'Membuat PO '.(string) ($purchaseOrder->po_number ?? ('#'.$purchaseOrder->id)),
                'ip_address' => request()?->ip(),
            ]);
        });

        static::updated(function (PurchaseOrder $purchaseOrder): void {
            if (! auth()->check() || empty($purchaseOrder->getChanges())) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Update',
                'module' => 'Purchase Order',
                'description' => 'Update PO '.(string) ($purchaseOrder->po_number ?? ('#'.$purchaseOrder->id)),
                'ip_address' => request()?->ip(),
            ]);
        });

        static::deleted(function (PurchaseOrder $purchaseOrder): void {
            if (! auth()->check()) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Delete',
                'module' => 'Purchase Order',
                'description' => 'Menghapus PO '.(string) ($purchaseOrder->po_number ?? ('#'.$purchaseOrder->id)),
                'ip_address' => request()?->ip(),
            ]);
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function inbounds(): HasMany
    {
        return $this->hasMany(Inbound::class);
    }

    public function getProgressStatusAttribute(): string
    {
        if ($this->status === 'completed') {
            return 'Completed';
        }

        $receivedTotal = (float) $this->items()->sum('qty_received');

        if ($receivedTotal > 0) {
            return 'Partial';
        }

        return 'Waiting Inbound';
    }
}
