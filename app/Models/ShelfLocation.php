<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShelfLocation extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::created(function (ShelfLocation $shelfLocation): void {
            if (! auth()->check()) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Create',
                'module' => 'Shelf Location',
                'description' => 'Membuat shelf location '.(string) ($shelfLocation->kode_lokasi ?? ('#'.$shelfLocation->id)),
                'ip_address' => request()?->ip(),
            ]);
        });

        static::updated(function (ShelfLocation $shelfLocation): void {
            if (! auth()->check() || empty($shelfLocation->getChanges())) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Update',
                'module' => 'Shelf Location',
                'description' => 'Update shelf location '.(string) ($shelfLocation->kode_lokasi ?? ('#'.$shelfLocation->id)),
                'ip_address' => request()?->ip(),
            ]);
        });

        static::deleted(function (ShelfLocation $shelfLocation): void {
            if (! auth()->check()) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Delete',
                'module' => 'Shelf Location',
                'description' => 'Menghapus shelf location '.(string) ($shelfLocation->kode_lokasi ?? ('#'.$shelfLocation->id)),
                'ip_address' => request()?->ip(),
            ]);
        });
    }

    public function materialPieces(): HasMany
    {
        return $this->hasMany(MaterialPiece::class, 'shelf_location_id');
    }
}
