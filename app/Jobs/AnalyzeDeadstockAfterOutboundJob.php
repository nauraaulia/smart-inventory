<?php

namespace App\Jobs;

use App\Services\AI\DeadstockAIService;
use App\Services\AI\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AnalyzeDeadstockAfterOutboundJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $outboundId)
    {
    }

    public function handle(): void
    {
        try {
            $service = new DeadstockAIService(new GeminiService());
            $taskId = $service->analyzeAfterOutbound($this->outboundId);

            Log::info('AI deadstock screening selesai', [
                'outbound_id' => $this->outboundId,
                'task_id' => $taskId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI deadstock screening gagal', [
                'outbound_id' => $this->outboundId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
