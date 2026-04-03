<?php

namespace App\Services\AI;

use App\Models\MaterialPiece;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TaskApprovalService
{
    public function approve(Task $task, User $actor): void
    {
        DB::transaction(function () use ($task, $actor) {
            match ($task->type) {
                'ai_booking_suggestion' => $this->approveAiBookingSuggestion($task, $actor),
                'pr_manager_approval' => $this->approveManagerPr($task, $actor),
                'pr_finance_approval' => $this->approveFinancePr($task, $actor),
                'pr_revision' => $this->approvePrRevision($task, $actor),
                default => $this->markDone($task, $actor),
            };
        });
    }

    public function reject(Task $task, User $actor, string $reason): void
    {
        DB::transaction(function () use ($task, $actor, $reason) {
            match ($task->type) {
                'pr_manager_approval', 'pr_finance_approval' => $this->rejectApprovalTask($task, $actor, $reason),
                default => $this->markRejected($task, $actor, $reason),
            };
        });
    }

    private function approveAiBookingSuggestion(Task $task, User $actor): void
    {
        $payload = $task->payload ?? [];
        $projectId = (int) data_get($payload, 'project_id');
        $projectCode = $this->projectCodeFromPayload($payload, $projectId);
        $detail = data_get($payload, 'detail', []);

        $createdPrIds = [];

        foreach ($detail as $item) {
            $materialId = (int) ($item['material_id'] ?? 0);
            $dibutuhkan = (float) ($item['dibutuhkan'] ?? 0);

            if ($materialId <= 0 || $dibutuhkan <= 0) {
                continue;
            }

            $bookedQty = $this->bookFromAvailablePieces(
                materialId: $materialId,
                projectId: $projectId,
                qtyNeeded: $dibutuhkan,
            );

            $shortage = max(0, $dibutuhkan - $bookedQty);

            if ($shortage > 0) {
                $pr = PurchaseRequest::create([
                    'material_id' => $materialId,
                    'project_id' => $projectId ?: null,
                    'requested_by' => $actor->id,
                    'approved_by_manager' => null,
                    'approved_by_finance' => null,
                    'quantity' => $this->decimal($shortage),
                    'reason' => 'Auto-generated dari Task AI karena stok kurang.',
                    'status' => 'pending_manager',
                    'priority' => $shortage >= 100 ? 'urgent' : 'high',
                    'needed_date' => now()->addDays(7)->toDateString(),
                    'submitted_at' => now(),
                    'approved_by_manager_at' => null,
                    'approved_by_finance_at' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ]);

                $createdPrIds[] = $pr->id;
            }
        }

        if ($projectId) {
            Project::whereKey($projectId)->update([
                'material_confirmed_at' => now(),
            ]);
        }

        $payload = $task->payload ?? [];
        data_set($payload, 'project_code', $projectCode);
        data_set($payload, 'approval_trail.staff', [
            'status' => 'approved',
            'by_user_id' => $actor->id,
            'at' => now()->toDateTimeString(),
        ]);
        data_set($payload, 'execution_result', [
            'created_pr_ids' => $createdPrIds,
            'created_pr_count' => count($createdPrIds),
        ]);

        $task->update([
            'status' => 'done',
            'handled_by' => $actor->id,
            'handled_at' => now(),
            'payload' => $payload,
        ]);

        if (!empty($createdPrIds)) {
            Task::create([
                'for_role' => 'Manager Operasional',
                'type' => 'pr_manager_approval',
                'title' => "Approval PR Project {$projectCode}",
                'message' => 'Terdapat PR hasil analisa AI yang menunggu approval Manager Operasional.',
                'payload' => [
                    'project_id' => $projectId,
                    'project_code' => $projectCode,
                    'pr_ids' => $createdPrIds,
                    'approval_trail' => [
                        'staff' => data_get($payload, 'approval_trail.staff'),
                    ],
                ],
                'status' => 'pending',
                'related_id' => $projectId,
                'related_type' => 'project',
            ]);
        }
    }

    private function approveManagerPr(Task $task, User $actor): void
    {
        $payload = $task->payload ?? [];
        $projectId = (int) data_get($payload, 'project_id');
        $projectCode = $this->projectCodeFromPayload($payload, $projectId);
        $prIds = collect(data_get($payload, 'pr_ids', []))->filter()->values()->all();

        if (!empty($prIds)) {
            PurchaseRequest::whereIn('id', $prIds)
                ->where('status', 'pending_manager')
                ->update([
                    'status' => 'pending_finance',
                    'approved_by_manager' => $actor->id,
                    'approved_by_manager_at' => now(),
                ]);
        }

        data_set($payload, 'approval_trail.manager', [
            'status' => 'approved',
            'by_user_id' => $actor->id,
            'at' => now()->toDateTimeString(),
        ]);

        $task->update([
            'status' => 'done',
            'handled_by' => $actor->id,
            'handled_at' => now(),
            'payload' => $payload,
        ]);

        Task::create([
            'for_role' => 'Finance',
            'type' => 'pr_finance_approval',
            'title' => "Approval Finance PR Project {$projectCode}",
            'message' => 'PR sudah di-approve Manager Operasional. Menunggu approval Finance.',
            'payload' => [
                'project_id' => $projectId,
                'project_code' => $projectCode,
                'pr_ids' => $prIds,
                'approval_trail' => data_get($payload, 'approval_trail', []),
            ],
            'status' => 'pending',
            'related_id' => $projectId,
            'related_type' => 'project',
        ]);
    }

    private function approveFinancePr(Task $task, User $actor): void
    {
        $payload = $task->payload ?? [];
        $projectId = (int) data_get($payload, 'project_id');
        $projectCode = $this->projectCodeFromPayload($payload, $projectId);
        $prIds = collect(data_get($payload, 'pr_ids', []))->filter()->values()->all();

        if (!empty($prIds)) {
            PurchaseRequest::whereIn('id', $prIds)
                ->where('status', 'pending_finance')
                ->update([
                    'status' => 'approved',
                    'approved_by_finance' => $actor->id,
                    'approved_by_finance_at' => now(),
                ]);
        }

        data_set($payload, 'approval_trail.finance', [
            'status' => 'approved',
            'by_user_id' => $actor->id,
            'at' => now()->toDateTimeString(),
        ]);

        $task->update([
            'status' => 'done',
            'handled_by' => $actor->id,
            'handled_at' => now(),
            'payload' => $payload,
        ]);

        Task::create([
            'for_role' => 'Manager Operasional',
            'type' => 'po_manual_followup',
            'title' => "PO Manual Project {$projectCode}",
            'message' => 'Finance sudah ACC. Lanjutkan pemesanan barang (PO) secara manual.',
            'payload' => [
                'project_id' => $projectId,
                'project_code' => $projectCode,
                'pr_ids' => $prIds,
                'approval_trail' => data_get($payload, 'approval_trail', []),
            ],
            'status' => 'pending',
            'related_id' => $projectId,
            'related_type' => 'project',
        ]);
    }

    private function approvePrRevision(Task $task, User $actor): void
    {
        $payload = $task->payload ?? [];
        $projectId = (int) data_get($payload, 'project_id');
        $projectCode = $this->projectCodeFromPayload($payload, $projectId);
        $prIds = collect(data_get($payload, 'pr_ids', []))->filter()->values()->all();

        if (!empty($prIds)) {
            PurchaseRequest::whereIn('id', $prIds)
                ->where('status', 'rejected')
                ->update([
                    'status' => 'pending_manager',
                    'rejection_reason' => null,
                    'rejected_at' => null,
                    'submitted_at' => now(),
                ]);
        }

        data_set($payload, 'revision_staff', [
            'status' => 'resubmitted',
            'by_user_id' => $actor->id,
            'at' => now()->toDateTimeString(),
        ]);

        $task->update([
            'status' => 'done',
            'handled_by' => $actor->id,
            'handled_at' => now(),
            'payload' => $payload,
        ]);

        Task::create([
            'for_role' => 'Manager Operasional',
            'type' => 'pr_manager_approval',
            'title' => "Re-Approval PR Project {$projectCode}",
            'message' => 'PR sudah direvisi Staff Administrasi Gudang, menunggu approval ulang.',
            'payload' => [
                'project_id' => $projectId,
                'project_code' => $projectCode,
                'pr_ids' => $prIds,
                'approval_trail' => data_get($payload, 'approval_trail', []),
            ],
            'status' => 'pending',
            'related_id' => $projectId,
            'related_type' => 'project',
        ]);
    }

    private function rejectApprovalTask(Task $task, User $actor, string $reason): void
    {
        $payload = $task->payload ?? [];
        $projectId = (int) data_get($payload, 'project_id');
        $projectCode = $this->projectCodeFromPayload($payload, $projectId);
        $prIds = collect(data_get($payload, 'pr_ids', []))->filter()->values()->all();

        if (!empty($prIds)) {
            PurchaseRequest::whereIn('id', $prIds)->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);
        }

        $this->markRejected($task, $actor, $reason);

        Task::create([
            'for_role' => 'Staff Administrasi Gudang',
            'type' => 'pr_revision',
            'title' => "Revisi PR Project {$projectCode}",
            'message' => 'PR ditolak dan dikembalikan ke Staff Administrasi Gudang untuk revisi.',
            'payload' => [
                'project_id' => $projectId,
                'project_code' => $projectCode,
                'pr_ids' => $prIds,
                'rejected_by_role' => $actor->role,
                'rejected_by_user_id' => $actor->id,
                'reason' => $reason,
                'approval_trail' => data_get($payload, 'approval_trail', []),
            ],
            'status' => 'pending',
            'related_id' => $projectId,
            'related_type' => 'project',
        ]);
    }

    private function markDone(Task $task, User $actor): void
    {
        $task->update([
            'status' => 'done',
            'handled_by' => $actor->id,
            'handled_at' => now(),
        ]);
    }

    private function markRejected(Task $task, User $actor, string $reason): void
    {
        $payload = $task->payload ?? [];
        data_set($payload, 'rejection', [
            'reason' => $reason,
            'by_user_id' => $actor->id,
            'at' => now()->toDateTimeString(),
        ]);

        $task->update([
            'status' => 'rejected',
            'handled_by' => $actor->id,
            'handled_at' => now(),
            'payload' => $payload,
        ]);
    }

    private function bookFromAvailablePieces(int $materialId, int $projectId, float $qtyNeeded): float
    {
        $remaining = $qtyNeeded;
        $booked = 0.0;

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
                ]);

                $remaining -= $pieceLength;
                $booked += $pieceLength;
                continue;
            }

            // Split piece: sebagian dibooking, sisa tetap available
            $piece->update([
                'panjang' => $this->decimal($pieceLength - $remaining),
            ]);

            MaterialPiece::create([
                'material_id' => $piece->material_id,
                'inbound_id' => $piece->inbound_id,
                'booked_for_project_id' => $projectId,
                'shelf_location_id' => $piece->shelf_location_id,
                'supplier_id' => $piece->supplier_id,
                'panjang' => $this->decimal($remaining),
                'unit_price' => $piece->unit_price,
                'status' => 'booked',
                'sumber' => 'split_booking',
                'booked_at' => now(),
                'keterangan' => 'Auto split booking dari task approval AI.',
            ]);

            $booked += $remaining;
            $remaining = 0;
        }

        return round($booked, 2);
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function projectCodeFromPayload(array $payload, int $projectId): string
    {
        $code = (string) data_get($payload, 'project_code', '');

        if ($code !== '') {
            return $code;
        }

        return Project::query()->whereKey($projectId)->value('kode_proyek') ?: "#{$projectId}";
    }
}
