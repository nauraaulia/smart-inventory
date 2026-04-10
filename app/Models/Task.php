<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    public function getProjectCodeAttribute()
    {
        $code = (string) data_get($this->payload, 'project_code', '');
        if ($code !== '') {
            return $code;
        }
        if (isset($this->payload['project_id'])) {
            return \App\Models\Project::query()->whereKey($this->payload['project_id'])->value('kode_proyek') ?: '#' . $this->payload['project_id'];
        }
        return null;
    }
    protected $guarded = [];

    protected $casts = [
        'payload'    => 'array',
        'handled_at' => 'datetime',
    ];

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForRole($query, string $role)
    {
        return $query->where('for_role', $role);
    }
}