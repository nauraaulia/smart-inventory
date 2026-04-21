<?php

namespace App\Services\Inventory;

use App\Models\Inbound;
use App\Models\MaterialPiece;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\PurchaseRequest;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class InboundAutoAllocationService
{
    public function processInboundPiece(MaterialPiece $piece): void
    {
        if ((int) $piece->material_id <= 0 || $piece->status !== 'available') {
            return;
        }

        if ($this->requiresHumanApproval($piece)) {
            $this->createAllocationSuggestionTask($piece);

            return;
        }

        $this->allocatePiece($piece);
    }

    public function allocatePiece(MaterialPiece $piece): void
    {
        if ((int) $piece->material_id <= 0 || $piece->status !== 'available') {
            return;
        }

        DB::transaction(function () use ($piece): void {
            /** @var MaterialPiece|null $lockedPiece */
            $lockedPiece = MaterialPiece::query()->lockForUpdate()->find($piece->id);

            if (! $lockedPiece || $lockedPiece->status !== 'available') {
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

    private function requiresHumanApproval(MaterialPiece $piece): bool
    {
        /** @var Inbound|null $inbound */
        $inbound = $piece->relationLoaded('inbound') ? $piece->inbound : $piece->inbound()->first();

        if (! $inbound) {
            return false;
        }

        // Inbound non-PO masuk mode Human-in-the-Loop agar alokasi tidak liar.
        return empty($inbound->purchase_order_id);
    }

    private function createAllocationSuggestionTask(MaterialPiece $piece): void
    {
        DB::transaction(function () use ($piece): void {
            /** @var MaterialPiece|null $lockedPiece */
            $lockedPiece = MaterialPiece::query()->lockForUpdate()->find($piece->id);

            if (! $lockedPiece || $lockedPiece->status !== 'available') {
                return;
            }

            /** @var Inbound|null $inbound */
            $inbound = Inbound::query()->find($lockedPiece->inbound_id);

            if (! $inbound || ! empty($inbound->purchase_order_id)) {
                return;
            }

            $piecePool = MaterialPiece::query()
                ->lockForUpdate()
                ->where('status', 'available')
                ->where('inbound_id', $lockedPiece->inbound_id)
                ->where('material_id', $lockedPiece->material_id)
                ->orderByDesc('panjang')
                ->get();

            if ($piecePool->isEmpty()) {
                return;
            }

            $pieceIds = $piecePool->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $totalAvailable = (float) $piecePool->sum('panjang');

            $existingPendingTask = Task::query()
                ->where('type', 'ai_inbound_allocation_suggestion')
                ->where('status', 'pending')
                ->where('payload->inbound_id', (int) $lockedPiece->inbound_id)
                ->where('payload->material_id', (int) $lockedPiece->material_id)
                ->latest('id')
                ->first();

            $suggestions = $this->buildSuggestions(
                materialId: (int) $lockedPiece->material_id,
                remaining: $totalAvailable,
            );

            if (empty($suggestions)) {
                return;
            }

            $materialName = (string) ($lockedPiece->material?->nama_material ?? ('Material #'.$lockedPiece->material_id));

            $payload = [
                'piece_id' => $lockedPiece->id,
                'piece_ids' => $pieceIds,
                'inbound_id' => $lockedPiece->inbound_id,
                'material_id' => $lockedPiece->material_id,
                'material_name' => $materialName,
                'qty_dibutuhkan' => round((float) collect($suggestions)->sum(fn ($row): float => (float) ($row['suggested_qty'] ?? 0)), 2),
                'piece_length' => (float) $lockedPiece->panjang,
                'total_available_qty' => round($totalAvailable, 2),
                'allocation_suggestions' => $suggestions,
                'ai_reasoning' => null,
            ];

            if ($existingPendingTask) {
                $existingPendingTask->update([
                    'title' => "Usulan Alokasi Inbound ({$materialName})",
                    'message' => "Inbound manual material {$materialName} tersedia {$totalAvailable}. Pilih proyek tujuan dan qty alokasi.",
                    'payload' => $payload,
                ]);

                return;
            }

            Task::create([
                'for_role' => 'Staff Administrasi Gudang',
                'type' => 'ai_inbound_allocation_suggestion',
                'title' => "Usulan Alokasi Inbound ({$materialName})",
                'message' => "Inbound manual material {$materialName} tersedia {$totalAvailable}. Pilih proyek tujuan dan qty alokasi.",
                'payload' => $payload,
                'status' => 'pending',
                'related_id' => $lockedPiece->inbound_id,
                'related_type' => 'inbound',
            ]);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSuggestions(int $materialId, float $remaining): array
    {
        if ($materialId <= 0 || $remaining <= 0) {
            return [];
        }

        $prRequests = PurchaseRequest::query()
            ->with('project:id,kode_proyek')
            ->where('material_id', $materialId)
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

        $projectIds = $prRequests
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $projectItemProjectIds = ProjectItem::query()
            ->where('material_id', $materialId)
            ->whereNotNull('project_id')
            ->distinct()
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->all();

        $candidateProjectIds = collect(array_merge($projectIds, $projectItemProjectIds))
            ->unique()
            ->values();

        if ($candidateProjectIds->isEmpty()) {
            return [];
        }

        $projectCodes = Project::query()
            ->whereIn('id', $candidateProjectIds->all())
            ->pluck('kode_proyek', 'id');

        $prStatusByProject = $prRequests
            ->groupBy('project_id')
            ->map(function ($rows): string {
                return (string) ($rows->first()->status ?? '-');
            });

        $result = [];

        foreach ($candidateProjectIds as $projectIdRaw) {
            if ($remaining <= 0) {
                break;
            }

            $projectId = (int) $projectIdRaw;

            if ($projectId <= 0) {
                continue;
            }

            $needed = $this->outstandingNeed(
                projectId: $projectId,
                materialId: $materialId,
            );

            if ($needed <= 0) {
                continue;
            }

            $allocated = min($remaining, $needed);

            if ($allocated <= 0) {
                continue;
            }

            $result[] = [
                'project_id' => $projectId,
                'project_code' => (string) ($projectCodes[$projectId] ?? ('#'.$projectId)),
                'pr_status' => (string) ($prStatusByProject[$projectId] ?? 'no_active_pr'),
                'suggested_qty' => round($allocated, 2),
            ];

            $remaining -= $allocated;
        }

        return $result;
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
                'keterangan' => trim(($piece->keterangan ?? '').' | Auto-allocate inbound untuk project prioritas.'),
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
