<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    // ============================================
    // RELASI KE MATERIAL PIECES
    // ============================================

    public function pieces(): HasMany
    {
        return $this->hasMany(MaterialPiece::class);
    }

    public function availablePieces(): HasMany
    {
        return $this->hasMany(MaterialPiece::class)
                    ->where('status', 'available');
    }

    public function bookedPieces(): HasMany
    {
        return $this->hasMany(MaterialPiece::class)
                    ->where('status', 'booked');
    }

    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    // ============================================
    // UPDATE SUMMARY STOK
    // ============================================

    /**
     * Update summary stok dari pieces
     * Dipanggil otomatis oleh MaterialPiece saat ada perubahan
     */
    public function updateStokSummary()
    {
        $this->update([
            'stok' => (int) $this->pieces()
                ->whereIn('status', ['available', 'booked'])
                ->sum('panjang'),
            
            'booked' => (int) $this->pieces()
                ->where('status', 'booked')
                ->sum('panjang'),
            
            'stok_rusak' => (int) $this->pieces()
                ->where('status', 'damaged')
                ->sum('panjang'),
        ]);
    }

    // ============================================
    // ATTRIBUTE HELPERS (TETAP SEPERTI DULU)
    // ============================================

    /**
     * Stok yang kondisinya LAYAK PAKAI
     * = Stok Total dikurangi yang Rusak
     */
    public function getStokLayakAttribute(): int
    {
        return $this->stok - $this->stok_rusak;
    }

    /**
     * Stok yang bisa dipakai proyek
     * = Stok Layak dikurangi yang sudah di-Booking
     */
    public function getAvailableAttribute(): int
    {
        return $this->stok_layak - $this->booked;
    }

    // ============================================
    // USER LOG (CCTV)
    // ============================================

    protected static function booted()
    {
        static::created(function ($item) {
            if (auth()->check()) {
                \App\Models\UserLog::create([
                    'user_id'     => auth()->id(),
                    'action'      => 'Create',
                    'module'      => 'Material',
                    'description' => 'Menambahkan material baru: ' . $item->kode_material,
                    'ip_address'  => request()->ip(),
                ]);
            }
        });

        static::updated(function ($item) {
            if (auth()->check()) {
                $changes = $item->getChanges();
                $detailPerubahan = [];

                foreach ($changes as $kolom => $nilaiBaru) {
                    if ($kolom !== 'updated_at') {
                        $nilaiLama = $item->getOriginal($kolom);
                        $detailPerubahan[] = "{$kolom} (dari '{$nilaiLama}' ke '{$nilaiBaru}')";
                    }
                }

                $teksPerubahan = implode(', ', $detailPerubahan);

                \App\Models\UserLog::create([
                    'user_id'     => auth()->id(),
                    'action'      => 'Update',
                    'module'      => 'Material',
                    'description' => 'Mengubah data material ' . $item->kode_material . ' : ' . $teksPerubahan,
                    'ip_address'  => request()->ip(),
                ]);
            }
        });

        static::deleted(function ($item) {
            if (auth()->check()) {
                \App\Models\UserLog::create([
                    'user_id'     => auth()->id(),
                    'action'      => 'Delete',
                    'module'      => 'Material',
                    'description' => 'Menghapus material: ' . $item->kode_material,
                    'ip_address'  => request()->ip(),
                ]);
            }
        });
    }
}