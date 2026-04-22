<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    public function hasAnyAiDecision(): bool
    {
        if ($this->type !== 'ai_booking_suggestion') {
            return false;
        }

        return collect(data_get($this->payload, 'items', []))
            ->filter(fn ($item): bool => is_array($item))
            ->contains(fn (array $item): bool => in_array((string) ($item['status'] ?? 'pending'), ['approved', 'rejected'], true));
    }

    public function hasAiItemStatus(string $status): bool
    {
        if ($this->type !== 'ai_booking_suggestion') {
            return false;
        }

        return collect(data_get($this->payload, 'items', []))
            ->filter(fn ($item): bool => is_array($item))
            ->contains(fn (array $item): bool => (string) ($item['status'] ?? 'pending') === $status);
    }

    public function aiDecisionState(): string
    {
        if ($this->type !== 'ai_booking_suggestion') {
            return match ((string) $this->status) {
                'done' => 'approved',
                'rejected' => 'rejected',
                default => 'pending',
            };
        }

        $items = collect(data_get($this->payload, 'items', []))
            ->filter(fn ($item): bool => is_array($item))
            ->values();

        if ($items->isEmpty()) {
            return 'pending';
        }

        $statuses = $items
            ->map(fn (array $item): string => (string) ($item['status'] ?? 'pending'))
            ->filter(fn (string $status): bool => in_array($status, ['pending', 'approved', 'rejected'], true))
            ->unique()
            ->values();

        if ($statuses->contains('pending')) {
            return 'pending';
        }

        if ($statuses->count() === 1 && $statuses->first() === 'approved') {
            return 'approved';
        }

        if ($statuses->count() === 1 && $statuses->first() === 'rejected') {
            return 'rejected';
        }

        if ($statuses->contains('approved') && $statuses->contains('rejected')) {
            return 'hybrid';
        }

        return 'pending';
    }

    public function visualStatus(): string
    {
        if ($this->type === 'ai_booking_suggestion' && $this->hasAnyAiDecision()) {
            return 'done';
        }

        return (string) $this->status;
    }

    public function getProjectCodeAttribute()
    {
        $code = (string) data_get($this->payload, 'project_code', '');
        if ($code !== '') {
            return $code;
        }
        if (isset($this->payload['project_id'])) {
            return Project::query()->whereKey($this->payload['project_id'])->value('kode_proyek') ?: '#'.$this->payload['project_id'];
        }

        return null;
    }

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
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
