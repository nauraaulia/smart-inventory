<?php

namespace App\Models;

use App\Jobs\ProsesKebutuhanProyekJob;
use Illuminate\Database\Eloquent\Model;

class ProjectItem extends Model
{
    protected $fillable = ['project_id', 'material_id', 'jumlah_diminta', 'tanpa_potongan', 'jumlah_keluar'];

    protected $casts = [
        'tanpa_potongan' => 'boolean',
    ];

    protected static function booted(): void
    {
        $dispatchSyncAi = function (ProjectItem $item): void {
            if (!$item->project_id) {
                return;
            }

            ProsesKebutuhanProyekJob::dispatchAfterResponse($item->project_id);
        };

        static::created($dispatchSyncAi);
        static::updated($dispatchSyncAi);
        static::deleted($dispatchSyncAi);
    }

    public function project() 
    {
        return $this->belongsTo(Project::class);
    }

    public function material() {
        return $this->belongsTo(Material::class);
    }
}
