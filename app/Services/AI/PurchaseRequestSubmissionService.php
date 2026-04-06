<?php

namespace App\Services\AI;

use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\UserLog;
use Illuminate\Support\Facades\DB;

class PurchaseRequestSubmissionService
{
    public function submitDraftsForProject(int $projectId, User $actor): array
    {
        return DB::transaction(function () use ($projectId, $actor) {
            $drafts = PurchaseRequest::query()
                ->where('project_id', $projectId)
                ->whereIn('status', ['draft', 'rejected'])
                ->whereNotNull('draft_approved_at')
                ->get();

            if ($drafts->isEmpty()) {
                return [
                    'submitted_count' => 0,
                    'task_id' => null,
                    'project_code' => $this->projectCode($projectId),
                ];
            }

            $ids = $drafts->pluck('id')->values()->all();

            PurchaseRequest::query()
                ->whereIn('id', $ids)
                ->update([
                    'status' => 'pending_manager',
                    'submitted_at' => now(),
                    'draft_approved_by' => DB::raw('COALESCE(draft_approved_by, ' . (int) $actor->id . ')'),
                    'draft_approved_at' => DB::raw('COALESCE(draft_approved_at, NOW())'),
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ]);

            $projectCode = $this->projectCode($projectId);

            $existingTasks = Task::query()
                ->where('type', 'pr_manager_approval')
                ->where('status', 'pending')
                ->where('related_type', 'project')
                ->where('related_id', $projectId)
                ->orderByDesc('id')
                ->get();

            $existingTask = $existingTasks->first();

            if ($existingTasks->count() > 1) {
                $existingTasks->slice(1)->each(function (Task $task): void {
                    $payload = $task->payload ?? [];
                    data_set($payload, 'superseded', true);
                    data_set($payload, 'superseded_at', now()->toDateTimeString());

                    $task->update([
                        'status' => 'done',
                        'handled_at' => now(),
                        'message' => 'Task approval manager ini digabung ke task terbaru.',
                        'payload' => $payload,
                    ]);
                });
            }

            if ($existingTask) {
                $payload = $existingTask->payload ?? [];
                $existingPrIds = collect(data_get($payload, 'pr_ids', []))->map(fn ($id) => (int) $id);
                $mergedPrIds = $existingPrIds->merge($ids)->unique()->values()->all();

                data_set($payload, 'project_id', $projectId);
                data_set($payload, 'project_code', $projectCode);
                data_set($payload, 'pr_ids', $mergedPrIds);
                data_set($payload, 'submitted_by_staff', [
                    'user_id' => $actor->id,
                    'at' => now()->toDateTimeString(),
                ]);

                $existingTask->update([
                    'title' => "Approval PR Project {$projectCode}",
                    'message' => 'Terdapat PR draft yang menunggu approval Manager Operasional.',
                    'payload' => $payload,
                ]);

                $this->logUserAction(
                    actor: $actor,
                    action: 'Submit',
                    module: 'Draft PR',
                    description: "Submit draft PR ke Manager untuk project {$projectCode}. Jumlah PR: " . count($ids),
                );

                return [
                    'submitted_count' => count($ids),
                    'task_id' => $existingTask->id,
                    'project_code' => $projectCode,
                ];
            }

            $task = Task::create([
                'for_role' => 'Manager Operasional',
                'type' => 'pr_manager_approval',
                'title' => "Approval PR Project {$projectCode}",
                'message' => 'Terdapat PR draft yang menunggu approval Manager Operasional.',
                'payload' => [
                    'project_id' => $projectId,
                    'project_code' => $projectCode,
                    'pr_ids' => $ids,
                    'submitted_by_staff' => [
                        'user_id' => $actor->id,
                        'at' => now()->toDateTimeString(),
                    ],
                ],
                'status' => 'pending',
                'related_id' => $projectId,
                'related_type' => 'project',
            ]);

            $this->logUserAction(
                actor: $actor,
                action: 'Submit',
                module: 'Draft PR',
                description: "Submit draft PR ke Manager untuk project {$projectCode}. Jumlah PR: " . count($ids),
            );

            return [
                'submitted_count' => count($ids),
                'task_id' => $task->id,
                'project_code' => $projectCode,
            ];
        });
    }

