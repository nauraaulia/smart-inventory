<?php

namespace App\Models;

use App\Jobs\ProsesKebutuhanProyekJob;
use App\Services\Inventory\InboundAutoAllocationService;
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

        // Saat stok piece bertambah/hilang, sinkron ulang AI task project yang terdampak.
        static::created(function (MaterialPiece $piece): void {
            if ($piece->status === 'available') {
                app(InboundAutoAllocationService::class)->allocatePiece($piece);
            }

            self::dispatchAffectedProjectSync((int) $piece->material_id);
        });

        static::deleted(function ($piece) {
            if ($piece->material) {
                $piece->material->updateStokSummary();
            }

            self::dispatchAffectedProjectSync((int) $piece->material_id);
        });

        static::restored(function (MaterialPiece $piece): void {
            if ($piece->material) {
                $piece->material->updateStokSummary();
            }

            self::dispatchAffectedProjectSync((int) $piece->material_id);
        });
    }

    private static function dispatchAffectedProjectSync(int $materialId): void
    {
        if ($materialId <= 0) {
            return;
        }

        $projectIds = Project::query()
            ->whereIn('status', ['Pending', 'Approved', 'Processing'])
            ->whereHas('items', fn ($query) => $query->where('material_id', $materialId))
            ->whereIn('id', Task::query()
                ->select('related_id')
                ->where('type', 'ai_booking_suggestion')
                ->where('related_type', 'project')
                ->where('status', 'pending')
                ->whereNotNull('related_id'))
            ->pluck('id')
            ->all();

        foreach ($projectIds as $projectId) {
            ProsesKebutuhanProyekJob::dispatchAfterResponse((int) $projectId);
        }
    }
}