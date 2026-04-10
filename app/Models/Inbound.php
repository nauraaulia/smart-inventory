<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Inbound extends Model
{
    protected $fillable = [
        'material_id', 'quantity', 'entry_date', 'supplier_id',
        'shelf_location_id', 'satuan', 'penerima', 'created_by', 
        'updated_by', 'jenis_inbound', 'is_opening_balance', 'kondisi', 'keterangan', 'project_id',
        'purchase_order_id', 'purchase_order_item_id',
    ];

    protected $casts = [
        'is_opening_balance' => 'boolean',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function dataSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function shelfLocation(): BelongsTo
    {
        return $this->belongsTo(ShelfLocation::class, 'shelf_location_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    protected static function booted()
    {
        $ensureShelfLocation = function ($inbound): void {
            if (empty($inbound->shelf_location_id)) {
                throw ValidationException::withMessages([
                    'shelf_location_id' => 'Lokasi rak wajib diisi untuk inbound.',
                ]);
            }
        };

        static::creating(function ($inbound) use ($ensureShelfLocation) {
            $ensureShelfLocation($inbound);

            if (auth()->check()) {
                $inbound->created_by = auth()->id();
            }
        });

        static::updating(function ($inbound) use ($ensureShelfLocation) {
            $ensureShelfLocation($inbound);

            if (auth()->check()) {
                $inbound->updated_by = auth()->id();
            }
        });

    // =====================================================
// CREATE
// =====================================================
static::created(function ($inbound) {
    if ($inbound->material) {

        // ── Update stok summary (tetap seperti sebelumnya) ──
        if ($inbound->jenis_inbound === 'Baru') {
            $inbound->material->increment('stok', $inbound->quantity);

        } elseif ($inbound->jenis_inbound === 'Retur') {
            if ($inbound->kondisi === 'Bagus') {
                $inbound->material->increment('stok', $inbound->quantity);

            } elseif (in_array($inbound->kondisi, ['Cacat Ringan', 'Cacat Berat'])) {
                $inbound->material->increment('stok', $inbound->quantity);
                $inbound->material->increment('stok_rusak', $inbound->quantity);
            }
        }

        self::createPiecesFromInbound($inbound);
    }

    // CCTV (tetap sama)
    if (auth()->check()) {
        \App\Models\UserLog::create([
            'user_id'     => auth()->id(),
            'action'      => 'Create',
            'module'      => 'Inbound',
            'description' => "Mencatat Inbound [{$inbound->jenis_inbound}]" . ($inbound->is_opening_balance ? ' [Opening Balance]' : '') . " Kondisi: [{$inbound->kondisi}] - ID: {$inbound->id}",
            'ip_address'  => request()->ip(),
        ]);
    }
});

    // =====================================================
    // UPDATE
    // =====================================================
    static::updated(function ($inbound) {
        $oldKondisi = $inbound->getOriginal('kondisi');
        $newKondisi = $inbound->kondisi;
        $oldJenis   = $inbound->getOriginal('jenis_inbound');
        $newJenis   = $inbound->jenis_inbound;
        $oldQty     = $inbound->getOriginal('quantity');
        $newQty     = $inbound->quantity;

        // Helper: apakah kondisi ini "Rusak"?
        $isRusak = fn($kondisi) => in_array($kondisi, ['Cacat Ringan', 'Cacat Berat']);

        // Helper: apakah kondisi ini nambah stok layak?
        $wasStockable = ($oldJenis === 'Baru' || ($oldJenis === 'Retur' && $oldKondisi === 'Bagus'));
        $isStockable  = ($newJenis === 'Baru' || ($newJenis === 'Retur' && $newKondisi === 'Bagus'));

        // Helper: apakah kondisi ini masuk stok_rusak?
        $wasRusak = ($oldJenis === 'Retur' && $isRusak($oldKondisi));
        $isRusakNow = ($newJenis === 'Retur' && $isRusak($newKondisi));

        $material = $inbound->material;
        if (!$material) return;

        // ── Urus stok total & stok_rusak ──────────────────

        if ($wasStockable && $isStockable) {
            // Bagus → Bagus, ganti qty
            $material->increment('stok', $newQty - $oldQty);

        } elseif ($wasRusak && $isRusakNow) {
            // Rusak → Rusak, ganti qty
            $material->increment('stok', $newQty - $oldQty);
            $material->increment('stok_rusak', $newQty - $oldQty);

        } elseif ($wasStockable && $isRusakNow) {
            // Bagus → Rusak
            // Tarik stok yg dulu, masukin sebagai rusak
            $material->decrement('stok', $oldQty);       // stok total turun dulu
            $material->increment('stok', $newQty);        // stok total naik lagi (qty baru)
            $material->increment('stok_rusak', $newQty);  // tandai sebagai rusak

        } elseif ($wasRusak && $isStockable) {
            // Rusak → Bagus
            // Hapus flag rusak, masukin ke stok layak
            $material->decrement('stok', $oldQty);        // stok total turun dulu
            $material->decrement('stok_rusak', $oldQty);  // hapus dari stok_rusak
            $material->increment('stok', $newQty);         // stok total naik (qty baru, layak)

        } elseif (!$wasStockable && !$wasRusak && $isStockable) {
            // Dari tidak ada → Bagus
            $material->increment('stok', $newQty);

        } elseif (!$wasStockable && !$wasRusak && $isRusakNow) {
            // Dari tidak ada → Rusak
            $material->increment('stok', $newQty);
            $material->increment('stok_rusak', $newQty);

        } elseif ($wasStockable && !$isStockable && !$isRusakNow) {
            // Dari Bagus → tidak ada
            $material->decrement('stok', $oldQty);

        } elseif ($wasRusak && !$isStockable && !$isRusakNow) {
            // Dari Rusak → tidak ada
            $material->decrement('stok', $oldQty);
            $material->decrement('stok_rusak', $oldQty);
        }

        // CCTV
        if (auth()->check()) {
            \App\Models\UserLog::create([
                'user_id'     => auth()->id(),
                'action'      => 'Update',
                'module'      => 'Inbound',
                'description' => "Edit Inbound ID: {$inbound->id} | Kondisi: {$oldKondisi} → {$newKondisi} | Qty: {$oldQty} → {$newQty}",
                'ip_address'  => request()->ip(),
            ]);
        }
    });

    // =====================================================
    // hapus
    // =====================================================
    static::deleted(function ($inbound) {
        if ($inbound->material) {

            if ($inbound->jenis_inbound === 'Baru') {
                $inbound->material->decrement('stok', $inbound->quantity);

            } elseif ($inbound->jenis_inbound === 'Retur') {

                if ($inbound->kondisi === 'Bagus') {
                    // Tarik balik stok layak
                    $inbound->material->decrement('stok', $inbound->quantity);

                } elseif (in_array($inbound->kondisi, ['Cacat Ringan', 'Cacat Berat'])) {
                    // Tarik balik stok total DAN stok_rusak
                    $inbound->material->decrement('stok', $inbound->quantity);
                    $inbound->material->decrement('stok_rusak', $inbound->quantity);
                }
            }
        }

        // CCTV
        if (auth()->check()) {
            \App\Models\UserLog::create([
                'user_id'     => auth()->id(),
                'action'      => 'Delete',
                'module'      => 'Inbound',
                'description' => "Hapus Inbound [{$inbound->jenis_inbound}] Kondisi: [{$inbound->kondisi}] - ID: {$inbound->id}",
                'ip_address'  => request()->ip(),
            ]);
        }
    });
}

    private static function createPiecesFromInbound(Inbound $inbound): void
    {
        $status = ($inbound->jenis_inbound === 'Retur' && in_array($inbound->kondisi, ['Cacat Ringan', 'Cacat Berat'], true))
            ? 'damaged'
            : 'available';

        $sumber = ($inbound->jenis_inbound === 'Retur')
            ? 'retur_proyek'
            : ($inbound->is_opening_balance ? 'opening_balance' : 'inbound_baru');

        $keteranganDasar = $inbound->keterangan
            ?? ($inbound->is_opening_balance
                ? 'Input opening balance awal stok.'
                : ($inbound->jenis_inbound === 'Retur'
                    ? "Retur dengan kondisi: {$inbound->kondisi}"
                    : 'Inbound baru dari supplier'));

        $totalQty = (float) $inbound->quantity;
        $rollLength = self::resolveAutoSplitRollLength($inbound, $totalQty);

        // Jika panjang roll standar tidak tersedia/terdeteksi, simpan sebagai 1 piece seperti perilaku sebelumnya.
        if ($rollLength <= 0 || $totalQty <= $rollLength) {
            \App\Models\MaterialPiece::create([
                'material_id' => $inbound->material_id,
                'panjang' => $totalQty,
                'status' => $status,
                'sumber' => $sumber,
                'shelf_location_id' => $inbound->shelf_location_id,
                'supplier_id' => $inbound->supplier_id,
                'inbound_id' => $inbound->id,
                'keterangan' => $keteranganDasar,
            ]);

            return;
        }

        $fullRollCount = (int) floor($totalQty / $rollLength);
        $remainder = round($totalQty - ($fullRollCount * $rollLength), 2);

        for ($i = 0; $i < $fullRollCount; $i++) {
            \App\Models\MaterialPiece::create([
                'material_id' => $inbound->material_id,
                'panjang' => number_format($rollLength, 2, '.', ''),
                'status' => $status,
                'sumber' => $sumber,
                'shelf_location_id' => $inbound->shelf_location_id,
                'supplier_id' => $inbound->supplier_id,
                'inbound_id' => $inbound->id,
                'keterangan' => $keteranganDasar . " | Roll utuh dari auto-split inbound ({$rollLength}).",
            ]);
        }

        if ($remainder > 0) {
            \App\Models\MaterialPiece::create([
                'material_id' => $inbound->material_id,
                'panjang' => number_format($remainder, 2, '.', ''),
                'status' => $status,
                'sumber' => 'split_inbound_remainder',
                'shelf_location_id' => $inbound->shelf_location_id,
                'supplier_id' => $inbound->supplier_id,
                'inbound_id' => $inbound->id,
                'keterangan' => $keteranganDasar . ' | Sisa potongan dari auto-split inbound.',
            ]);
        }
    }

    private static function resolveAutoSplitRollLength(Inbound $inbound, float $totalQty): float
    {
        $configured = (float) ($inbound->material?->default_roll_length ?? 0);

        if ($configured > 0) {
            return $configured;
        }

        // Fallback: ikuti pola data historis piece inbound sebelumnya untuk material yang sama.
        $historicalLengths = \App\Models\MaterialPiece::query()
            ->where('material_id', $inbound->material_id)
            ->whereIn('sumber', ['inbound_baru', 'opening_balance'])
            ->whereIn('status', ['available', 'booked', 'used'])
            ->where('panjang', '>', 0)
            ->pluck('panjang')
            ->map(fn ($value) => (float) $value)
            ->filter(fn (float $value) => $value <= $totalQty)
            ->values();

        if ($historicalLengths->isEmpty()) {
            return 0;
        }

        $preferred = $historicalLengths
            ->map(fn (float $value) => number_format($value, 2, '.', ''))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->map(fn (string $value) => (float) $value)
            ->first();

        return (float) ($preferred ?? 0);
    }
}