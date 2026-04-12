<?php

namespace App\Services\AI;

use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\Outbound;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification as DatabaseNotificationModel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeadstockAIService
{
    public function __construct(protected GeminiService $gemini)
    {
    }

    public function analyzeAfterOutbound(int $outboundId): ?int
    {
        $outbound = Outbound::query()->find($outboundId);
        if (!$outbound) {
            return null;
        }

        $materials = $this->buildWarehouseDataset();
        if (empty($materials)) {
            return null;
        }

        $analysis = $this->askGeminiDeadstockAnalysis($outboundId, $materials);
        $items = collect($analysis['items'] ?? [])->filter(fn ($row) => is_array($row))->values();

        if ($items->isEmpty()) {
            return null;
        }

        $normalizedItems = $items
            ->map(function (array $row) use ($materials): array {
                $materialId = (int) ($row['material_id'] ?? 0);
                $source = collect($materials)->firstWhere('material_id', $materialId) ?? [];

                return [
                    'material_id' => $materialId,
                    'material_name' => (string) ($row['material_name'] ?? ($source['material_name'] ?? ('Material #' . $materialId))),
                    'available_qty' => round((float) ($source['available_qty'] ?? ($row['available_qty'] ?? 0)), 2),
                    'satuan' => (string) ($source['satuan'] ?? ($row['satuan'] ?? 'unit')),
                    'last_used_days' => (int) ($source['last_used_days'] ?? ($row['last_used_days'] ?? 9999)),
                    'oldest_available_days' => (int) ($source['oldest_available_days'] ?? ($row['oldest_available_days'] ?? 0)),
                    'rekomendasi' => (string) ($row['rekomendasi'] ?? 'monitor'),
                    'prioritas' => (string) ($row['prioritas'] ?? 'sedang'),
                    'alasan' => trim((string) ($row['alasan'] ?? 'Analisis deadstock oleh Gemini berdasarkan pola konsumsi gudang.')),
                    'manager_decision' => 'pending',
                ];
            })
            ->filter(fn (array $row): bool => $row['material_id'] > 0 && $row['available_qty'] > 0)
            ->values()
            ->all();

        if (empty($normalizedItems)) {
            return null;
        }

        $title = 'AI: Screening Deadstock Pasca Outbound #' . $outboundId;
        $message = (string) ($analysis['ringkasan'] ?? 'Gemini mendeteksi material yang berpotensi deadstock. Mohon keputusan Manager Operasional.');

        $payload = [
            'outbound_id' => $outboundId,
            'generated_at' => now()->toDateTimeString(),
            'total_material_dianalisis' => count($materials),
            'deadstock_items' => $normalizedItems,
        ];

        $existing = Task::query()
            ->where('type', 'ai_deadstock_recommendation')
            ->where('related_type', 'outbound')
            ->where('related_id', $outboundId)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->update([
                'for_role' => 'Manager Operasional',
                'title' => $title,
                'message' => $message,
                'payload' => $payload,
            ]);

            $this->notifyManagerOperasional($title, $message, (int) $existing->id);

            return (int) $existing->id;
        }

        $task = Task::query()->create([
            'for_role' => 'Manager Operasional',
            'type' => 'ai_deadstock_recommendation',
            'title' => $title,
            'message' => $message,
            'payload' => $payload,
            'status' => 'pending',
            'related_type' => 'outbound',
            'related_id' => $outboundId,
        ]);

        $this->notifyManagerOperasional($title, $message, (int) $task->id);

        return (int) $task->id;
    }

    private function notifyManagerOperasional(string $title, string $message, int $taskId): void
    {
        $users = User::query()
            ->whereIn('role', ['Manager Operasional', 'Manager Operational', 'Manajer Operasional'])
            ->get(['id']);

        if ($users->isEmpty()) {
            return;
        }

        $databaseMessage = Notification::make()
            ->title($title)
            ->body($message)
            ->warning()
            ->getDatabaseMessage();

        foreach ($users as $user) {
            DatabaseNotificationModel::query()->create([
                'id' => (string) Str::uuid(),
                'type' => \Filament\Notifications\DatabaseNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => array_merge($databaseMessage, [
                    'deadstock_task_id' => $taskId,
                ]),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildWarehouseDataset(): array
    {
        $materials = Material::query()
            ->whereNull('deleted_at')
            ->get(['id', 'nama_material', 'satuan']);

        return $materials
            ->map(function (Material $material): ?array {
                $materialId = (int) $material->id;

                $availableQty = (float) MaterialPiece::query()
                    ->where('material_id', $materialId)
                    ->where('status', 'available')
                    ->sum('panjang');

                if ($availableQty <= 0) {
                    return null;
                }

                $availablePieceCount = (int) MaterialPiece::query()
                    ->where('material_id', $materialId)
                    ->where('status', 'available')
                    ->count();

                $oldestAvailableAt = MaterialPiece::query()
                    ->where('material_id', $materialId)
                    ->where('status', 'available')
                    ->orderBy('created_at')
                    ->value('created_at');

                $lastUsedAt = MaterialPiece::query()
                    ->where('material_id', $materialId)
                    ->whereIn('status', ['used', 'booked'])
                    ->whereNotNull('booked_at')
                    ->max('booked_at');

                $usage90d = (float) MaterialPiece::query()
                    ->where('material_id', $materialId)
                    ->whereIn('status', ['booked', 'used'])
                    ->where('booked_at', '>=', now()->subDays(90))
                    ->sum('panjang');

                $avgDailyUsage90d = $usage90d > 0
                    ? round($usage90d / 90, 2)
                    : 0.0;

                $deadPieceCount = (int) MaterialPiece::query()
                    ->where('material_id', $materialId)
                    ->where('status', 'available')
                    ->where('created_at', '<=', now()->subDays(120))
                    ->count();

                return [
                    'material_id' => $materialId,
                    'material_name' => (string) $material->nama_material,
                    'satuan' => (string) ($material->satuan ?: 'unit'),
                    'available_qty' => round($availableQty, 2),
                    'available_piece_count' => $availablePieceCount,
                    'oldest_available_days' => $oldestAvailableAt
                        ? Carbon::parse($oldestAvailableAt)->diffInDays(now())
                        : 0,
                    'last_used_days' => $lastUsedAt
                        ? Carbon::parse($lastUsedAt)->diffInDays(now())
                        : 9999,
                    'usage_90_days' => round($usage90d, 2),
                    'avg_daily_usage_90_days' => $avgDailyUsage90d,
                    'dead_piece_count_120_days' => $deadPieceCount,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $materials
     * @return array<string, mixed>
     */
    private function askGeminiDeadstockAnalysis(int $outboundId, array $materials): array
    {
        $materialsJson = json_encode($materials, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (!is_string($materialsJson) || $materialsJson === '') {
            throw new \RuntimeException('Gagal menyiapkan payload deadstock untuk Gemini.');
        }

        $prompt = <<<PROMPT
Kamu adalah AI inventory strategist gudang.

Konteks: setelah outbound #{$outboundId}, lakukan screening SEMUA material pada data ini untuk mendeteksi deadstock.

DATA MATERIAL:
{$materialsJson}

TUGAS:
1) Pelajari pola konsumsi material.
2) Temukan material yang berpotensi deadstock (mengendap lama/tidak bergerak).
3) Beri rekomendasi tindakan untuk Manager Operasional.

Pilihan rekomendasi (wajib pilih salah satu):
- buang
- jual_murah
- clearance
- return_distributor
- monitor

FORMAT JAWABAN WAJIB JSON (tanpa teks lain):
{
  "ringkasan": "ringkasan singkat",
  "items": [
    {
      "material_id": 1,
      "material_name": "Nama Material",
      "rekomendasi": "clearance",
      "prioritas": "tinggi",
      "alasan": "Alasan natural, singkat, operasional"
    }
  ]
}

ATURAN:
- Gunakan material_id yang ada di input.
- Alasan maksimal 2 kalimat.
- Fokus ke material yang butuh tindakan; boleh skip material sehat.
- `usage_90_days` adalah total pemakaian 90 hari (bukan rata-rata harian).
- Jika menyebut rata-rata harian, gunakan `avg_daily_usage_90_days`.
PROMPT;

        $responseRaw = (string) $this->gemini->ask($prompt);
        $responseClean = trim((string) preg_replace('/```json|```/i', '', $responseRaw));

        Log::info('Gemini deadstock analysis - raw response', [
            'outbound_id' => $outboundId,
            'response_length' => strlen($responseRaw),
            'response_preview' => mb_substr($responseClean, 0, 3000),
        ]);

        $decoded = json_decode($responseClean, true);

        if (!is_array($decoded) && preg_match('/\{(?:[^{}]|(?R))*\}/s', $responseClean, $match)) {
            $decoded = json_decode((string) $match[0], true);
        }

        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            throw new \RuntimeException('Response Gemini deadstock tidak valid.');
        }

        return $decoded;
    }
}
