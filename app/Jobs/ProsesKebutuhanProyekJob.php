<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\AI\BookingAIService;
use App\Services\AI\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProsesKebutuhanProyekJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $projectId
    ) {}

    public function handle(): void
    {
        $project = Project::with('items.material')->find($this->projectId);

        if (!$project) {
            Log::warning("Project #{$this->projectId} tidak ditemukan");
            return;
        }

        // Format kebutuhan material dari project items
        $kebutuhan = $project->items->map(fn($item) => [
            'material_id' => $item->material_id,
            'qty'         => $item->jumlah_diminta,
        ])->toArray();

        if (empty($kebutuhan)) {
            Log::info("Project #{$this->projectId} tidak punya kebutuhan material");
            return;
        }

        // Jalankan AI
        $aiService = new BookingAIService(new GeminiService());
        $aiService->prosesKebutuhanProyek($this->projectId, $kebutuhan);

        Log::info("AI selesai proses Project #{$this->projectId}");
    }
}