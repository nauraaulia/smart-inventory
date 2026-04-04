<?php

namespace App\Services\Inventory;

use App\Models\MaterialPiece;
use App\Models\ProjectItem;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;

class InboundAutoAllocationService
{
    public function allocatePiece(MaterialPiece $piece): void
    {
        if ((int) $piece->material_id <= 0 || $piece->status !== 'available') {
            return;
        }

        DB::transaction(function () use ($piece): void {
            /** @var MaterialPiece|null $lockedPiece */
            $lockedPiece = MaterialPiece::query()->lockForUpdate()->find($piece->id);

            if (!$lockedPiece || $lockedPiece->status !== 'available') {
                return;
            }

            $remaining = (float) $lockedPiece->panjang;

            if ($remaining <= 0) {
                return;
            }

            $requests = PurchaseRequest::query()
                ->where('material_id', $lockedPiece->material_id)
                ->whereNotNull('project_id')
                ->whereIn('status', ['approved', 'ordered', 'pending_finance', 'pending_manager'])
                ->orderByRaw("CASE status
                    WHEN 'approved' THEN 1
                    WHEN 'ordered' THEN 2
                    WHEN 'pending_finance' THEN 3
                    WHEN 'pending_manager' THEN 4
                    ELSE 9
                END")
                ->orderBy('submitted_at')
                ->orderBy('created_at')
                ->get();

            foreach ($requests as $request) {
                if ($remaining <= 0) {
                    break;
                }

                $needed = $this->outstandingNeed(
                    projectId: (int) $request->project_id,
                    materialId: (int) $lockedPiece->material_id,
                );

                if ($needed <= 0) {
                    continue;
                }

                $allocated = min($remaining, $needed);
                $this->allocatePortion($lockedPiece, (int) $request->project_id, $allocated);

                $lockedPiece->refresh();
                $remaining = (float) $lockedPiece->panjang;

                if ($lockedPiece->status !== 'available') {
                    break;
                }
            }
        });
    }

    private function outstandingNeed(int $projectId, int $materialId): float
    {
        $requested = (float) ProjectItem::query()
            ->where('project_id', $projectId)
            ->where('material_id', $materialId)
            ->sum('jumlah_diminta');

        if ($requested <= 0) {
            $requested = (float) PurchaseRequest::query()
                ->where('project_id', $projectId)
                ->where('material_id', $materialId)
                ->whereIn('status', ['approved', 'ordered', 'pending_finance', 'pending_manager'])
                ->sum('quantity');
        }

        $booked = (float) MaterialPiece::query()
            ->where('booked_for_project_id', $projectId)
            ->where('material_id', $materialId)
            ->whereIn('status', ['booked', 'used'])
            ->sum('panjang');

        return max(0, round($requested - $booked, 2));
    }

    private function allocatePortion(MaterialPiece $piece, int $projectId, float $allocated): void
    {
        if ($allocated <= 0) {
            return;
        }

        $pieceLength = (float) $piece->panjang;

        if ($pieceLength <= ($allocated + 0.00001)) {
            $piece->update([
                'status' => 'booked',
                'booked_for_project_id' => $projectId,
                'booked_at' => now(),
                'keterangan' => trim(($piece->keterangan ?? '') . ' | Auto-allocate inbound untuk project prioritas.'),
            ]);

            return;
        }

        $piece->update([
            'panjang' => number_format($pieceLength - $allocated, 2, '.', ''),
        ]);

        MaterialPiece::create([
            'material_id' => $piece->material_id,
            'inbound_id' => $piece->inbound_id,
            'booked_for_project_id' => $projectId,
            'shelf_location_id' => $piece->shelf_location_id,
            'supplier_id' => $piece->supplier_id,
            'panjang' => number_format($allocated, 2, '.', ''),
            'unit_price' => $piece->unit_price,
            'status' => 'booked',
            'sumber' => 'split_auto_allocation',
            'booked_at' => now(),
            'keterangan' => 'Auto split allocation untuk prioritas project dengan PR aktif.',
        ]);
    }
}
