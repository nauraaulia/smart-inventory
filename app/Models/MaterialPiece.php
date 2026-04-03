<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialPiece extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'panjang' => 'decimal:2',
    ];

    // ── Relasi ──────────────────────────────────

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function shelfLocation(): BelongsTo
    {
        return $this->belongsTo(ShelfLocation::class);
    }

    public function bookedForProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'booked_for_project_id');
    }

    public function inbound(): BelongsTo
    {
        return $this->belongsTo(Inbound::class);
    }

    // ── Scopes ──────────────────────────────────

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeBooked($query)
    {
        return $query->where('status', 'booked');
    }

    public function scopeUtuh($query, $threshold = 250)
    {
        return $query->where('sumber', 'inbound_baru')
                     ->where('panjang', '>=', $threshold);
    }

    public function scopePotongan($query, $threshold = 250)
    {
        return $query->where(function($q) use ($threshold) {
            $q->where('sumber', '!=', 'inbound_baru')
              ->orWhere('panjang', '<', $threshold);
        });
    }

    // ── Auto Update Summary di Material ─────────

    protected static function booted()
    {
        // Setiap kali piece berubah, update summary di tabel materials
        static::saved(function ($piece) {
            $piece->material->updateStokSummary();
        });

        static::deleted(function ($piece) {
            if ($piece->material) {
                $piece->material->updateStokSummary();
            }
        });
    }
}