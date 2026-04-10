<?php

namespace App\Services\AI;

use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\Inbound;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use App\Models\UserLog;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class TaskApprovalService
{
    public function approve(Task $task, User $actor, array $options = []): void
    {
        DB::transaction(function () use ($task, $actor, $options) {
            match ($task->type) {
                'ai_booking_suggestion' => $this->approveAiBookingSuggestion($task, $actor, $options),
                'ai_inbound_allocation_suggestion' => $this->approveInboundAllocationSuggestion($task, $actor),
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
        $project = $projectId > 0 ? Project::query()->find($projectId) : null;
        $bufferDays = (int) ($options['buffer_days'] ?? 3);

        $createdPrIds = [];
        $pendingShortages = [];
        $touchedMaterialIds = [];

        // Tandai task selesai lebih awal agar hook sinkron stok tidak membuat task AI duplikat
        // ketika material piece berubah di tengah proses approval.
        data_set($payload, 'approval_trail.staff', [
            'status' => 'approved',
            'by_user_id' => $actor->id,
            'at' => now()->toDateTimeString(),
        ]);

        data_set($payload, 'recommendation_lock', [
            'locked' => true,
            'locked_by_user_id' => $actor->id,
            'locked_at' => now()->toDateTimeString(),
            'reason' => 'Booking dan draft PR dari Task AI sudah dikonfirmasi, rekomendasi dikunci sebagai snapshot historis.',
            'snapshot_ai_summary' => data_get($payload, 'ai_summary', []),
        ]);

        $task->update([
            'status' => 'done',
            'handled_by' => $actor->id,
            'handled_at' => now(),
            'payload' => $payload,
        ]);

        foreach ($detail as $item) {
            $materialId = (int) ($item['material_id'] ?? 0);
            $dibutuhkan = (float) ($item['dibutuhkan'] ?? 0);
            $tanpaPotongan = (bool) ($item['tanpa_potongan'] ?? false);

            if ($materialId <= 0 || $dibutuhkan <= 0) {
                continue;
            }

            $touchedMaterialIds[] = $materialId;

            $bookedQty = $this->bookFromAvailablePieces(
                materialId: $materialId,
                projectId: $projectId,
                qtyNeeded: $dibutuhkan,
                tanpaPotongan: $tanpaPotongan,
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

    private function approveInboundAllocationSuggestion(Task $task, User $actor, array $options = []): void
    {
        $payload = $task->payload ?? [];
        $pieceId = (int) data_get($payload, 'piece_id');
        $manualAlloc = collect($options['manual_allocation'] ?? []);
        $suggestions = $manualAlloc->isNotEmpty()
            ? $manualAlloc->map(fn($row) => [
                'project_id' => (int) ($row['project_id'] ?? 0),
                'suggested_qty' => (float) ($row['qty'] ?? 0),
            ])
            : collect(data_get($payload, 'allocation_suggestions', []));

        if ($pieceId <= 0 || $suggestions->isEmpty()) {
            $this->markDone($task, $actor);

            return;
        }

        /** @var MaterialPiece|null $piece */
        $piece = MaterialPiece::query()->lockForUpdate()->find($pieceId);

        if (!$piece || $piece->status !== 'available') {
            data_set($payload, 'execution_result', [
                'status' => 'skipped',
                'reason' => 'Piece sudah tidak available saat approval diproses.',
                'piece_id' => $pieceId,
            ]);

            $task->update([
                'status' => 'done',
                'handled_by' => $actor->id,
                'handled_at' => now(),
                'message' => 'Usulan ditutup karena piece sudah tidak available.',
                'payload' => $payload,
            ]);

            return;
        }

        $remaining = (float) $piece->panjang;
        $allocations = [];

        foreach ($suggestions as $row) {
            if ($remaining <= 0) {
                break;
            }

            $projectId = (int) data_get($row, 'project_id');
            $requestedQty = (float) data_get($row, 'suggested_qty', 0);

            if ($projectId <= 0 || $requestedQty <= 0) {
                continue;
            }

            $outstanding = $this->outstandingNeed(
                projectId: $projectId,
                materialId: (int) $piece->material_id,
            );

            if ($outstanding <= 0) {
                continue;
            }

            $allocQty = min($remaining, $requestedQty, $outstanding);

            if ($allocQty <= 0) {
                continue;
            }

            $this->allocatePortionFromSinglePiece($piece, $projectId, $allocQty);
            $piece->refresh();
            $remaining = (float) $piece->panjang;

            $allocations[] = [
                'project_id' => $projectId,
                'project_code' => $this->projectCodeFromPayload(['project_id' => $projectId], $projectId),
                'allocated_qty' => round($allocQty, 2),
            ];

            if ($piece->status !== 'available') {
                break;
            }
        }

        data_set($payload, 'execution_result', [
            'status' => 'processed',
            'piece_id' => $pieceId,
            'allocated_rows' => $allocations,
            'remaining_piece_length' => $piece->status === 'available' ? (float) $piece->panjang : 0,
        ]);

        $task->update([
            'status' => 'done',
            'handled_by' => $actor->id,
            'handled_at' => now(),
            'message' => empty($allocations)
                ? 'Usulan disetujui, tetapi kebutuhan proyek sudah terpenuhi sehingga tidak ada booking baru.'
                : 'Usulan disetujui dan alokasi inbound manual sudah dibooking sesuai kebutuhan terbaru.',
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

        $managerRecipients = User::query()
            ->whereIn('role', ['Manager Operasional', 'Manajer Operasional'])
            ->get();

        if ($managerRecipients->isNotEmpty()) {
            $notification = Notification::make()
                ->title("Finance ACC - Lanjutkan PO Project {$projectCode}")
                ->body('Finance sudah menyetujui PR. Silakan lanjutkan proses pembuatan PO.')
                ->actions([
                    Action::make('open_po_review')
                        ->label('Buka Review PO')
                        ->button()
                        ->url('/admin/purchase-orders/review?pr_ids=' . urlencode(implode(',', $prIds))),
                ])
                ->success();

            foreach ($managerRecipients as $recipient) {
                $recipient->notifyNow($notification->toDatabase());
                DatabaseNotificationsSent::dispatch($recipient);
            }
        }
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

    private function allocatePortionFromSinglePiece(MaterialPiece $piece, int $projectId, float $allocated): void
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
                'keterangan' => trim(($piece->keterangan ?? '') . ' | Booking dari approval usulan alokasi inbound manual.'),
            ]);

            return;
        }

        $piece->update([
            'panjang' => $this->decimal($pieceLength - $allocated),
        ]);

        MaterialPiece::create([
            'material_id' => $piece->material_id,
            'inbound_id' => $piece->inbound_id,
            'booked_for_project_id' => $projectId,
            'shelf_location_id' => $piece->shelf_location_id,
            'supplier_id' => $piece->supplier_id,
            'panjang' => $this->decimal($allocated),
            'unit_price' => $piece->unit_price,
            'status' => 'booked',
            'sumber' => 'split_booking',
            'booked_at' => now(),
            'keterangan' => 'Split booking dari approval usulan alokasi inbound manual.',
        ]);
    }

    private function bookFromAvailablePieces(int $materialId, int $projectId, float $qtyNeeded, bool $tanpaPotongan = false): float
    {
        if ($tanpaPotongan) {
            $material = Material::query()->find($materialId);
            $rollStandar = (float) ($material?->default_roll_length ?? 0);
            $minimumUtuh = $rollStandar > 0 ? $rollStandar : $qtyNeeded;

            /** @var MaterialPiece|null $piece */
            $piece = MaterialPiece::query()
                ->where('material_id', $materialId)
                ->where('status', 'available')
                ->whereIn('sumber', ['inbound_baru', 'opening_balance'])
                ->where('panjang', '>=', $minimumUtuh)
                ->orderBy('panjang')
                ->lockForUpdate()
                ->first();

            if (!$piece) {
                return 0.0;
            }

            $pieceLength = (float) $piece->panjang;

            if ($pieceLength <= $qtyNeeded) {
                $piece->update([
                    'status' => 'booked',
                    'booked_for_project_id' => $projectId,
                    'booked_at' => now(),
                ]);

                return round($pieceLength, 2);
            }

            $piece->update([
                'panjang' => $this->decimal($pieceLength - $qtyNeeded),
                'sumber' => 'split_booking_residue',
                'keterangan' => trim(($piece->keterangan ?? '') . ' | Sisa split dari booking tanpa potongan.'),
            ]);

            MaterialPiece::create([
                'material_id' => $piece->material_id,
                'inbound_id' => $piece->inbound_id,
                'booked_for_project_id' => $projectId,
                'shelf_location_id' => $piece->shelf_location_id,
                'supplier_id' => $piece->supplier_id,
                'panjang' => $this->decimal($qtyNeeded),
                'unit_price' => $piece->unit_price,
                'status' => 'booked',
                'sumber' => 'split_booking',
                'booked_at' => now(),
                'keterangan' => 'Auto split booking dari task approval AI (mode tanpa potongan).',
            ]);

            return round($qtyNeeded, 2);
        }

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
