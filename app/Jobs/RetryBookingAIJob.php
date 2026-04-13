<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\Task;
use App\Services\AI\BookingAIService;
use App\Services\AI\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RetryBookingAIJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<int, array{material_id:int,qty:float,qty_original?:float}>  $kebutuhan
     */
    public function __construct(
        public int $projectId,
        public array $kebutuhan,
    ) {
        $this->onConnection('database');
    }

    public function handle(): void
    {
        $project = Project::query()->find($this->projectId);

        if (!$project) {
            Log::warning('RetryBookingAIJob skip: project tidak ditemukan', [
                'project_id' => $this->projectId,
            ]);

            return;
        }

        $kebutuhan = $this->kebutuhan;

        if (empty($kebutuhan)) {
            $cachedPayload = Cache::get('ai_booking_retry_payload_' . $this->projectId, []);
            $kebutuhan = is_array($cachedPayload) ? (array) ($cachedPayload['kebutuhan'] ?? []) : [];
        }

        if (empty($kebutuhan)) {
            Log::info('RetryBookingAIJob skip: kebutuhan kosong', [
                'project_id' => $this->projectId,
            ]);

            return;
        }

        $aiService = new BookingAIService(new GeminiService());

        // Retry hanya 1x. Jika masih gagal transient, service akan fallback lokal dan update task.
        $result = $aiService->prosesKebutuhanProyek($this->projectId, $kebutuhan, false);

        $pendingTask = Task::query()
            ->where('type', 'ai_booking_suggestion')
            ->where('related_type', 'project')
            ->where('related_id', $this->projectId)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        $source = (string) data_get($pendingTask?->payload, 'ai_instruction.sumber', 'unknown');
        $usedFallback = str_starts_with($source, 'fallback_');

        Log::info('RetryBookingAIJob selesai', [
            'project_id' => $this->projectId,
            'result_count' => count($result),
            'source' => $source,
            'fallback_permanen' => $usedFallback,
        ]);

        Cache::forget('ai_booking_retry_payload_' . $this->projectId);
        Cache::forget('ai_booking_retry_scheduled_' . $this->projectId);
    }
}
