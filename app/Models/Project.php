<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $guarded = [];

    // =========================================
    // 🔑 AUTO GENERATE KODE PROYEK
    // =========================================
    public static function generateKodeProyek(int $templateId): string
    {
        $template = ProjectTemplate::findOrFail($templateId);

        $kategori = $template->kode_kategori;
        $periode  = now()->format('ym');

        $prefix = "PRJ-{$kategori}-{$periode}";

        $lastProject = self::where('kode_proyek', 'like', "{$prefix}-%")
            ->orderByDesc('kode_proyek')
            ->first();

        if ($lastProject) {
            $lastNumber = (int) substr($lastProject->kode_proyek, -4);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('%s-%04d', $prefix, $nextNumber);
    }

    // =========================================
    // 📹 CCTV USER LOG
    // =========================================
    protected static function booted()
{
    // 1. CCTV Saat Proyek Dibuat (Create)
    static::created(function ($item) {
        if (auth()->check()) {
            \App\Models\UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Create',
                'module' => 'Project',
                'description' => 'Membuat proyek baru: ' . $item->kode_proyek . ' - ' . $item->nama_proyek,
                'ip_address' => request()->ip(),
            ]);
        }

        // 🔥 TRIGGER AI: Jalankan setelah project tersimpan
        \App\Jobs\ProsesKebutuhanProyekJob::dispatch($item->id);
    });

    // 2. CCTV Saat Proyek Diedit (Update)
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
                'module' => 'Project',
                'description' => 'Mengubah proyek ' . $item->kode_proyek . ' : ' . $teksPerubahan,
                'ip_address' => request()->ip(),
            ]);
        }
    });

    // 3. CCTV Saat Proyek Dihapus (Delete)
    static::deleted(function ($item) {
        if (auth()->check()) {
            \App\Models\UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Delete',
                'module' => 'Project',
                'description' => 'Menghapus proyek: ' . $item->kode_proyek . ' - ' . $item->nama_proyek,
                'ip_address' => request()->ip(),
            ]);
        }
    });
}

    // =========================================
    // RELATIONSHIPS
    // =========================================
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class, 'project_template_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProjectItem::class);
    }



    
}