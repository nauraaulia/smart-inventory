<?php

namespace App\Console\Commands;

use App\Jobs\RetryBookingAIJob;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RequeueStuckBookingAiCommand extends Command
{
    protected $signature = 'ai:requeue-stuck-booking {--minutes=720 : Rentang menit task yang dipindai} {--limit=40 : Batas task yang direqueue}';

    protected $description = 'Requeue task AI booking yang gagal karena quota/rate limit agar dicoba ulang otomatis.';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $retryMinutes = max(1, (int) env('AI_BOOKING_QUOTA_RETRY_MINUTES', 5));
        $from = now()->subMinutes($minutes);

        $tasks = Task::query()
            ->where('type', 'ai_booking_suggestion')
            ->where('status', 'pending')
            ->where('updated_at', '>=', $from)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $scanned = $tasks->count();
        $requeued = 0;
        $skipped = 0;

        foreach ($tasks as $task) {
            if (! $this->isQuotaTask($task)) {
                $skipped++;
                continue;
            }

            if ($this->hasRecentRetrySchedule($task, $retryMinutes)) {
                $skipped++;
                continue;
            }

            $projectId = (int) ($task->related_id ?: data_get($task->payload, 'project_id', 0));
            if ($projectId <= 0) {
                $skipped++;
                continue;
            }

            $project = Project::query()
                ->with('items:project_id,material_id,jumlah_diminta')
                ->find($projectId);

            if (! $project) {
                $skipped++;
                continue;
            }

            $kebutuhan = $project->items
                ->map(fn ($item): array => [
                    'material_id' => (int) $item->material_id,
                    'qty' => (float) $item->jumlah_diminta,
                ])
                ->filter(fn (array $row): bool => $row['material_id'] > 0 && $row['qty'] > 0)
                ->values()
                ->all();

            if ($kebutuhan === []) {
                $skipped++;
                continue;
            }

            $retryKey = 'ai_booking_quota_retry_'.$projectId;
            if (! Cache::add($retryKey, now()->toDateTimeString(), now()->addMinutes($retryMinutes))) {
                $skipped++;
                continue;
            }

            dispatch((new RetryBookingAIJob($projectId, $kebutuhan))
                ->delay(now()->addMinutes($retryMinutes)));

            $payload = is_array($task->payload) ? $task->payload : [];
            data_set($payload, 'ai_error.retry_after_minutes', $retryMinutes);
            data_set($payload, 'ai_error.retry_scheduled_at', now()->toDateTimeString());
            data_set($payload, 'last_synced_at', now()->toDateTimeString());

            $task->update([
                'message' => 'Gemini terkena quota/rate limit. Sistem akan coba ulang dalam '.$retryMinutes.' menit. Jika masih gagal, fallback sistem dijalankan.',
                'payload' => $payload,
            ]);

            $requeued++;
        }

        $this->info("Selesai. scanned={$scanned}, requeued={$requeued}, skipped={$skipped}");

        return self::SUCCESS;
    }

    private function isQuotaTask(Task $task): bool
    {
        $message = trim((string) ($task->message ?? ''));
        $payloadMessage = trim((string) data_get($task->payload, 'ai_error.message', ''));
        $text = mb_strtolower($message.' '.$payloadMessage);

        return str_contains($text, 'quota')
            || str_contains($text, 'rate limit')
            || str_contains($text, 'rate limited')
            || str_contains($text, 'resource_exhausted')
            || str_contains($text, 'cooldown');
    }

    private function hasRecentRetrySchedule(Task $task, int $retryMinutes): bool
    {
        $scheduledAt = (string) data_get($task->payload, 'ai_error.retry_scheduled_at', '');

        if ($scheduledAt === '') {
            return false;
        }

        try {
            $scheduledTime = Carbon::parse($scheduledAt);
        } catch (\Throwable $e) {
            Log::warning('Retry schedule timestamp invalid', [
                'task_id' => $task->id,
                'value' => $scheduledAt,
            ]);

            return false;
        }

        return $scheduledTime->diffInMinutes(now()) < max(1, $retryMinutes);
    }
}
