<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class Supplier extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = [];

    protected static function booted()
    {
        // 1. CCTV Saat supplier Dibuat
        static::created(function ($item) {
            if (auth()->check()) {
                \App\Models\UserLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'Create',
                    'module' => 'Supplier',
                    'description' => 'Menambahkan supplier baru: ' . $item->kode_klien,
                    'ip_address' => request()->ip(),
                ]);
            }
        });

        // 2. CCTV Saat Supplier Diedit (Super Detail)
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
                    'module' => 'Supplier',
                    'description' => 'Mengubah data supplier ' . $item->kode_supplier . ' : ' . $teksPerubahan,
                    'ip_address' => request()->ip(),
                ]);
            }
        });

        // 3. CCTV Saat Supplier Dihapus
        static::deleted(function ($item) {
            if (auth()->check()) {
                \App\Models\UserLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'Delete',
                    'module' => 'Supplier',
                    'description' => 'Menghapus supplier: ' . $item->kode_supplier,
                    'ip_address' => request()->ip(),
                ]);
            }
        });
    }
}
