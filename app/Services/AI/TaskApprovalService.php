<?php

namespace App\Services\AI;

use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\Inbound;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use App\Models\UserLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TaskApprovalService
{
    public function approve(Task $task, User $actor, array $options = []): void
    {
        DB::transaction(function () use ($task, $actor, $options) {
            match ($task->type) {
                'ai_booking_suggestion' => $this->approveAiBookingSuggestion($task, $actor, $options),
                'pr_manager_approval' => $this->approveManagerPr($task, $actor),
                'pr_finance_approval' => $this->approveFinancePr($task, $actor),
                'pr_revision' => $this->approvePrRevision($task, $actor),
                default => $this->markDone($task, $actor),
            };
        });

        $payload = $task->payload ?? [];
        $projectId = (int) data_get($payload, 'project_id');
        $projectCode = $this->projectCodeFromPayload($payload, $projectId);

        $this->logUserAction(
            actor: $actor,
            action: 'Approve',
            module: 'Task AI',
            description: "Approve task {$task->type} (ID {$task->id}) untuk project {$projectCode}.",
        );
    }

    public function reject(Task $task, User $actor, string $reason): void
    {
        DB::transaction(function () use ($task, $actor, $reason) {
            match ($task->type) {
                'pr_manager_approval', 'pr_finance_approval' => $this->rejectApprovalTask($task, $actor, $reason),
                default => $this->markRejected($task, $actor, $reason),
            };
        });

        $payload = $task->payload ?? [];
        $projectId = (int) data_get($payload, 'project_id');
        $projectCode = $this->projectCodeFromPayload($payload, $projectId);

        $this->logUserAction(
            actor: $actor,
            action: 'Reject',
            module: 'Task AI',
            description: "Reject task {$task->type} (ID {$task->id}) untuk project {$projectCode}. Alasan: {$reason}",
        );
    }

    private function approveAiBookingSuggestion(Task $task, User $actor, array $options = []): void
    {
        $payload = $task->payload ?? [];
        $projectId = (int) data_get($payload, 'project_id');
        $projectCode = $this->projectCodeFromPayload($payload, $projectId);
        $detail = data_get($payload, 'detail', []);
        $generatePr = (bool) ($options['generate_pr'] ?? true);
        $bufferDays = (int) ($options['buffer_days'] ?? 3);
        $project = $projectId > 0 ? Project::query()->find($projectId) : null;

        $createdPrIds = [];
        $pendingShortages = [];
        $touchedMaterialIds = [];

        foreach ($detail as $item) {
            $materialId = (int) ($item['material_id'] ?? 0);
            $dibutuhkan = (float) ($item['dibutuhkan'] ?? 0);

            if ($materialId <= 0 || $dibutuhkan <= 0) {
                continue;
            }

            $touchedMaterialIds[] = $materialId;

            $bookedQty = $this->bookFromAvailablePieces(
                materialId: $materialId,
                projectId: $projectId,
                qtyNeeded: $dibutuhkan,
            );

            $shortage = max(0, $dibutuhkan - $bookedQty);

            if ($shortage > 0) {
                if (!$generatePr) {
                    $pendingShortages[] = [
                        'material_id' => $materialId,
                        'shortage_qty' => $this->decimal($shortage),
                    ];
                    continue;
                }

                $pr = PurchaseRequest::create([
                    'material_id' => $materialId,
                    'project_id' => $projectId ?: null,
                    'requested_by' => $actor->id,
                    'draft_approved_by' => null,
                    'draft_approved_at' => null,
                    'approved_by_manager' => null,
                    'approved_by_finance' => null,
                    'quantity' => $this->decimal($shortage),
                    'reason' => 'Draft auto-generated dari Task AI karena stok kurang.',
                    'status' => 'draft',
                    'priority' => $this->hitungPrioritas(
                        material: $materialId > 0 ? Material::query()->find($materialId) : null,
                        project: $project,
                        bufferDays: $bufferDays,
                    ),
                    'needed_date' => $this->tanggalPenggunaan($project)?->toDateString() ?? now()->addDays(7)->toDateString(),
                    'submitted_at' => null,
                    'approved_by_manager_at' => null,
                    'approved_by_finance_at' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ]);

                $createdPrIds[] = $pr->id;
            }
        }

        Material::query()
            ->whereIn('id', array_unique($touchedMaterialIds))
            ->get()
            ->each(fn (Material $m) => $m->updateStokSummary());

        if ($projectId) {
            $isMaterialReady = empty($pendingShortages) && count($createdPrIds) === 0;

            Project::whereKey($projectId)->update([
                'material_confirmed_at' => $isMaterialReady ? now() : null,
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
            'created_draft_pr_ids' => $createdPrIds,
            'created_draft_pr_count' => count($createdPrIds),
            'pending_shortages_without_pr' => $pendingShortages,
            'generate_pr_selected' => $generatePr,
        ]);

        $task->update([
            'status' => 'done',
            'handled_by' => $actor->id,
            'handled_at' => now(),
            'message' => $this->buildAiApprovalMessage(
                $task->message,
                $generatePr,
                $pendingShortages,
                count($createdPrIds),
            ),
            'payload' => $payload,
        ]);
    }

    private function buildAiApprovalMessage(
        string $defaultMessage,
        bool $generatePr,
        array $pendingShortages,
        int $createdDraftPrCount,
    ): string {
        if (!$generatePr && !empty($pendingShortages)) {
            return 'Booking diproses. Ada shortage yang belum dibuat PR (sesuai pilihan konfirmasi).';
        }

        if ($generatePr && $createdDraftPrCount > 0) {
            return "Booking diproses. Draft PR berhasil dibuat ({$createdDraftPrCount}) dan siap direview Staff.";
        }

        return $defaultMessage;
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

    private function tanggalPenggunaan(?Project $project): ?Carbon
    {
        if (!$project || blank($project->tanggal_mulai)) {
            return null;
        }

        $usageDate = Carbon::parse($project->tanggal_mulai)->startOfDay();
        $hMinus = max(0, (int) ($project->h_minus_persiapan ?? 0));

        if ($hMinus > 0) {
            $usageDate = $usageDate->subDays($hMinus);
        }

        return $usageDate;
    }

    private function hitungPrioritas(?Material $material, ?Project $project, int $bufferDays = 3): string
    {
        $usageDate = $this->tanggalPenggunaan($project) ?? now()->addDays(14)->startOfDay();
        $today = now()->startOfDay();

        $leadTimeDays = $this->leadTimeSupplierHari($material);
        $buffer = max(0, $bufferDays);

        $hariTersisa = $today->diffInDays($usageDate, false) - $leadTimeDays - $buffer;

        if ($hariTersisa <= 0) {
            // Mapping CRITICAL ke nilai enum existing `urgent`.
            return 'urgent';
        }

        if ($hariTersisa <= 7) {
            return 'high';
        }

        if ($hariTersisa <= 14) {
            return 'medium';
        }

        return 'low';
    }

    private function leadTimeSupplierHari(?Material $material): int
    {
        if (!$material) {
            return 7;
        }

        $defaultLeadTime = (int) ($material->defaultSupplier?->lead_time_hari ?? 0);

        if ($defaultLeadTime > 0) {
            return $defaultLeadTime;
        }

        $latestSupplierId = Inbound::query()
            ->where('material_id', $material->id)
            ->whereNotNull('supplier_id')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->value('supplier_id');

        if (!$latestSupplierId) {
            return 7;
        }

        $leadTime = (int) Supplier::query()
            ->whereKey($latestSupplierId)
            ->value('lead_time_hari');

        return $leadTime > 0 ? $leadTime : 7;
    }

    private function logUserAction(User $actor, string $action, string $module, string $description): void
    {
        UserLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'ip_address' => request()?->ip(),
        ]);
    }
}
