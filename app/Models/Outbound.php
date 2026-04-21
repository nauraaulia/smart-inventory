<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Outbound extends Model
{
    protected $guarded = [];

    protected $casts = [
        'outbound_manual' => 'boolean',
        'taken_at' => 'datetime',
        'ready_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (Outbound $outbound): void {
            if (! auth()->check()) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Create',
                'module' => 'Outbound',
                'description' => 'Membuat outbound #'.$outbound->id.' untuk project #'.(int) ($outbound->project_id ?? 0),
                'ip_address' => request()?->ip(),
            ]);
        });

        static::updated(function (Outbound $outbound): void {
            if (! auth()->check() || empty($outbound->getChanges())) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Update',
                'module' => 'Outbound',
                'description' => 'Update outbound #'.$outbound->id.' (status: '.(string) ($outbound->status ?? '-').')',
                'ip_address' => request()?->ip(),
            ]);
        });

        static::deleted(function (Outbound $outbound): void {
            if (! auth()->check()) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Delete',
                'module' => 'Outbound',
                'description' => 'Menghapus outbound #'.$outbound->id,
                'ip_address' => request()?->ip(),
            ]);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OutboundItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }
}
