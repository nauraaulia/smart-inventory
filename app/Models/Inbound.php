<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inbound extends Model
{
    protected $fillable = [
        'material_id', 'quantity', 'entry_date', 'supplier_id',
        'shelf_location_id', 'satuan', 'penerima', 'created_by', 
        'updated_by', 'kategori', 'jenis_inbound', 'kondisi', 'keterangan',
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

    protected static function booted()
    {
        static::creating(function ($inbound) {
            if (auth()->check()) {
                $inbound->created_by = auth()->id();
            }
        });

        static::updating(function ($inbound) {
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

        // ── 🔥 BARU: Auto-create MaterialPiece ──
        \App\Models\MaterialPiece::create([
            'material_id' => $inbound->material_id,
            'panjang' => $inbound->quantity,
            'status' => ($inbound->jenis_inbound === 'Retur' && in_array($inbound->kondisi, ['Cacat Ringan', 'Cacat Berat'])) 
                ? 'damaged' 
                : 'available',
            'sumber' => ($inbound->jenis_inbound === 'Retur') ? 'retur_proyek' : 'inbound_baru',
            'shelf_location_id' => $inbound->shelf_location_id,
            'inbound_id' => $inbound->id,
            'keterangan' => $inbound->keterangan ?? 
                ($inbound->jenis_inbound === 'Retur' 
                    ? "Retur dengan kondisi: {$inbound->kondisi}" 
                    : "Inbound baru dari supplier"),
        ]);
    }

    // CCTV (tetap sama)
    if (auth()->check()) {
        \App\Models\UserLog::create([
            'user_id'     => auth()->id(),
            'action'      => 'Create',
            'module'      => 'Inbound',
            'description' => "Mencatat Inbound [{$inbound->jenis_inbound}] Kondisi: [{$inbound->kondisi}] - ID: {$inbound->id}",
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
    // DELETE
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
}