    public function cancelSubmittedPr(PurchaseRequest $record, User $actor): array
    {
        return DB::transaction(function () use ($record, $actor) {
            /** @var PurchaseRequest|null $pr */
            $pr = PurchaseRequest::query()->lockForUpdate()->find($record->id);

            if (!$pr || $pr->status !== 'pending_manager') {
                return [
                    'cancelled' => false,
                    'project_code' => $this->projectCode((int) ($record->project_id ?? 0)),
                ];
            }

            $projectId = (int) $pr->project_id;
            $projectCode = $this->projectCode($projectId);

            $pr->update([
                'status' => 'draft',
                'submitted_at' => null,
                'approved_by_manager' => null,
                'approved_by_manager_at' => null,
                'approved_by_finance' => null,
                'approved_by_finance_at' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            $this->syncPendingManagerTaskAfterCancellation(
                projectId: $projectId,
                cancelledPrId: (int) $pr->id,
                actor: $actor,
                projectCode: $projectCode,
            );

            $this->logUserAction(
                actor: $actor,
                action: 'Cancel Submit',
                module: 'Draft PR',
                description: "Membatalkan pengajuan PR ID {$pr->id} untuk project {$projectCode} sebelum approval Manager.",
            );

            return [
                'cancelled' => true,
                'project_code' => $projectCode,
            ];
        });
    }

    public function cancelSubmissionToFinanceByManager(PurchaseRequest $record, User $actor): array
    {
        return DB::transaction(function () use ($record, $actor) {
            /** @var PurchaseRequest|null $pr */
            $pr = PurchaseRequest::query()->lockForUpdate()->find($record->id);

            if (!$pr || $pr->status !== 'pending_finance') {
                return [
                    'cancelled' => false,
                    'project_code' => $this->projectCode((int) ($record->project_id ?? 0)),
                ];
            }

            $projectCode = $this->projectCode((int) ($pr->project_id ?? 0));

            $pr->update([
                'status' => 'pending_manager',
                'approved_by_manager' => null,
                'approved_by_manager_at' => null,
                'approved_by_finance' => null,
                'approved_by_finance_at' => null,
            ]);

            $this->logUserAction(
                actor: $actor,
                action: 'Cancel Submit',
                module: 'Purchase Request',
                description: "Manager membatalkan pengajuan PR ID {$pr->id} ke Finance. Status kembali ke pending_manager.",
            );

            return [
                'cancelled' => true,
                'project_code' => $projectCode,
            ];
        });
    }

    private function syncPendingManagerTaskAfterCancellation(int $projectId, int $cancelledPrId, User $actor, string $projectCode): void
    {
        $pendingTasks = Task::query()
            ->where('type', 'pr_manager_approval')
            ->where('status', 'pending')
            ->where('related_type', 'project')
            ->where('related_id', $projectId)
            ->orderByDesc('id')
            ->get();

        $task = $pendingTasks->first();

        if (!$task) {
            return;
        }

        if ($pendingTasks->count() > 1) {
            $pendingTasks->slice(1)->each(function (Task $redundantTask): void {
                $payload = $redundantTask->payload ?? [];
                data_set($payload, 'superseded', true);
                data_set($payload, 'superseded_at', now()->toDateTimeString());

                $redundantTask->update([
                    'status' => 'done',
                    'handled_at' => now(),
                    'message' => 'Task approval manager ini digabung ke task terbaru.',
                    'payload' => $payload,
                ]);
            });
        }

        $payload = $task->payload ?? [];
        $remainingPrIds = collect(data_get($payload, 'pr_ids', []))
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === $cancelledPrId)
            ->unique()
            ->values()
            ->all();

        data_set($payload, 'project_id', $projectId);
        data_set($payload, 'project_code', $projectCode);
        data_set($payload, 'pr_ids', $remainingPrIds);
        data_set($payload, 'last_cancellation', [
            'pr_id' => $cancelledPrId,
            'by_user_id' => $actor->id,
            'at' => now()->toDateTimeString(),
        ]);

        if (empty($remainingPrIds)) {
            $task->update([
                'status' => 'done',
                'handled_at' => now(),
                'message' => 'Semua PR pada task ini dibatalkan sebelum approval Manager.',
                'payload' => $payload,
            ]);

            return;
        }

        $task->update([
            'title' => "Approval PR Project {$projectCode}",
            'message' => 'Terdapat PR draft yang menunggu approval Manager Operasional.',
            'payload' => $payload,
        ]);
    }

    private function projectCode(int $projectId): string
    {
        return Project::query()->whereKey($projectId)->value('kode_proyek') ?: "#{$projectId}";
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
