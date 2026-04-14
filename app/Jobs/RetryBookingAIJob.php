<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\AI\BookingAIService;
use App\Services\AI\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

        if (empty($this->kebutuhan)) {
            Log::info('RetryBookingAIJob skip: kebutuhan kosong', [
                'project_id' => $this->projectId,
            ]);

            return;
        }

        $aiService = new BookingAIService(new GeminiService());

        // Retry hanya 1x. Jika masih gagal transient, service akan fallback lokal dan update task.
        $result = $aiService->prosesKebutuhanProyek($this->projectId, $this->kebutuhan, false);

        Log::info('RetryBookingAIJob selesai', [
            'project_id' => $this->projectId,
            'result_count' => count($result),
        ]);
    }
}
