<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequest extends Model
{
    protected $guarded = [];

    public const PRIORITY_BUFFER_DAYS = 3;

    protected $casts = [
        'quantity' => 'decimal:2',
        'project_allocated_qty' => 'decimal:2',
        'needed_date' => 'date',
        'submitted_at' => 'datetime',
        'project_allocated_at' => 'datetime',
        'draft_approved_at' => 'datetime',
        'approved_by_manager_at' => 'datetime',
        'approved_by_finance_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updated(function (PurchaseRequest $pr): void {
            $statusChangedToReceived = $pr->wasChanged('status') && $pr->status === 'received';

            if (!$statusChangedToReceived) {
                return;
            }

            if (!$pr->project_id || !$pr->material_id) {
                return;
            }

            if (filled($pr->project_allocated_at)) {
                return;
            }

            DB::transaction(function () use ($pr): void {
                /** @var PurchaseRequest|null $lockedPr */
                $lockedPr = PurchaseRequest::query()->lockForUpdate()->find($pr->id);

                if (!$lockedPr || filled($lockedPr->project_allocated_at) || $lockedPr->status !== 'received') {
                    return;
                }

                $allocated = self::bookToProjectFromAvailablePieces(
                    materialId: (int) $lockedPr->material_id,
                    projectId: (int) $lockedPr->project_id,
                    qtyNeeded: (float) $lockedPr->quantity,
                );

                $lockedPr->forceFill([
                    'project_allocated_at' => now(),
                    'project_allocated_qty' => number_format($allocated, 2, '.', ''),
                ])->saveQuietly();

                UserLog::create([
                    'user_id' => auth()->id() ?? $lockedPr->requested_by,
                    'action' => 'Auto Allocate',
                    'module' => 'Purchase Request',
                    'description' => "PR ID {$lockedPr->id} status received dialokasikan otomatis ke project ID {$lockedPr->project_id}. Qty teralokasi: {$allocated}.",
                    'ip_address' => request()?->ip(),
                ]);
            });
        });
    }

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

    public function getHariTersisaAttribute(): ?int
    {
        if (blank($this->needed_date)) {
            return null;
        }

        $today = Carbon::today();
        $usageDate = Carbon::parse($this->needed_date)->startOfDay();
        $leadTimeDays = max(0, (int) ($this->material?->defaultSupplier?->lead_time_hari ?? 7));

        return $today->diffInDays($usageDate, false) - $leadTimeDays - self::PRIORITY_BUFFER_DAYS;
    }

    private static function bookToProjectFromAvailablePieces(int $materialId, int $projectId, float $qtyNeeded): float
    {
        $remaining = max(0, $qtyNeeded);
        $booked = 0.0;

        if ($materialId <= 0 || $projectId <= 0 || $remaining <= 0) {
            return 0.0;
        }

        $pieces = MaterialPiece::query()
            ->where('material_id', $materialId)
            ->where('status', 'available')
            ->orderByDesc('panjang')
            ->lockForUpdate()
            ->get();

        foreach ($pieces as $piece) {
            if ($remaining <= 0) {
                break;
            }

            $pieceLength = (float) $piece->panjang;

            if ($pieceLength <= $remaining) {
                $piece->update([
                    'status' => 'booked',
                    'booked_for_project_id' => $projectId,
                    'booked_at' => now(),
                    'keterangan' => trim(($piece->keterangan ?? '') . ' | Auto allocate dari PR received.'),
                ]);

                $remaining -= $pieceLength;
                $booked += $pieceLength;
                continue;
            }

            $piece->update([
                'panjang' => number_format($pieceLength - $remaining, 2, '.', ''),
            ]);

            MaterialPiece::create([
                'material_id' => $piece->material_id,
                'piece_code' => $piece->piece_code,
                'inbound_id' => $piece->inbound_id,
                'booked_for_project_id' => $projectId,
                'shelf_location_id' => $piece->shelf_location_id,
                'supplier_id' => $piece->supplier_id,
                'panjang' => number_format($remaining, 2, '.', ''),
                'unit_price' => $piece->unit_price,
                'status' => 'booked',
                'sumber' => 'split_pr_received',
                'booked_at' => now(),
                'keterangan' => 'Auto split allocate dari PR manual status received.',
            ]);

            $booked += $remaining;
            $remaining = 0;
        }

        return round($booked, 2);
    }
}
