<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Models\UserLog;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckProjectMaterialReadiness extends Command
{
    protected $signature = 'project:check-material-readiness';

    protected $description = 'Cek kesiapan material project H-0 dan kirim notifikasi jika stok belum mencukupi.';

    public function handle(): int
    {
        $today = now()->toDateString();
        $notifiedProjects = 0;

        $projects = Project::query()
            ->whereDate('tanggal_mulai', $today)
            ->with(['items:id,project_id,material_id,jumlah_diminta'])
            ->get(['id', 'kode_proyek', 'nama_proyek', 'tanggal_mulai']);

        if ($projects->isEmpty()) {
            $this->info('Tidak ada project dengan tanggal_mulai hari ini.');

            return self::SUCCESS;
        }

        foreach ($projects as $project) {
            $requiredByMaterial = $project->items
                ->groupBy('material_id')
                ->map(fn ($rows): float => (float) $rows->sum('jumlah_diminta'))
                ->filter(fn (float $qty, $materialId): bool => (int) $materialId > 0 && $qty > 0)
                ->all();

            if ($requiredByMaterial === []) {
                continue;
            }

            $materials = Material::query()
                ->whereIn('id', array_keys($requiredByMaterial))
                ->get(['id', 'nama_material', 'stok'])
                ->keyBy('id');

            $shortageLines = [];

            foreach ($requiredByMaterial as $materialId => $requiredQty) {
                $material = $materials->get((int) $materialId);
                $actualStock = (float) ($material?->stok ?? 0);

                if ($actualStock >= $requiredQty) {
                    continue;
                }

                $shortage = $requiredQty - $actualStock;
                $materialName = (string) ($material?->nama_material ?? ('Material #' . (int) $materialId));
                $shortageLines[] = $materialName . ' (kurang ' . $this->formatNumber($shortage) . ')';
            }

            if ($shortageLines === []) {
                continue;
            }

            $recipients = User::query()
                ->whereIn('id', $this->resolveRecipientIds($project->id))
                ->get(['id', 'name', 'role']);

            if ($recipients->isEmpty()) {
                continue;
            }

            $title = 'Material belum siap untuk project ' . $project->kode_proyek . ' - ' . $project->nama_proyek;
            $body = 'Project dijadwalkan mulai hari ini, tetapi stok belum mencukupi. Material kurang: ' . implode(', ', $shortageLines);

            $notification = Notification::make()
                ->title($title)
                ->body($body)
                ->warning();

            foreach ($recipients as $recipient) {
                $recipient->notifyNow($notification->toDatabase());
                DatabaseNotificationsSent::dispatch($recipient);
            }

            $notifiedProjects++;
            $this->line('Notifikasi dikirim untuk project ' . $project->kode_proyek . ' ke ' . $recipients->count() . ' user.');
        }

        $this->info('Selesai. Total project yang terkirim notifikasi: ' . $notifiedProjects);

        return self::SUCCESS;
    }

    /**
     * @return array<int>
     */
    private function resolveRecipientIds(int $projectId): array
    {
        $recipientIds = [];

        $gudangIds = User::query()
            ->whereIn('role', ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'])
            ->pluck('id')
            ->all();

        $recipientIds = array_merge($recipientIds, $gudangIds);

        $ownerId = $this->resolveProjectOwnerId($projectId);

        if ($ownerId !== null) {
            $recipientIds[] = $ownerId;
        }

        return array_values(array_unique(array_map('intval', $recipientIds)));
    }

    private function resolveProjectOwnerId(int $projectId): ?int
    {
        if (Schema::hasColumn('projects', 'created_by')) {
            $ownerId = Project::query()->whereKey($projectId)->value('created_by');

            if (filled($ownerId)) {
                return (int) $ownerId;
            }
        }

        $project = Project::query()->find($projectId, ['id', 'kode_proyek']);

        if (!$project) {
            return null;
        }

        $ownerLog = UserLog::query()
            ->where('module', 'Project')
            ->where('action', 'Create')
            ->where('description', 'like', '%' . $project->kode_proyek . '%')
            ->orderBy('id')
            ->first(['user_id']);

        return $ownerLog?->user_id ? (int) $ownerLog->user_id : null;
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
