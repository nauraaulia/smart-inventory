<?php

namespace App\Services\AI;

use App\Jobs\ProsesKebutuhanProyekJob;
use App\Models\Inbound;
use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\Outbound;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskApprovalService
{
    public function approve(Task $task, User $actor, array $options = []): void
    {
        DB::transaction(function () use ($task, $actor, $options): void {
            match ($task->type) {
                'ai_booking_suggestion' => $this->approveAiBookingSuggestion($task, $actor, $options),
                'ai_inbound_allocation_suggestion' => $this->approveInboundAllocationSuggestion($task, $actor, $options),
                'ai_deadstock_recommendation' => $this->approveDeadstockRecommendation($task, $actor, $options),
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

        if ($task->type === 'ai_inbound_allocation_suggestion' && $projectId > 0) {
            $allocatedTotalQty = (float) data_get($payload, 'execution_result.allocated_total_qty', 0);

            if ($allocatedTotalQty > 0) {
                $refreshKey = 'ai_post_operational_approval_refresh_'.$projectId;
                if (Cache::add($refreshKey, 1, now()->addSeconds(15))) {
                    Cache::forget('ai_booking_project_hash_'.$projectId);
                    ProsesKebutuhanProyekJob::dispatch($projectId)->delay(now()->addSeconds(2));
                }
            }
        }
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
        $materialItems = collect(data_get($payload, 'items', []));
        $instructionSegments = collect(data_get($payload, 'ai_instruction.requested_segments', []))
            ->map(fn ($value): float => round((float) $value, 2))
            ->filter(fn (float $value): bool => $value > 0)
            ->values()
            ->all();
        $generatePr = (bool) ($options['generate_pr'] ?? true);
        $rejectionReason = trim((string) ($options['rejection_reason'] ?? ''));
        $decisionsInput = collect($options['material_decisions'] ?? []);
        $decisionsByMaterial = $decisionsInput
            ->mapWithKeys(fn ($row): array => [(int) ($row['material_id'] ?? 0) => $row]);
        $materialIdsForSnapshot = $materialItems
            ->pluck('material_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $stockAtApprovalByMaterial = [];
        if (! empty($materialIdsForSnapshot)) {
            $stockAtApprovalByMaterial = MaterialPiece::query()
                ->selectRaw('material_id, COALESCE(SUM(panjang), 0) as total_available')
                ->whereIn('material_id', $materialIdsForSnapshot)
                ->where('status', 'available')
                ->groupBy('material_id')
                ->get()
                ->mapWithKeys(fn ($row): array => [(int) $row->material_id => round((float) $row->total_available, 2)])
                ->all();
        }
        $project = $projectId > 0 ? Project::query()->find($projectId) : null;
        $bufferDays = (int) ($options['buffer_days'] ?? 3);

        $createdPrIds = [];
        $pendingShortages = [];
        $createdInboundSuggestionTaskIds = [];
        $touchedMaterialIds = [];
        $materialResults = [];

        // Tandai task selesai lebih awal agar hook sinkron stok tidak membuat task AI duplikat
        // ketika material piece berubah di tengah proses approval.
        data_set($payload, 'approval_trail.staff', [
            'status' => 'approved',
            'by_user_id' => $actor->id,
            'at' => now()->toDateTimeString(),
        ]);

        data_set($payload, 'recommendation_lock', [
            'locked' => true,
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

        if ($materialItems->isEmpty()) {
            $materialItems = collect($detail)->map(function (array $item): array {
                return [
                    'material_id' => (int) ($item['material_id'] ?? 0),
                    'material_name' => (string) ($item['nama_material'] ?? '-'),
                    'dibutuhkan' => (float) ($item['dibutuhkan'] ?? 0),
                    'satuan' => 'unit',
                    'recommended_piece_ids' => [],
                    'recommended_qty' => (float) ($item['dibutuhkan'] ?? 0),
                    'ai_reason' => (string) ($item['alasan_ai'] ?? ''),
                    'status' => 'pending',
                    'tanpa_potongan' => (bool) ($item['tanpa_potongan'] ?? false),
                ];
            });
        }

        $detailByMaterial = collect($detail)
            ->keyBy(fn (array $item): int => (int) ($item['material_id'] ?? 0));

        foreach ($materialItems as $item) {
            $materialId = (int) ($item['material_id'] ?? 0);
            $decisionRow = (array) ($decisionsByMaterial->get($materialId, []) ?? []);
            $detailRow = (array) ($detailByMaterial->get($materialId, []));
            $dibutuhkan = (float) ($item['dibutuhkan'] ?? 0);
            $tanpaPotongan = (bool) ($item['tanpa_potongan'] ?? ($detailRow['tanpa_potongan'] ?? false));
            $aiBookingQty = max(0, (float) ($item['recommended_qty'] ?? ($detailRow['recommended_qty'] ?? $dibutuhkan)));
            $aiPrQtyRaw = (float) ($item['recommended_pr_qty'] ?? ($detailRow['recommended_pr_qty'] ?? ($detailRow['qty_pr'] ?? ($dibutuhkan - $aiBookingQty))));
            $aiBookingQty = min($dibutuhkan, $aiBookingQty);
            $aiPrQty = min(max(0, $dibutuhkan - $aiBookingQty), max(0, $aiPrQtyRaw));
            $recommendedPieceIds = collect(! empty($item['recommended_piece_ids'] ?? []) ? ($item['recommended_piece_ids'] ?? []) : ($detailRow['recommended_piece_ids'] ?? []))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values()
                ->all();
            $recommendedPieces = collect(! empty($item['recommended_pieces'] ?? null) ? ($item['recommended_pieces'] ?? []) : ($detailRow['recommended_pieces'] ?? []))
                ->filter(fn ($row): bool => is_array($row) && (int) ($row['id'] ?? 0) > 0 && (float) ($row['take_qty'] ?? 0) > 0)
                ->values()
                ->all();
            $decision = strtolower((string) data_get($decisionRow, 'status', 'approved'));
            $materialRejectionReason = trim((string) (data_get($decisionRow, 'rejection_reason', '') ?: $rejectionReason));

            $normalizedSegments = $this->normalizeRequestedSegmentsForTarget(
                requestedSegments: $instructionSegments,
                targetQty: $aiBookingQty,
            );

            if (! empty($normalizedSegments) && ! empty($recommendedPieces)) {
                $recommendedTakeSorted = collect($recommendedPieces)
                    ->map(fn (array $row): float => round((float) ($row['take_qty'] ?? 0), 2))
                    ->filter(fn (float $value): bool => $value > 0)
                    ->sort()
                    ->values()
                    ->all();

                $targetSegmentsSorted = collect($normalizedSegments)
                    ->map(fn ($value): float => round((float) $value, 2))
                    ->filter(fn (float $value): bool => $value > 0)
                    ->sort()
                    ->values()
                    ->all();

                if ($recommendedTakeSorted !== $targetSegmentsSorted) {
                    throw ValidationException::withMessages([
                        'payload.items' => 'Detail piece rekomendasi tidak sinkron dengan segmen instruksi manager. Jalankan ulang AI agar rekomendasi terbaru sebelum approve.',
                    ]);
                }
            }

            if ($materialId <= 0 || $dibutuhkan <= 0) {
                continue;
            }

            if ($decision === 'pending') {
                $decision = 'approved';
            }

            $touchedMaterialIds[] = $materialId;

            if ($decision === 'rejected') {
                if ($materialRejectionReason === '') {
                    throw ValidationException::withMessages([
                        'rejection_reason' => 'Alasan penolakan wajib diisi jika ada material yang ditolak.',
                    ]);
                }

                $inboundTaskId = $this->createInboundAllocationTaskFromRejectedBooking(
                    materialId: $materialId,
                    projectId: $projectId,
                    qtyNeeded: $dibutuhkan,
                    projectCode: $projectCode,
                    materialName: (string) ($item['material_name'] ?? ('Material #'.$materialId)),
                    sourceTaskId: (int) $task->id,
                    rejectionReason: $materialRejectionReason,
                );

                if ($inboundTaskId) {
                    $createdInboundSuggestionTaskIds[] = $inboundTaskId;
                }

                $materialResults[] = [
                    'material_id' => $materialId,
                    'material_name' => (string) ($item['material_name'] ?? ('Material #'.$materialId)),
                    'status' => 'rejected',
                    'dibutuhkan' => $this->decimal($dibutuhkan),
                    'ai_booking_qty' => $this->decimal($aiBookingQty),
                    'ai_pr_qty' => $this->decimal($aiPrQty),
                    'booked_qty' => $this->decimal(0),
                    'shortage_qty' => $this->decimal($dibutuhkan),
                    'allocations' => [],
                    'ai_reason' => (string) ($item['ai_reason'] ?? ''),
                    'rejection_reason' => $materialRejectionReason,
                    'created_operational_task_id' => $inboundTaskId,
                ];

                continue;
            }

            $bookingResult = [
                'booked_qty' => 0.0,
                'allocations' => [],
                'ai_reason' => (string) ($item['ai_reason'] ?? ''),
            ];

            if ($aiBookingQty > 0) {
                if (! empty($recommendedPieces)) {
                    $bookingResult = $this->bookFromRecommendedPieces(
                        materialId: $materialId,
                        projectId: $projectId,
                        qtyNeeded: $aiBookingQty,
                        recommendedPieces: $recommendedPieces,
                    );

                    $remainingAfterRecommended = max(0, round($aiBookingQty - (float) ($bookingResult['booked_qty'] ?? 0), 2));
                    if ($remainingAfterRecommended > 0.00001) {
                        $fallbackResult = $this->bookFromAvailablePieces(
                            materialId: $materialId,
                            projectId: $projectId,
                            qtyNeeded: $remainingAfterRecommended,
                            tanpaPotongan: $tanpaPotongan,
                            preferredPieceIds: $recommendedPieceIds,
                        );

                        $bookingResult['booked_qty'] = round(
                            (float) ($bookingResult['booked_qty'] ?? 0) + (float) ($fallbackResult['booked_qty'] ?? 0),
                            2
                        );
                        $bookingResult['allocations'] = array_values(array_merge(
                            (array) ($bookingResult['allocations'] ?? []),
                            (array) ($fallbackResult['allocations'] ?? []),
                        ));
                    }
                } else {
                    $bookingResult = $this->bookFromAvailablePieces(
                        materialId: $materialId,
                        projectId: $projectId,
                        qtyNeeded: $aiBookingQty,
                        tanpaPotongan: $tanpaPotongan,
                        preferredPieceIds: $recommendedPieceIds,
                    );
                }
            }

            $bookedQty = (float) ($bookingResult['booked_qty'] ?? 0);
            $bookingShortage = max(0, $aiBookingQty - $bookedQty);
            $createdOperationalTaskId = null;

            if ($bookingShortage > 0) {
                $createdOperationalTaskId = $this->createInboundAllocationTaskFromRejectedBooking(
                    materialId: $materialId,
                    projectId: $projectId,
                    qtyNeeded: $bookingShortage,
                    projectCode: $projectCode,
                    materialName: (string) ($item['material_name'] ?? ('Material #'.$materialId)),
                    contextNote: 'belum terpenuhi dari booking AI (stok berubah saat approve)',
                );

                if ($createdOperationalTaskId) {
                    $createdInboundSuggestionTaskIds[] = $createdOperationalTaskId;
                }
            }

            $materialResults[] = [
                'material_id' => $materialId,
                'material_name' => (string) ($item['material_name'] ?? ('Material #'.$materialId)),
                'status' => 'approved',
                'dibutuhkan' => $this->decimal($dibutuhkan),
                'ai_booking_qty' => $this->decimal($aiBookingQty),
                'ai_pr_qty' => $this->decimal($aiPrQty),
                'booked_qty' => $this->decimal($bookedQty),
                'shortage_qty' => $this->decimal(max(0, $dibutuhkan - $bookedQty)),
                'booking_shortage_qty' => $this->decimal($bookingShortage),
                'allocations' => $bookingResult['allocations'] ?? [],
                'ai_reason' => (string) ($item['ai_reason'] ?? ($bookingResult['ai_reason'] ?? '')),
                'created_operational_task_id' => $createdOperationalTaskId,
            ];

            if ($aiPrQty > 0) {
                if (! $generatePr) {
                    $pendingShortages[] = [
                        'material_id' => $materialId,
                        'shortage_qty' => $this->decimal($aiPrQty),
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
                    'quantity' => $this->decimal($aiPrQty),
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
            $isMaterialReady = empty($pendingShortages)
                && count($createdPrIds) === 0
                && count($createdInboundSuggestionTaskIds) === 0;

            Project::whereKey($projectId)->update([
                'material_confirmed_at' => $isMaterialReady ? now() : null,
            ]);

            $this->syncProjectOutboundFromApproval(
                projectId: $projectId,
                actorId: (int) $actor->id,
                isMaterialReady: $isMaterialReady,
            );
        }
        $payload = $task->payload ?? [];
        data_set($payload, 'execution_result', [
            'created_draft_pr_ids' => $createdPrIds,
            'created_draft_pr_count' => count($createdPrIds),
            'pending_shortages_without_pr' => $pendingShortages,
            'generate_pr_selected' => $generatePr,
            'material_results' => $materialResults,
            'created_inbound_suggestion_task_ids' => $createdInboundSuggestionTaskIds,
        ]);

        $payload['items'] = collect($materialItems)
            ->map(function (array $item) use ($decisionsByMaterial, $stockAtApprovalByMaterial, $rejectionReason): array {
                $materialId = (int) ($item['material_id'] ?? 0);
                $decisionRow = (array) ($decisionsByMaterial->get($materialId, []) ?? []);
                $status = strtolower((string) data_get($decisionRow, 'status', 'approved'));
                $materialRejectionReason = trim((string) (data_get($decisionRow, 'rejection_reason', '') ?: $rejectionReason));

                $item['status'] = in_array($status, ['approved', 'rejected'], true) ? $status : 'approved';
                $item['stock_at_approval'] = (float) ($stockAtApprovalByMaterial[$materialId] ?? ($item['available_qty'] ?? 0));
                if ($item['status'] === 'rejected') {
                    $item['rejection_reason'] = $materialRejectionReason;
                }

                return $item;
            })
            ->values()
            ->all();

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

        $this->closeSiblingAiBookingSuggestionTasks($task, $actor->id);
    }

    private function createInboundAllocationTaskFromRejectedBooking(
        int $materialId,
        int $projectId,
        float $qtyNeeded,
        string $projectCode,
        string $materialName,
        string $contextNote = 'ditolak dari rekomendasi AI',
        ?int $sourceTaskId = null,
        string $rejectionReason = '',
    ): ?int {
        if ($materialId <= 0 || $projectId <= 0 || $qtyNeeded <= 0) {
            return null;
        }

        $material = Material::query()->find($materialId, ['id', 'satuan', 'default_roll_length']);
        $unit = (string) ($material?->resolveTrackingUnit() ?? 'unit');

        $availablePieces = MaterialPiece::query()
            ->with(['shelfLocation:id,kode_lokasi,nama_lokasi'])
            ->where('material_id', $materialId)
            ->where('status', 'available')
            ->orderByRaw("CASE
                WHEN sumber IN ('split_auto_allocation', 'split_booking', 'split_booking_residue') THEN 1
                WHEN sumber IN ('inbound_baru', 'opening_balance') THEN 2
                ELSE 3
            END ASC")
            ->orderBy('created_at')
            ->get();

        $piecesPayload = $availablePieces->map(function (MaterialPiece $piece): array {
            $receivedAt = $piece->inbound?->entry_date
                ? Carbon::parse($piece->inbound->entry_date)
                : $piece->created_at;

            return [
                'id' => (int) $piece->id,
                'panjang' => (float) $piece->panjang,
                'sumber' => (string) $piece->sumber,
                'lokasi_rak' => (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-')),
                'umur_hari' => $receivedAt ? (int) $receivedAt->diffInDays(now()) : null,
            ];
        })->values()->all();

        $allocationSuggestions = $this->buildManualAllocationSuggestions(
            materialId: $materialId,
            qtyNeeded: $qtyNeeded,
            preferredProjectId: $projectId,
        );

        $basePayload = [
            'project_id' => $projectId,
            'project_code' => $projectCode,
            'material_id' => $materialId,
            'material_name' => $materialName,
            'unit' => $unit,
            'qty_dibutuhkan' => $this->decimal($qtyNeeded),
            'total_available_qty' => round((float) $availablePieces->sum('panjang'), 2),
            'piece_ids' => collect($piecesPayload)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'available_pieces' => $piecesPayload,
            'allocation_suggestions' => $allocationSuggestions,
        ];

        if ($sourceTaskId > 0) {
            $basePayload['source_ai_task_id'] = $sourceTaskId;
        }

        if ($rejectionReason !== '') {
            $basePayload['rejection'] = [
                'reason' => $rejectionReason,
                'at' => now()->toDateTimeString(),
            ];
        }

        $existingPendingTasks = Task::query()
            ->where('type', 'ai_inbound_allocation_suggestion')
            ->where('related_type', 'project')
            ->where('related_id', $projectId)
            ->where('status', 'pending')
            ->where('payload->material_id', $materialId)
            ->orderByDesc('id')
            ->get();

        $task = $existingPendingTasks->first();

        if ($existingPendingTasks->count() > 1) {
            $existingPendingTasks->slice(1)->each(function (Task $existingTask): void {
                $payload = is_array($existingTask->payload) ? $existingTask->payload : [];
                data_set($payload, 'superseded', true);
                data_set($payload, 'superseded_at', now()->toDateTimeString());

                $existingTask->update([
                    'status' => 'done',
                    'handled_at' => now(),
                    'message' => 'Task operasional ini digantikan oleh task operasional terbaru untuk material yang sama.',
                    'payload' => $payload,
                ]);
            });
        }

        if ($task) {
            $payload = is_array($task->payload) ? $task->payload : [];
            $payload = array_merge($payload, $basePayload);

            $task->update([
                'title' => "Task Operasional Alokasi Inbound ({$materialName})",
                'message' => "Material {$materialName} {$contextNote}. Pilih manual piece untuk pemenuhan kebutuhan proyek {$projectCode}.",
                'payload' => $payload,
            ]);

            return (int) $task->id;
        }

        $task = Task::create([
            'for_role' => 'Staff Administrasi Gudang',
            'type' => 'ai_inbound_allocation_suggestion',
            'title' => "Task Operasional Alokasi Inbound ({$materialName})",
            'message' => "Material {$materialName} {$contextNote}. Pilih manual piece untuk pemenuhan kebutuhan proyek {$projectCode}.",
            'payload' => $basePayload,
            'status' => 'pending',
            'related_id' => $projectId,
            'related_type' => 'project',
        ]);

        return (int) $task->id;
    }

    private function approveInboundAllocationSuggestion(Task $task, User $actor, array $options = []): void
    {
        $payload = $task->payload ?? [];
        $defaultProjectId = (int) data_get($payload, 'project_id');
        $pieceId = (int) data_get($payload, 'piece_id');
        $inboundId = (int) data_get($payload, 'inbound_id');
        $materialId = (int) data_get($payload, 'material_id');
        $pieceIds = collect(data_get($payload, 'piece_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
        $selectedPieceIds = collect($options['selected_piece_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        if (empty($pieceIds) && $pieceId > 0) {
            $pieceIds = [$pieceId];
        }

        if (! empty($selectedPieceIds)) {
            $pieceIds = $selectedPieceIds;
        }

        if (empty($pieceIds)) {
            $pieceIds = collect(data_get($payload, 'available_pieces', []))
                ->map(fn ($row) => (int) ($row['id'] ?? 0))
                ->filter(fn ($id) => $id > 0)
                ->values()
                ->all();
        }

        $manualAlloc = collect($options['manual_allocation'] ?? []);
        $selectedManualAlloc = $manualAlloc->isNotEmpty()
            ? $manualAlloc
                ->filter(fn ($row) => (bool) ($row['selected'] ?? true))
                ->map(fn ($row) => [
                    'project_id' => (int) ($row['project_id'] ?? $defaultProjectId),
                    'suggested_qty' => (float) ($row['qty'] ?? ($row['suggested_qty'] ?? 0)),
                ])
            : collect();

        $suggestions = $selectedManualAlloc->isNotEmpty()
            ? $selectedManualAlloc
            : collect(data_get($payload, 'allocation_suggestions', []));

        if (($pieceId <= 0 && empty($pieceIds)) || $suggestions->isEmpty()) {
            $this->markDone($task, $actor);

            return;
        }

        $availablePoolQty = $this->availablePoolQty($materialId, $inboundId, $pieceIds);

        if ($suggestions->isEmpty() && $defaultProjectId > 0 && $availablePoolQty > 0) {
            $suggestions = collect([
                [
                    'project_id' => $defaultProjectId,
                    'suggested_qty' => $availablePoolQty,
                ],
            ]);
        }

        if ($availablePoolQty <= 0) {
            data_set($payload, 'execution_result', [
                'status' => 'skipped',
                'reason' => 'Piece pool sudah tidak available saat approval diproses.',
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

        $remaining = $availablePoolQty;
        $allocations = [];

        foreach ($suggestions as $row) {
            if ($remaining <= 0) {
                break;
            }

            $projectId = (int) data_get($row, 'project_id');
            $requestedQty = (float) data_get($row, 'suggested_qty', 0);

            if ($projectId <= 0 && $defaultProjectId > 0) {
                $projectId = $defaultProjectId;
            }

            if ($projectId <= 0 || $requestedQty <= 0) {
                continue;
            }

            $allocQty = min($remaining, $requestedQty);

            if ($allocQty <= 0) {
                continue;
            }

            $allocated = $this->allocateFromAvailablePool(
                materialId: $materialId,
                inboundId: $inboundId > 0 ? $inboundId : null,
                pieceIds: $pieceIds,
                projectId: $projectId,
                qtyNeeded: $allocQty,
            );

            if ($allocated <= 0) {
                continue;
            }

            $remaining = $this->availablePoolQty($materialId, $inboundId, $pieceIds);

            $allocations[] = [
                'project_id' => $projectId,
                'project_code' => $this->projectCodeFromPayload(['project_id' => $projectId], $projectId),
                'allocated_qty' => round($allocated, 2),
            ];

            if ($remaining <= 0) {
                break;
            }
        }

        $allocatedTotalQty = round((float) collect($allocations)->sum(fn ($row): float => (float) ($row['allocated_qty'] ?? 0)), 2);
        $returnedToStockQty = round(max(0, (float) $remaining), 2);

        $manualAllocationForPayload = $manualAlloc->isNotEmpty()
            ? $manualAlloc
                ->map(function ($row) use ($defaultProjectId): array {
                    $projectId = (int) ($row['project_id'] ?? $defaultProjectId);

                    return [
                        'selected' => (bool) ($row['selected'] ?? true),
                        'project_id' => $projectId,
                        'project_code' => (string) ($row['project_code'] ?? $this->projectCodeFromPayload(['project_id' => $projectId], $projectId)),
                        'pr_status' => (string) ($row['pr_status'] ?? '-'),
                        'qty' => (float) ($row['qty'] ?? ($row['suggested_qty'] ?? 0)),
                    ];
                })
                ->values()
                ->all()
            : collect($suggestions)
                ->map(function ($row) use ($defaultProjectId): array {
                    $projectId = (int) data_get($row, 'project_id', $defaultProjectId);

                    return [
                        'selected' => true,
                        'project_id' => $projectId,
                        'project_code' => $this->projectCodeFromPayload(['project_id' => $projectId], $projectId),
                        'pr_status' => (string) data_get($row, 'pr_status', '-'),
                        'qty' => (float) data_get($row, 'suggested_qty', 0),
                    ];
                })
                ->values()
                ->all();

        data_set($payload, 'selected_piece_ids', array_values(array_unique(array_map('intval', $pieceIds))));
        data_set($payload, 'manual_allocation', $manualAllocationForPayload);

        data_set($payload, 'execution_result', [
            'status' => 'processed',
            'piece_id' => $pieceId,
            'selected_piece_ids' => $pieceIds,
            'allocated_rows' => $allocations,
            'allocated_total_qty' => $allocatedTotalQty,
            'returned_to_stock_qty' => $returnedToStockQty,
            'remaining_piece_length' => $remaining,
        ]);

        $task->update([
            'status' => 'done',
            'handled_by' => $actor->id,
            'handled_at' => now(),
            'message' => empty($allocations)
                ? 'Keterangan: Tidak ada alokasi baru. 0 unit dialokasikan, '.$this->decimal($returnedToStockQty).' unit masuk ke stok available.'
                : 'Keterangan: '.$this->decimal($allocatedTotalQty).' unit dialokasikan, '.$this->decimal($returnedToStockQty).' unit masuk ke stok available.',
            'payload' => $payload,
        ]);

        $this->closeSiblingInboundAllocationTasks($task);
    }

    private function approveDeadstockRecommendation(Task $task, User $actor, array $options = []): void
    {
        $payload = $task->payload ?? [];
        data_set($payload, 'approval_trail.manager_operasional', [
            'status' => 'approved',
            'by_user_id' => $actor->id,
            'at' => now()->toDateTimeString(),
            'note' => 'Task deadstock disetujui dan menunggu keputusan final pada menu Aging Material.',
        ]);
        data_set($payload, 'workflow_state', 'approved_for_aging');

        $task->update([
            'status' => 'pending',
            'handled_by' => $actor->id,
            'handled_at' => now(),
            'message' => 'Keterangan: Screening deadstock di-ACC. Lanjutkan keputusan final di menu Aging Material.',
            'payload' => $payload,
        ]);

        $this->logUserAction(
            actor: $actor,
            action: 'Approve',
            module: 'Aging Material',
            description: "ACC screening deadstock task ID {$task->id}. Menunggu proses final di menu Aging Material.",
        );
    }

    public function finalizeDeadstockAging(Task $task, User $actor, array $options = []): void
    {
        DB::transaction(function () use ($task, $actor, $options): void {
            if ($task->type !== 'ai_deadstock_recommendation') {
                throw ValidationException::withMessages([
                    'deadstock_decisions' => 'Task ini bukan task deadstock.',
                ]);
            }

            if ($task->status !== 'pending' || (string) data_get($task->payload ?? [], 'workflow_state', '') !== 'approved_for_aging') {
                throw ValidationException::withMessages([
                    'deadstock_decisions' => 'Task harus di-ACC dulu di menu Tasks sebelum diproses di Aging Material.',
                ]);
            }

            $payload = $task->payload ?? [];
            $existingRows = collect(data_get($payload, 'deadstock_items', []))
                ->filter(fn ($row): bool => is_array($row) && (int) ($row['material_id'] ?? 0) > 0)
                ->keyBy(fn (array $row): int => (int) $row['material_id']);
            $rows = collect($options['deadstock_decisions'] ?? [])
                ->filter(fn ($row): bool => is_array($row) && (int) ($row['material_id'] ?? 0) > 0)
                ->map(function (array $row) use ($existingRows): array {
                    $materialId = (int) ($row['material_id'] ?? 0);
                    $existing = (array) ($existingRows->get($materialId) ?? []);

                    return array_merge($existing, $row, [
                        'material_id' => $materialId,
                    ]);
                })
                ->values();

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages([
                    'deadstock_decisions' => 'Keputusan per material wajib diisi sebelum submit.',
                ]);
            }

            $normalizedRows = $rows->map(function (array $row) use ($actor): array {
                $availableQty = round(max(0, (float) ($row['available_qty'] ?? 0)), 2);
                $qtyKept = round(max(0, (float) ($row['qty_kept'] ?? $availableQty)), 2);
                $qtyBuang = round(max(0, (float) ($row['qty_buang'] ?? 0)), 2);
                $qtyJualMurah = round(max(0, (float) ($row['qty_jual_murah'] ?? 0)), 2);
                $qtyReturnDistributor = round(max(0, (float) ($row['qty_return_distributor'] ?? 0)), 2);
                $totalInput = round($qtyKept + $qtyBuang + $qtyJualMurah + $qtyReturnDistributor, 2);

                if (abs($totalInput - $availableQty) > 0.01) {
                    throw ValidationException::withMessages([
                        'deadstock_decisions' => 'Total qty status harus sama dengan stok untuk material '.(string) ($row['material_name'] ?? ('#'.(int) ($row['material_id'] ?? 0))).'.',
                    ]);
                }

                $removedBuang = 0.0;
                $removedJual = 0.0;
                $removedReturn = 0.0;

                if ($qtyBuang > 0) {
                    $removedBuang = $this->reduceAvailableStockForDeadstock(
                        materialId: (int) ($row['material_id'] ?? 0),
                        qtyToRemove: $qtyBuang,
                        decision: 'buang',
                        actorId: (int) $actor->id,
                    );
                }

                if ($qtyJualMurah > 0) {
                    $removedJual = $this->reduceAvailableStockForDeadstock(
                        materialId: (int) ($row['material_id'] ?? 0),
                        qtyToRemove: $qtyJualMurah,
                        decision: 'jual_murah',
                        actorId: (int) $actor->id,
                    );
                }

                if ($qtyReturnDistributor > 0) {
                    $removedReturn = $this->reduceAvailableStockForDeadstock(
                        materialId: (int) ($row['material_id'] ?? 0),
                        qtyToRemove: $qtyReturnDistributor,
                        decision: 'return_distributor',
                        actorId: (int) $actor->id,
                    );
                }

                $qtyDisposed = round($removedBuang + $removedJual + $removedReturn, 2);
                $qtyKept = round(max(0, $availableQty - $qtyDisposed), 2);
                $decision = 'monitor';

                $activeDisposals = collect([
                    'buang' => $removedBuang,
                    'jual_murah' => $removedJual,
                    'return_distributor' => $removedReturn,
                ])->filter(fn (float $qty): bool => $qty > 0.00001);

                if ($activeDisposals->count() > 1 || ($activeDisposals->count() >= 1 && $qtyKept > 0.00001)) {
                    $decision = 'mixed';
                } elseif ($activeDisposals->count() === 1) {
                    $decision = (string) $activeDisposals->keys()->first();
                }

                return [
                    'material_id' => (int) ($row['material_id'] ?? 0),
                    'material_name' => (string) ($row['material_name'] ?? ('Material #'.(int) ($row['material_id'] ?? 0))),
                    'available_qty' => $availableQty,
                    'satuan' => (string) ($row['satuan'] ?? 'unit'),
                    'last_used_days' => (int) ($row['last_used_days'] ?? 0),
                    'prioritas' => (string) ($row['prioritas'] ?? 'sedang'),
                    'rekomendasi' => (string) ($row['rekomendasi'] ?? 'monitor'),
                    'alasan' => (string) ($row['alasan'] ?? ''),
                    'manager_decision' => $decision,
                    'qty_kept' => round($qtyKept, 2),
                    'qty_disposed' => round($qtyDisposed, 2),
                    'qty_buang' => round($removedBuang, 2),
                    'qty_jual_murah' => round($removedJual, 2),
                    'qty_return_distributor' => round($removedReturn, 2),
                    'removed_from_stock_qty' => round($qtyDisposed, 2),
                ];
            })->values();

            data_set($payload, 'workflow_state', 'completed_in_aging');
            data_set($payload, 'deadstock_items', $normalizedRows->all());
            data_set($payload, 'execution_result', [
                'decisions' => $normalizedRows
                    ->map(fn (array $row): array => [
                        'material_id' => (int) $row['material_id'],
                        'material_name' => (string) $row['material_name'],
                        'decision' => (string) $row['manager_decision'],
                        'qty_kept' => (float) ($row['qty_kept'] ?? 0),
                        'qty_disposed' => (float) ($row['qty_disposed'] ?? 0),
                    ])
                    ->all(),
                'decision_counts' => $normalizedRows->countBy(fn (array $row): string => (string) ($row['manager_decision'] ?? 'monitor')),
                'total_qty_kept' => round((float) $normalizedRows->sum(fn (array $row): float => (float) ($row['qty_kept'] ?? 0)), 2),
                'total_qty_disposed' => round((float) $normalizedRows->sum(fn (array $row): float => (float) ($row['qty_disposed'] ?? 0)), 2),
                'finalized_by' => $actor->id,
                'finalized_at' => now()->toDateTimeString(),
            ]);

            $counts = collect(data_get($payload, 'execution_result.decision_counts', []));
            $summary = 'Keterangan: Aging material selesai diproses.';
            if ($counts->isNotEmpty()) {
                $summary = 'Keterangan: Aging selesai. '.$counts
                    ->map(fn ($count, $key): string => $key.' '.$count.' material')
                    ->implode(', ').'. Total buang/jual/return '.$this->decimal((float) data_get($payload, 'execution_result.total_qty_disposed', 0)).' unit.';
            }

            $task->update([
                'status' => 'done',
                'handled_by' => $actor->id,
                'handled_at' => now(),
                'message' => $summary,
                'payload' => $payload,
            ]);
        });

        $this->logUserAction(
            actor: $actor,
            action: 'Approve',
            module: 'Aging Material',
            description: "Finalize aging task {$task->type} (ID {$task->id}) dengan keputusan qty keep/dispose.",
        );
    }

    private function reduceAvailableStockForDeadstock(int $materialId, float $qtyToRemove, string $decision, int $actorId): float
    {
        if ($materialId <= 0 || $qtyToRemove <= 0) {
            return 0.0;
        }

        $remaining = round($qtyToRemove, 2);
        $removed = 0.0;

        while ($remaining > 0.00001) {
            /** @var MaterialPiece|null $piece */
            $piece = MaterialPiece::query()
                ->where('material_id', $materialId)
                ->where('status', 'available')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $piece) {
                break;
            }

            $pieceLength = round((float) $piece->panjang, 2);

            if ($pieceLength <= ($remaining + 0.00001)) {
                $piece->update([
                    'status' => 'used',
                    'used_at' => now(),
                    'keterangan' => trim((string) ($piece->keterangan ?? '').' | Aging: '.$decision.' oleh user #'.$actorId),
                ]);

                $removed += $pieceLength;
                $remaining = round(max(0, $remaining - $pieceLength), 2);

                continue;
            }

            $piece->update([
                'panjang' => $this->decimal($pieceLength - $remaining),
                'keterangan' => trim((string) ($piece->keterangan ?? '').' | Sisa split dari aging ('.$decision.').'),
            ]);

            MaterialPiece::create([
                'material_id' => $piece->material_id,
                'inbound_id' => $piece->inbound_id,
                'booked_for_project_id' => null,
                'shelf_location_id' => $piece->shelf_location_id,
                'supplier_id' => $piece->supplier_id,
                'panjang' => $this->decimal($remaining),
                'unit_price' => $piece->unit_price,
                'status' => 'used',
                'sumber' => 'aging_clearance',
                'used_at' => now(),
                'keterangan' => 'Pengurangan stok dari keputusan aging: '.$decision.' oleh user #'.$actorId,
            ]);

            $removed += $remaining;
            $remaining = 0.0;
        }

        return round($removed, 2);
    }

    /**
     * @return array<int, array{project_id:int, project_code:string, pr_status:string, suggested_qty:float}>
     */
    private function buildManualAllocationSuggestions(int $materialId, float $qtyNeeded, ?int $preferredProjectId = null): array
    {
        if ($materialId <= 0 || $qtyNeeded <= 0) {
            return [];
        }

        $remaining = $qtyNeeded;
        $projectCodes = Project::query()->pluck('kode_proyek', 'id');
        $suggestions = [];

        if (($preferredProjectId ?? 0) > 0) {
            $preferredOutstanding = $this->outstandingNeed((int) $preferredProjectId, $materialId);
            if ($preferredOutstanding > 0) {
                $qty = min($remaining, $preferredOutstanding);
                $suggestions[] = [
                    'project_id' => (int) $preferredProjectId,
                    'project_code' => (string) ($projectCodes[(int) $preferredProjectId] ?? ('#'.(int) $preferredProjectId)),
                    'pr_status' => 'priority_project',
                    'suggested_qty' => round($qty, 2),
                ];
                $remaining -= $qty;
            }
        }

        if ($remaining <= 0.00001) {
            return $suggestions;
        }

        $projectItemProjectIds = ProjectItem::query()
            ->where('material_id', $materialId)
            ->whereNotNull('project_id')
            ->distinct()
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();

        foreach ($projectItemProjectIds as $projectId) {
            if ($remaining <= 0.00001) {
                break;
            }

            if ($preferredProjectId && (int) $projectId === (int) $preferredProjectId) {
                continue;
            }

            $needed = $this->outstandingNeed((int) $projectId, $materialId);
            if ($needed <= 0) {
                continue;
            }

            $qty = min($remaining, $needed);
            $suggestions[] = [
                'project_id' => (int) $projectId,
                'project_code' => (string) ($projectCodes[(int) $projectId] ?? ('#'.(int) $projectId)),
                'pr_status' => 'project_need',
                'suggested_qty' => round($qty, 2),
            ];
            $remaining -= $qty;
        }

        return $suggestions;
    }

    private function availablePoolQty(int $materialId, int $inboundId, array $pieceIds): float
    {
        $query = MaterialPiece::query()
            ->where('status', 'available')
            ->where('material_id', $materialId);

        if ($inboundId > 0) {
            $query->where('inbound_id', $inboundId);
        }

        if (! empty($pieceIds)) {
            $query->whereIn('id', $pieceIds);
        }

        return round((float) $query->sum('panjang'), 2);
    }

    private function allocateFromAvailablePool(int $materialId, ?int $inboundId, array $pieceIds, int $projectId, float $qtyNeeded): float
    {
        $remaining = max(0, $qtyNeeded);
        $allocated = 0.0;

        while ($remaining > 0.00001) {
            $query = MaterialPiece::query()
                ->lockForUpdate()
                ->where('status', 'available')
                ->where('material_id', $materialId)
                ->orderByDesc('panjang');

            if (($inboundId ?? 0) > 0) {
                $query->where('inbound_id', $inboundId);
            }

            if (! empty($pieceIds)) {
                $query->whereIn('id', $pieceIds);
            }

            /** @var MaterialPiece|null $piece */
            $piece = $query->first();

            if (! $piece) {
                break;
            }

            $chunk = min($remaining, (float) $piece->panjang);

            if ($chunk <= 0) {
                break;
            }

            $this->allocatePortionFromSinglePiece($piece, $projectId, $chunk);

            $allocated += $chunk;
            $remaining -= $chunk;
        }

        return round($allocated, 2);
    }

    private function buildAiApprovalMessage(
        string $defaultMessage,
        bool $generatePr,
        array $pendingShortages,
        int $createdDraftPrCount,
    ): string {
        if (! $generatePr && ! empty($pendingShortages)) {
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

        if (! empty($prIds)) {
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

        if (! empty($prIds)) {
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
                        ->url('/admin/purchase-orders/review?pr_ids='.urlencode(implode(',', $prIds))),
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

        if (! empty($prIds)) {
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

        if (! empty($prIds)) {
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

    private function syncProjectOutboundFromApproval(int $projectId, int $actorId, bool $isMaterialReady): void
    {
        if ($projectId <= 0) {
            return;
        }

        $project = Project::query()
            ->with(['items.material:id,satuan,default_roll_length'])
            ->find($projectId);

        if (! $project) {
            return;
        }

        $outbound = Outbound::query()
            ->where('project_id', $projectId)
            ->where('outbound_manual', false)
            ->orderByDesc('id')
            ->first();

        // Create outbound queue entry once material is complete so staff gudang can process it.
        if (! $outbound && $isMaterialReady) {
            $outbound = Outbound::query()->create([
                'project_id' => $projectId,
                'status' => 'pending',
                'outbound_manual' => false,
                'created_by' => $actorId > 0 ? $actorId : null,
            ]);
        }

        if (! $outbound) {
            return;
        }

        if ($outbound->status === 'taken') {
            return;
        }

        if (! $isMaterialReady && $outbound->status === 'ready') {
            $outbound->update([
                'status' => 'pending',
                'ready_at' => null,
                'prepared_by' => null,
            ]);
        }

        $requiredByMaterial = $project->items
            ->groupBy('material_id')
            ->map(function ($rows): array {
                $first = $rows->first();

                return [
                    'material_id' => (int) ($first->material_id ?? 0),
                    'qty' => round((float) $rows->sum('jumlah_diminta'), 2),
                    'unit' => (string) ($first?->material?->resolveTrackingUnit() ?? 'unit'),
                ];
            })
            ->filter(fn (array $row): bool => (int) ($row['material_id'] ?? 0) > 0 && (float) ($row['qty'] ?? 0) > 0)
            ->values();

        if ($requiredByMaterial->isEmpty()) {
            return;
        }

        $outbound->items()->delete();

        foreach ($requiredByMaterial as $row) {
            $outbound->items()->create([
                'material_id' => (int) $row['material_id'],
                'qty' => $this->decimal((float) $row['qty']),
                'unit' => (string) $row['unit'],
            ]);
        }
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

    private function closeSiblingInboundAllocationTasks(Task $task): void
    {
        if ($task->type !== 'ai_inbound_allocation_suggestion') {
            return;
        }

        $projectId = (int) $task->related_id;
        $materialId = (int) data_get($task->payload, 'material_id');

        if ($projectId <= 0 || $materialId <= 0) {
            return;
        }

        Task::query()
            ->where('type', 'ai_inbound_allocation_suggestion')
            ->where('related_type', 'project')
            ->where('related_id', $projectId)
            ->where('status', 'pending')
            ->where('payload->material_id', $materialId)
            ->whereKeyNot($task->id)
            ->get()
            ->each(function (Task $sibling): void {
                $payload = is_array($sibling->payload) ? $sibling->payload : [];
                data_set($payload, 'superseded', true);
                data_set($payload, 'superseded_at', now()->toDateTimeString());

                $sibling->update([
                    'status' => 'done',
                    'handled_at' => now(),
                    'message' => 'Task operasional ini digantikan oleh task operasional lain yang sudah diproses untuk material yang sama.',
                    'payload' => $payload,
                ]);
            });
    }

    private function closeSiblingAiBookingSuggestionTasks(Task $task, int $actorId): void
    {
        if ($task->type !== 'ai_booking_suggestion') {
            return;
        }

        $projectId = (int) $task->related_id;
        if ($projectId <= 0) {
            return;
        }

        Task::query()
            ->where('type', 'ai_booking_suggestion')
            ->where('related_type', 'project')
            ->where('related_id', $projectId)
            ->where('status', 'pending')
            ->whereKeyNot($task->id)
            ->get()
            ->each(function (Task $sibling) use ($actorId): void {
                $payload = is_array($sibling->payload) ? $sibling->payload : [];
                data_set($payload, 'superseded', true);
                data_set($payload, 'superseded_at', now()->toDateTimeString());
                data_set($payload, 'resolved_via_material_decision', true);

                $sibling->update([
                    'status' => 'done',
                    'handled_by' => $actorId > 0 ? $actorId : null,
                    'handled_at' => now(),
                    'message' => 'Task AI ini ditutup karena keputusan material sudah diproses dan dialihkan ke hasil akhir terbaru.',
                    'payload' => $payload,
                ]);
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
                'keterangan' => trim(($piece->keterangan ?? '').' | Booking dari approval usulan alokasi inbound manual.'),
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

    /**
     * @param  array<int, array<string, mixed>>  $recommendedPieces
     * @return array{booked_qty: float, allocations: array<int, array<string, mixed>>, ai_reason: string}
     */
    private function bookFromRecommendedPieces(int $materialId, int $projectId, float $qtyNeeded, array $recommendedPieces): array
    {
        $remaining = max(0, round($qtyNeeded, 2));
        $booked = 0.0;
        $allocations = [];

        foreach ($recommendedPieces as $row) {
            if ($remaining <= 0.00001) {
                break;
            }

            $pieceId = (int) ($row['id'] ?? 0);
            $takeRequested = max(0, round((float) ($row['take_qty'] ?? 0), 2));

            if ($pieceId <= 0 || $takeRequested <= 0) {
                continue;
            }

            /** @var MaterialPiece|null $piece */
            $piece = MaterialPiece::query()
                ->with(['shelfLocation:id,kode_lokasi,nama_lokasi'])
                ->lockForUpdate()
                ->where('id', $pieceId)
                ->where('material_id', $materialId)
                ->where('status', 'available')
                ->first();

            if (! $piece) {
                continue;
            }

            $pieceLength = round((float) $piece->panjang, 2);
            if ($pieceLength <= 0) {
                continue;
            }

            $takeQty = min($takeRequested, $remaining, $pieceLength);
            if ($takeQty <= 0.00001) {
                continue;
            }

            $this->allocatePortionFromSinglePiece($piece, $projectId, $takeQty);

            $booked += $takeQty;
            $remaining = max(0, round($remaining - $takeQty, 2));
            $allocations[] = [
                'piece_id' => (int) $pieceId,
                'taken_qty' => round($takeQty, 2),
                'original_length' => $pieceLength,
                'remaining_length' => round(max(0, $pieceLength - $takeQty), 2),
                'rack' => (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-')),
                'was_split' => $takeQty + 0.00001 < $pieceLength,
                'source' => 'recommended_pieces',
            ];
        }

        return [
            'booked_qty' => round($booked, 2),
            'allocations' => $allocations,
            'ai_reason' => 'Booking mengikuti detail piece hasil ACC task AI.',
        ];
    }

    /**
     * @param  array<int, int>  $preferredPieceIds
     * @return array{booked_qty: float, allocations: array<int, array<string, mixed>>, ai_reason: string}
     */
    private function bookFromAvailablePieces(int $materialId, int $projectId, float $qtyNeeded, bool $tanpaPotongan = false, array $preferredPieceIds = []): array
    {
        $allocations = [];

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

            if (! $piece) {
                return [
                    'booked_qty' => 0.0,
                    'allocations' => [],
                    'ai_reason' => 'Mode tanpa potongan aktif, tetapi tidak ada roll utuh yang mencukupi kebutuhan.',
                ];
            }

            $pieceLength = (float) $piece->panjang;
            $entryDate = $piece->inbound?->entry_date ? Carbon::parse($piece->inbound->entry_date) : $piece->created_at;
            $ageDays = $entryDate ? $entryDate->diffInDays(now()) : 0;

            if ($pieceLength <= $qtyNeeded) {
                $piece->update([
                    'status' => 'booked',
                    'booked_for_project_id' => $projectId,
                    'booked_at' => now(),
                ]);

                return [
                    'booked_qty' => round($pieceLength, 2),
                    'allocations' => [[
                        'piece_id' => (int) $piece->id,
                        'taken_qty' => round($pieceLength, 2),
                        'original_length' => round($pieceLength, 2),
                        'remaining_length' => 0,
                        'rack' => (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-')),
                        'was_split' => false,
                    ]],
                    'ai_reason' => "Disarankan ambil piece #{$piece->id} ({$this->formatQty($pieceLength)}, masuk {$ageDays} hari lalu) karena mode tanpa potongan mewajibkan roll utuh.",
                ];
            }

            $piece->update([
                'panjang' => $this->decimal($pieceLength - $qtyNeeded),
                'sumber' => 'split_booking_residue',
                'keterangan' => trim(($piece->keterangan ?? '').' | Sisa split dari booking tanpa potongan.'),
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

            return [
                'booked_qty' => round($qtyNeeded, 2),
                'allocations' => [[
                    'piece_id' => (int) $piece->id,
                    'taken_qty' => round($qtyNeeded, 2),
                    'original_length' => round($pieceLength, 2),
                    'remaining_length' => round($pieceLength - $qtyNeeded, 2),
                    'rack' => (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-')),
                    'was_split' => true,
                ]],
                'ai_reason' => "Disarankan potong dari piece #{$piece->id} ({$this->formatQty($pieceLength)}, masuk {$ageDays} hari lalu) karena paling efisien untuk kebutuhan {$this->formatQty($qtyNeeded)}.",
            ];
        }

        $remaining = $qtyNeeded;
        $booked = 0.0;
        $usedFirstPieceId = null;
        $usedFirstPieceLength = null;
        $usedFirstPieceAge = null;

        while ($remaining > 0.00001) {
            $pieceQuery = MaterialPiece::query()
                ->with(['shelfLocation:id,kode_lokasi,nama_lokasi', 'inbound:id,entry_date'])
                ->where('material_id', $materialId)
                ->where('status', 'available')
                ->when(! empty($preferredPieceIds), fn ($q) => $q->whereIn('id', $preferredPieceIds))
                ->orderByRaw('CASE WHEN panjang >= ? THEN 0 ELSE 1 END ASC', [$remaining])
                ->orderByRaw("CASE
                    WHEN sumber IN ('split_auto_allocation', 'split_booking', 'split_booking_residue') THEN 1
                    WHEN sumber IN ('inbound_baru', 'opening_balance') THEN 2
                    ELSE 3
                END ASC")
                ->orderByRaw('CASE WHEN panjang >= ? THEN panjang ELSE -panjang END ASC', [$remaining])
                ->orderByRaw('COALESCE((SELECT entry_date FROM inbounds WHERE inbounds.id = material_pieces.inbound_id), material_pieces.created_at) ASC')
                ->lockForUpdate();

            /** @var MaterialPiece|null $piece */
            $piece = $pieceQuery->first();

            if (! $piece && ! empty($preferredPieceIds)) {
                $preferredPieceIds = [];

                continue;
            }

            if (! $piece) {
                break;
            }

            if ($remaining <= 0) {
                break;
            }

            $pieceLength = (float) $piece->panjang;
            $entryDate = $piece->inbound?->entry_date ? Carbon::parse($piece->inbound->entry_date) : $piece->created_at;
            $ageDays = $entryDate ? $entryDate->diffInDays(now()) : 0;

            if ($usedFirstPieceId === null) {
                $usedFirstPieceId = (int) $piece->id;
                $usedFirstPieceLength = $pieceLength;
                $usedFirstPieceAge = $ageDays;
            }

            if ($pieceLength <= $remaining) {
                $piece->update([
                    'status' => 'booked',
                    'booked_for_project_id' => $projectId,
                    'booked_at' => now(),
                ]);

                $remaining -= $pieceLength;
                $booked += $pieceLength;

                $allocations[] = [
                    'piece_id' => (int) $piece->id,
                    'taken_qty' => round($pieceLength, 2),
                    'original_length' => round($pieceLength, 2),
                    'remaining_length' => 0,
                    'rack' => (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-')),
                    'was_split' => false,
                ];

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

            $allocations[] = [
                'piece_id' => (int) $piece->id,
                'taken_qty' => round($remaining, 2),
                'original_length' => round($pieceLength, 2),
                'remaining_length' => round($pieceLength - $remaining, 2),
                'rack' => (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-')),
                'was_split' => true,
            ];

            $booked += $remaining;
            $remaining = 0;
        }

        $reason = 'Tidak ada piece available yang sesuai untuk dibooking.';

        if ($usedFirstPieceId !== null) {
            $leftover = max(0, round((float) ($usedFirstPieceLength ?? 0) - $qtyNeeded, 2));
            $reason = "Disarankan potong dari piece #{$usedFirstPieceId} ({$this->formatQty((float) ($usedFirstPieceLength ?? 0))}, masuk ".(int) ($usedFirstPieceAge ?? 0)." hari lalu) karena ukuran paling efisien untuk kebutuhan {$this->formatQty($qtyNeeded)} dengan sisa {$this->formatQty($leftover)} yang masih berguna.";
        }

        return [
            'booked_qty' => round($booked, 2),
            'allocations' => $allocations,
            'ai_reason' => $reason,
        ];
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function formatQty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'m';
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
        if (! $project || blank($project->tanggal_mulai)) {
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
        if (! $material) {
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

        if (! $latestSupplierId) {
            return 7;
        }

        $leadTime = (int) Supplier::query()
            ->whereKey($latestSupplierId)
            ->value('lead_time_hari');

        return $leadTime > 0 ? $leadTime : 7;
    }

    /**
     * @param  array<int, float|int|string>  $requestedSegments
     * @return array<int, float>
     */
    private function normalizeRequestedSegmentsForTarget(array $requestedSegments, float $targetQty): array
    {
        $target = round((float) $targetQty, 2);
        if ($target <= 0) {
            return [];
        }

        $segments = collect($requestedSegments)
            ->map(fn ($value): float => round((float) $value, 2))
            ->filter(fn (float $value): bool => $value > 0)
            ->values()
            ->all();

        if (empty($segments)) {
            return [];
        }

        $sum = round((float) array_sum($segments), 2);
        if (abs($sum - $target) <= 0.01) {
            return $segments;
        }

        return $this->pickSegmentSubsetForTarget($segments, $target);
    }

    /**
     * @param  array<int, float>  $segments
     * @return array<int, float>
     */
    private function pickSegmentSubsetForTarget(array $segments, float $target): array
    {
        $targetInt = (int) round($target * 100);
        if ($targetInt <= 0) {
            return [];
        }

        $segmentsInt = array_map(fn (float $value): int => (int) round($value * 100), $segments);
        $n = count($segmentsInt);
        if ($n === 0 || $n > 30) {
            return [];
        }

        $dp = [0 => []];

        foreach ($segmentsInt as $idx => $valueInt) {
            $next = $dp;
            foreach ($dp as $sum => $pickedIdx) {
                $newSum = $sum + $valueInt;
                if ($newSum > $targetInt) {
                    continue;
                }

                $newPicked = $pickedIdx;
                $newPicked[] = $idx;

                if (! array_key_exists($newSum, $next)) {
                    $next[$newSum] = $newPicked;

                    continue;
                }

                $current = (array) ($next[$newSum] ?? []);
                if (count($newPicked) > count($current)) {
                    $next[$newSum] = $newPicked;
                }
            }

            $dp = $next;
        }

        if (! array_key_exists($targetInt, $dp)) {
            return [];
        }

        return array_values(array_map(fn (int $idx): float => round((float) ($segments[$idx] ?? 0), 2), $dp[$targetInt]));
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
