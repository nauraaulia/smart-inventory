<?php

namespace App\Models;

use App\Jobs\ProsesKebutuhanProyekJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

            $projectId = (int) $item->project_id;
            if ($projectId <= 0) {
                return;
            }

            // Hindari burst queue saat form menyimpan beberapa perubahan item sekaligus.
            $debounceKey = 'project_item_ai_dispatch_' . $projectId;
            if (!Cache::add($debounceKey, 1, now()->addSeconds(2))) {
                return;
            }

            ProsesKebutuhanProyekJob::dispatch($projectId);
        };

        static::saved($dispatchSyncAi);
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
