<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTemplate extends Model
{
    use HasFactory;

    protected $guarded = []; 
    
        public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
        {
        // Pastikan nama relasi ini sama dengan yang ada di ProjectTemplateItem
                return $this->hasMany(ProjectTemplateItem::class);
        }
    
    protected static function booted()
    {
        parent::booted();

        static::created(function ($item) {
            if (auth()->check()) {
                \App\Models\UserLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'Create',
                    'module' => 'Project Template',
                    'description' => 'Menambahkan template baru: ' . $item->kode_template,
                    'ip_address' => request()->ip(),
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
                    'user_id' => auth()->id(),
                    'action' => 'Update',
                    'module' => 'Project Template',
                    'description' => 'Mengubah data template ' . $item->kode_template . ' : ' . $teksPerubahan,
                    'ip_address' => request()->ip(),
                ]);
            }
        });


        static::deleted(function ($item) {
            if (auth()->check()) {
                \App\Models\UserLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'Delete',
                    'module' => 'Project Template',
                    'description' => 'Menghapus template: ' . $item->kode_template,
                    'ip_address' => request()->ip(),
                ]);
            }
        });

        
    }
}