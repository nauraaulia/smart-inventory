<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
        'needed_date' => 'date',
        'submitted_at' => 'datetime',
        'draft_approved_at' => 'datetime',
        'approved_by_manager_at' => 'datetime',
        'approved_by_finance_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function draftApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_approved_by');
    }

    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_manager');
    }

    public function financeApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_finance');
    }
}
