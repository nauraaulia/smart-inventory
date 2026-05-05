<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\BookingAIService;
use App\Services\AI\GeminiService;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProsesKebutuhanProyekJob implements ShouldQueue
{
    use Queueable;

    /**
     * Jumlah kali job akan di-retry jika gagal.
     * Total 3 attempt: 1x awal + 2x retry.
     */
    public int $tries = 3;

    /**
     * Jeda retry singkat agar tetap hemat waktu dan token.
     */
    public array $backoff = [10, 30];

    /**
     * Timeout job execution (detik).
     */
    public int $timeout = 120;

    public function __construct(
        public int $projectId,
        public bool $sendNotifications = false,
    ) {}

    public function middleware(): array
    {
        return [new RateLimited('gemini-api')];
    }

    public function handle(): void
    {
        $project = Project::with('items.material')->find($this->projectId);

        if (! $project) {
            Log::warning("Project #{$this->projectId} tidak ditemukan");

            return;
        }

        // Format kebutuhan material dari project items
        $kebutuhan = $project->items->map(fn ($item) => [
            'material_id' => $item->material_id,
            'qty' => $item->jumlah_diminta,
        ])->filter(fn (array $item): bool => (int) ($item['material_id'] ?? 0) > 0 && (float) ($item['qty'] ?? 0) > 0)
            ->sortBy('material_id')
            ->values()
            ->toArray();

        $triggerSource = $this->sendNotifications ? 'manager_project_submit' : 'system_sync';

        if (empty($kebutuhan)) {
            if ($this->sendNotifications) {
                $this->notifyGeminiProcessing($project);
            }
            $aiService = new BookingAIService(new GeminiService);
            $aiService->prosesKebutuhanProyek($this->projectId, [], false, $triggerSource);
            if ($this->sendNotifications) {
                $this->notifyGeminiFinished($project, false);
            }

            return;
        }

        $dedupeKey = 'ai_booking_project_hash_'.$this->projectId;
        $kebutuhanHash = sha1((string) json_encode([
            'kebutuhan' => $kebutuhan,
            'catatan_teknis' => trim((string) ($project->catatan_teknis ?? '')),
        ], JSON_UNESCAPED_UNICODE));
        $lastHash = (string) Cache::get($dedupeKey, '');

        if ($this->sendNotifications) {
            $this->notifyGeminiProcessing($project);
        }

        if ($lastHash === $kebutuhanHash) {
            Log::info("AI skip duplicate kebutuhan Project #{$this->projectId}", [
                'project_id' => $this->projectId,
                'kebutuhan_hash' => $kebutuhanHash,
            ]);
            if ($this->sendNotifications) {
                $this->notifyGeminiFinished($project, true);
            }

            return;
        }

        try {
            // Jalankan AI
            $aiService = new BookingAIService(new GeminiService);
            // Untuk error Gemini yang sifatnya sementara, lempar ke queue agar retry dulu.
            $aiService->prosesKebutuhanProyek($this->projectId, $kebutuhan, true, $triggerSource);

            // Simpan hash hanya jika proses AI sukses, agar retry tidak ke-skip saat gagal.
            $dedupeMinutes = max(1, (int) env('AI_BOOKING_DEDUPE_MINUTES', 180));
            Cache::put($dedupeKey, $kebutuhanHash, now()->addMinutes($dedupeMinutes));
            if ($this->sendNotifications) {
                $this->notifyGeminiFinished($project, false);
            }
        } catch (Throwable $e) {
            Cache::forget($dedupeKey);
            throw $e;
        }

        Log::info("AI selesai proses Project #{$this->projectId}");
    }

    public function failed(Throwable $e): void
    {
        $fallbackRecovered = false;
        $errorMessage = trim((string) $e->getMessage());
        $isGeminiQuotaOrCooldown = $this->isGeminiQuotaOrCooldown($errorMessage);

        try {
            $project = Project::with('items.material')->find($this->projectId);
            if ($project && ! $isGeminiQuotaOrCooldown) {
                $kebutuhan = $project->items->map(fn ($item) => [
                    'material_id' => $item->material_id,
                    'qty' => $item->jumlah_diminta,
                ])->filter(fn (array $item): bool => (int) ($item['material_id'] ?? 0) > 0 && (float) ($item['qty'] ?? 0) > 0)
                    ->sortBy('material_id')
                    ->values()
                    ->toArray();

                $aiService = new BookingAIService(new GeminiService);
                // Setelah retry habis, pakai fallback lokal agar task tetap berisi rekomendasi.
                $aiService->prosesKebutuhanProyek($this->projectId, $kebutuhan, false, 'system_sync');

                $latestTask = Task::query()
                    ->where('type', 'ai_booking_suggestion')
                    ->where('related_type', 'project')
                    ->where('related_id', $this->projectId)
                    ->where('status', 'pending')
                    ->latest('id')
                    ->first();

                if ($latestTask) {
                    $detail = data_get($latestTask->payload, 'detail', []);
                    $fallbackRecovered = is_array($detail) && ! empty($detail);
                }
            }
        } catch (Throwable $fallbackError) {
            Log::error('Fallback darurat setelah retry gagal tidak berhasil', [
                'project_id' => $this->projectId,
                'error' => $fallbackError->getMessage(),
            ]);
        }

        $task = Task::query()
            ->where('type', 'ai_booking_suggestion')
            ->where('related_type', 'project')
            ->where('related_id', $this->projectId)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($task) {
            $payload = is_array($task->payload) ? $task->payload : [];
            data_set($payload, 'ai_error.message', $errorMessage);
            data_set($payload, 'ai_error.failed_at', now()->toDateTimeString());
            data_set($payload, 'ai_error.retries', (int) $this->tries);
            data_set($payload, 'last_synced_at', now()->toDateTimeString());

            $updateData = ['payload' => $payload];
            if (! $fallbackRecovered) {
                $updateData['message'] = $isGeminiQuotaOrCooldown
                    ? 'Gemini terkena quota/rate limit setelah '.(int) $this->tries.' percobaan. Rekomendasi fallback PHP tidak dijalankan agar hasil tetap menunggu Gemini.'
                    : 'AI gagal setelah '.(int) $this->tries.' percobaan: '.$errorMessage;
            }

            $task->update($updateData);
        }

        Log::error('AI job gagal setelah retry maksimal', [
            'project_id' => $this->projectId,
            'tries' => $this->tries,
            'error' => $errorMessage,
            'fallback_skipped' => $isGeminiQuotaOrCooldown,
        ]);

        $project = Project::query()->find($this->projectId, ['id', 'kode_proyek']);
        if ($project && $this->sendNotifications) {
            $this->notifyGeminiFailed($project, $errorMessage, $isGeminiQuotaOrCooldown);
        }
    }

    private function isGeminiQuotaOrCooldown(string $message): bool
    {
        $text = mb_strtolower($message);

        return str_contains($text, 'quota')
            || str_contains($text, 'rate limit')
            || str_contains($text, 'rate limited')
            || str_contains($text, 'cooldown aktif')
            || str_contains($text, 'resource_exhausted');
    }

    private function notifyGeminiProcessing(Project $project): void
    {
        $projectCode = (string) ($project->kode_proyek ?: ('#'.$project->id));
        $key = 'gemini_processing_notif_'.(int) $project->id;

        if (! Cache::add($key, 1, now()->addSeconds(15))) {
            return;
        }

        $notification = Notification::make()
            ->title("Gemini sedang memproses {$projectCode}")
            ->body('Mohon tunggu, rekomendasi booking/PR sedang dibuat.')
            ->info();

        $this->sendToRelevantUsers($notification);
    }

    private function notifyGeminiFinished(Project $project, bool $unchanged = false): void
    {
        $projectCode = (string) ($project->kode_proyek ?: ('#'.$project->id));

        $notification = Notification::make()
            ->title("Rekomendasi {$projectCode} sudah siap")
            ->body($unchanged
                ? 'Tidak ada perubahan kebutuhan dari sinkron terakhir. Data rekomendasi tetap up-to-date.'
                : 'Silakan review rekomendasi terbaru dari Gemini.')
            ->success();

        $this->sendToRelevantUsers($notification);
    }

    private function notifyGeminiFailed(Project $project, string $errorMessage, bool $fallbackSkipped = false): void
    {
        $projectCode = (string) ($project->kode_proyek ?: ('#'.$project->id));
        $body = $fallbackSkipped
            ? 'Gemini terkena quota/rate limit. Fallback PHP tidak dijalankan agar rekomendasi menunggu Gemini.'
            : 'Gemini gagal memproses rekomendasi. Sistem fallback hanya dijalankan untuk error non-quota.';
        if ($errorMessage !== '') {
            $body .= ' Detail: '.mb_substr($errorMessage, 0, 180);
        }

        $notification = Notification::make()
            ->title("Gemini gagal di {$projectCode}")
            ->body($body)
            ->warning();

        $this->sendToRelevantUsers($notification);
    }

    private function sendToRelevantUsers(Notification $notification): void
    {
        $recipients = User::query()
            ->whereIn('role', [
                'Manager Teknik',
                'Manajer Teknik',
                'Staff Administrasi Gudang',
                'Staff Adm Gudang',
                'Staff Gudang',
                'Admin Gudang',
                'Administrator Gudang',
            ])
            ->get();

        foreach ($recipients as $recipient) {
            $recipient->notifyNow($notification->toDatabase());
            DatabaseNotificationsSent::dispatch($recipient);
        }
    }
}
