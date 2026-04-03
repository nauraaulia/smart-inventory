<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Klien extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [];

    protected static function booted()
    {
        // 1. CCTV Saat Klien Dibuat
        static::created(function ($item) {
            if (auth()->check()) {
                \App\Models\UserLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'Create',
                    'module' => 'Klien',
                    'description' => 'Menambahkan klien baru: ' . $item->kode_klien,
                    'ip_address' => request()->ip(),
                ]);
            }
        });

        // 2. CCTV Saat Klien Diedit (Super Detail)
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
                    'user_id' => auth()->id(),
                    'action' => 'Update',
                    'module' => 'Klien',
                    'description' => 'Mengubah data klien ' . $item->kode_klien . ' : ' . $teksPerubahan,
                    'ip_address' => request()->ip(),
                ]);
            }
        });

        // 3. CCTV Saat Klien Dihapus
        static::deleted(function ($item) {
            if (auth()->check()) {
                \App\Models\UserLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'Delete',
                    'module' => 'Klien',
                    'description' => 'Menghapus klien: ' . $item->kode_klien,
                    'ip_address' => request()->ip(),
                ]);
            }
        });
    }
}
