<?php

namespace App\Services\AI;

use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Models\User;
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
                    'draft_approved_by' => null,
                    'draft_approved_at' => null,
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

            return [
                'submitted_count' => count($ids),
                'task_id' => $task->id,
                'project_code' => $projectCode,
            ];
        });
    }

    private function projectCode(int $projectId): string
    {
        return Project::query()->whereKey($projectId)->value('kode_proyek') ?: "#{$projectId}";
    }
}
