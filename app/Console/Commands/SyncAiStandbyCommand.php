<?php

namespace App\Console\Commands;

use App\Models\Outbound;
use App\Models\Project;
use App\Services\AI\BookingAIService;
use App\Services\AI\DeadstockAIService;
use App\Services\AI\GeminiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncAiStandbyCommand extends Command
{
    protected $signature = 'ai:sync-standby {--minutes=180 : Rentang menit data yang dipindai} {--limit=40 : Batas item per jenis sinkronisasi}';

    protected $description = 'Sinkronisasi AI Booking dan Deadstock secara backend agar tetap jalan walau tidak ada user aktif.';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $from = now()->subMinutes($minutes);

        $this->info("AI standby sync dimulai. window={$minutes} menit, limit={$limit}");

        $bookingService = new BookingAIService(new GeminiService());
        $deadstockService = new DeadstockAIService(new GeminiService());

        $projectSynced = 0;
        $projectErrors = 0;

        $projectIds = Project::query()
            ->where('updated_at', '>=', $from)
            ->whereHas('items')
            ->whereNotIn('status', ['Complete', 'completed', 'complete'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($projectIds as $projectId) {
            try {
                $project = Project::query()
                    ->with('items:project_id,material_id,jumlah_diminta')
                    ->find((int) $projectId);

                if (!$project) {
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
                    continue;
                }

                $bookingService->prosesKebutuhanProyek((int) $projectId, $kebutuhan);
                $projectSynced++;
            } catch (\Throwable $e) {
                $projectErrors++;

                Log::warning('AI standby sync project gagal', [
                    'project_id' => (int) $projectId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $deadstockSynced = 0;
        $deadstockErrors = 0;

        $outboundIds = Outbound::query()
            ->where('status', 'taken')
            ->whereNotNull('taken_at')
            ->where('taken_at', '>=', $from)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('tasks')
                    ->whereColumn('tasks.related_id', 'outbounds.id')
                    ->where('tasks.related_type', 'outbound')
                    ->where('tasks.type', 'ai_deadstock_recommendation');
            })
            ->orderByDesc('taken_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($outboundIds as $outboundId) {
            try {
                $taskId = $deadstockService->analyzeAfterOutbound((int) $outboundId);

                if ($taskId !== null) {
                    $deadstockSynced++;
                }
            } catch (\Throwable $e) {
                $deadstockErrors++;

                Log::warning('AI standby sync deadstock gagal', [
                    'outbound_id' => (int) $outboundId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Selesai. project_synced={$projectSynced}, project_errors={$projectErrors}, deadstock_synced={$deadstockSynced}, deadstock_errors={$deadstockErrors}");

        return self::SUCCESS;
    }
}
