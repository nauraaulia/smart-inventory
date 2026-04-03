<?php

namespace App\Services\AI;

use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

class BookingAIService
{
    protected GeminiService $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Entry point: dipanggil saat proyek baru masuk
     * $kebutuhan = [['material_id' => 1, 'qty' => 100], ...]
     */
    public function prosesKebutuhanProyek(int $projectId, array $kebutuhan): array
    {
        $kebutuhanDelta = $this->hitungDeltaKebutuhan($projectId, $kebutuhan);

        $hasilAnalisis = [];

        foreach ($kebutuhanDelta as $item) {
            $material = Material::find($item['material_id']);
            if (!$material) continue;

            $hasil = $this->analisaSatuItem($material, $item['qty'], $projectId);
            $hasilAnalisis[] = $hasil;
        }

        if (empty($hasilAnalisis)) {
            $this->sinkronTaskTanpaTambahan($projectId);
            return [];
        }

        // Kirim semua hasil ke Gemini untuk summary
        $summary = $this->mintaSummaryDariGemini($hasilAnalisis, $projectId);

        // Simpan/Update Task untuk Staff Adm (sinkron per project)
        $this->sinkronTaskUntukStaff($summary, $projectId, $hasilAnalisis);

        return $hasilAnalisis;
    }

    /**
     * Analisa 1 material: cukup / sebagian / kosong
     */
    private function analisaSatuItem(Material $material, float $dibutuhkan, int $projectId): array
    {
        $available = $material->available; // accessor dari model

        if ($available >= $dibutuhkan) {
            // ✅ Full booking bisa
            return [
                'material_id'   => $material->id,
                'nama_material' => $material->nama_material,
                'dibutuhkan'    => $dibutuhkan,
                'available'     => $available,
                'aksi'          => 'full_booking',
                'qty_booking'   => $dibutuhkan,
                'qty_pr'        => 0,
            ];

        } elseif ($available > 0) {
            // ⚠️ Sebagian booking, sisanya PR
            return [
                'material_id'   => $material->id,
                'nama_material' => $material->nama_material,
                'dibutuhkan'    => $dibutuhkan,
                'available'     => $available,
                'aksi'          => 'partial',
                'qty_booking'   => $available,
                'qty_pr'        => $dibutuhkan - $available,
            ];

        } else {
            // ❌ Kosong, full PR
            return [
                'material_id'   => $material->id,
                'nama_material' => $material->nama_material,
                'dibutuhkan'    => $dibutuhkan,
                'available'     => 0,
                'aksi'          => 'full_pr',
                'qty_booking'   => 0,
                'qty_pr'        => $dibutuhkan,
            ];
        }
    }

    /**
     * Minta Gemini bikin summary yang readable untuk Staff
     */
    private function mintaSummaryDariGemini(array $hasilAnalisis, int $projectId): string
    {
        $dataJson = json_encode($hasilAnalisis, JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
        Kamu adalah asisten sistem inventory gudang yang cerdas.
        
        Ada proyek baru (Project ID: {$projectId}) yang membutuhkan material.
        Berikut hasil analisis ketersediaan stok:
        
        {$dataJson}
        
        Berikan ringkasan yang JELAS dan SINGKAT dalam Bahasa Indonesia untuk Staff Administrasi Gudang.
        
        Format output HARUS berupa JSON seperti ini (jangan tambah teks lain di luar JSON):
        {
            "ringkasan": "Teks ringkasan singkat di sini",
            "rekomendasi": [
                {
                    "material": "nama material",
                    "aksi": "booking / buat PR / booking sebagian + PR",
                    "detail": "detail singkat"
                }
            ],
            "perlu_konfirmasi": true
        }
        PROMPT;

        $responseRaw = $this->gemini->ask($prompt);

        // Bersihkan kalau ada ```json ... ```
        $responseClean = preg_replace('/```json|```/', '', $responseRaw);

        return trim($responseClean);
    }

    /**
     * Buat Task di database untuk Staff Adm Gudang
     */
    private function sinkronTaskUntukStaff(string $summaryJson, int $projectId, array $detail): void
{
    $decoded = json_decode($summaryJson, true);
    $projectCode = Project::query()->whereKey($projectId)->value('kode_proyek') ?: "#{$projectId}";

    if (!is_array($decoded)) {
        $decoded = [
            'ringkasan' => 'AI summary tidak valid, gunakan hasil analisis detail.',
            'rekomendasi' => [],
            'perlu_konfirmasi' => true,
        ];
    }

    $decoded['rekomendasi'] = $this->normalisasiRekomendasi(
        $decoded['rekomendasi'] ?? [],
        $detail,
    );

    if (blank($decoded['ringkasan'] ?? null)) {
        $decoded['ringkasan'] = 'Silakan review rekomendasi booking/PR dari AI untuk project ini.';
    }

    $payload = [
        'project_id'   => $projectId,
        'project_code' => $projectCode,
        'detail'       => $detail,
        'ai_summary'   => $decoded,
        'approval_trail' => [
            'staff' => null,
            'manager' => null,
            'finance' => null,
        ],
        'last_synced_at' => now()->toDateTimeString(),
    ];

    $taskPending = Task::query()
        ->where('type', 'ai_booking_suggestion')
        ->where('related_type', 'project')
        ->where('related_id', $projectId)
        ->where('status', 'pending')
        ->orderByDesc('id')
        ->get();

    $taskTarget = $taskPending->first();

    // Antisipasi duplikasi pending lama: sisakan task terbaru saja.
    if ($taskPending->count() > 1) {
        $taskPending->slice(1)->each(function (Task $task) {
            $payload = $task->payload ?? [];
            data_set($payload, 'superseded', true);
            data_set($payload, 'superseded_at', now()->toDateTimeString());

            $task->update([
                'status' => 'done',
                'handled_at' => now(),
                'message' => 'Task ini digantikan oleh task AI terbaru untuk project yang sama.',
                'payload' => $payload,
            ]);
        });
    }

    if ($taskTarget) {
        $taskTarget->update([
            'title' => "AI: Konfirmasi Kebutuhan Material Proyek {$projectCode}",
            'message' => $decoded['ringkasan'] ?? 'AI menyarankan booking/PR untuk proyek baru',
            'payload' => $payload,
        ]);

        Log::info("AI task di-update untuk Project #{$projectId}");
        return;
    }

    Task::create([
        'for_role'     => 'Staff Administrasi Gudang',
        'type'         => 'ai_booking_suggestion',
        'title'        => "AI: Konfirmasi Kebutuhan Material Proyek {$projectCode}",
        'message'      => $decoded['ringkasan'] ?? 'AI menyarankan booking/PR untuk proyek baru',
        'payload'      => $payload,
        'status'       => 'pending',
        'related_id'   => $projectId,
        'related_type' => 'project',
    ]);

    Log::info("AI task dibuat untuk Project #{$projectId}");
}

    private function hitungDeltaKebutuhan(int $projectId, array $kebutuhan): array
    {
        return collect($kebutuhan)->map(function (array $item) use ($projectId) {
            $materialId = (int) ($item['material_id'] ?? 0);
            $qtyRequested = (float) ($item['qty'] ?? 0);

            if ($materialId <= 0 || $qtyRequested <= 0) {
                return null;
            }

            $qtyBooked = (float) MaterialPiece::query()
                ->where('booked_for_project_id', $projectId)
                ->where('material_id', $materialId)
                ->whereIn('status', ['booked', 'used'])
                ->sum('panjang');

            $qtyPr = (float) PurchaseRequest::query()
                ->where('project_id', $projectId)
                ->where('material_id', $materialId)
                ->whereIn('status', [
                    'draft',
                    'pending_manager',
                    'pending_finance',
                    'approved',
                    'ordered',
                    'received',
                ])
                ->sum('quantity');

            $qtyCommitted = $qtyBooked + $qtyPr;
            $qtyDelta = max(0, $qtyRequested - $qtyCommitted);

            if ($qtyDelta <= 0) {
                return null;
            }

            return [
                'material_id' => $materialId,
                'qty' => $qtyDelta,
            ];
        })->filter()->values()->all();
    }

    private function sinkronTaskTanpaTambahan(int $projectId): void
    {
        $projectCode = Project::query()->whereKey($projectId)->value('kode_proyek') ?: "#{$projectId}";

        $taskPending = Task::query()
            ->where('type', 'ai_booking_suggestion')
            ->where('related_type', 'project')
            ->where('related_id', $projectId)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if (!$taskPending) {
            return;
        }

        $payload = $taskPending->payload ?? [];
        data_set($payload, 'detail', []);
        data_set($payload, 'ai_summary.rekomendasi', []);
        data_set($payload, 'ai_summary.ringkasan', 'Tidak ada tambahan kebutuhan baru. Data kebutuhan sebelumnya sudah ter-cover booking/PR.');
        data_set($payload, 'last_synced_at', now()->toDateTimeString());

        $taskPending->update([
            'status' => 'done',
            'handled_at' => now(),
            'title' => "AI: Konfirmasi Kebutuhan Material Proyek {$projectCode}",
            'message' => 'Tidak ada tambahan kebutuhan baru. Task ditutup otomatis.',
            'payload' => $payload,
        ]);
    }

    private function normalisasiRekomendasi(array $rekomendasi, array $detail): array
    {
        $filtered = collect($rekomendasi)
            ->filter(fn ($item) => is_array($item) && !empty(array_filter($item, fn ($v) => filled($v))))
            ->map(function (array $item) {
                return [
                    'material' => (string) ($item['material'] ?? '-'),
                    'aksi' => (string) ($item['aksi'] ?? '-'),
                    'detail' => (string) ($item['detail'] ?? '-'),
                ];
            })
            ->values()
            ->all();

        if (!empty($filtered)) {
            return $filtered;
        }

        return collect($detail)->map(function (array $item) {
            $aksi = match ($item['aksi'] ?? null) {
                'full_booking' => 'booking',
                'partial' => 'booking sebagian + PR',
                'full_pr' => 'buat PR',
                default => 'review manual',
            };

            return [
                'material' => (string) ($item['nama_material'] ?? ('Material #' . ($item['material_id'] ?? '-'))),
                'aksi' => $aksi,
                'detail' => sprintf(
                    'Butuh %s, tersedia %s, booking %s, PR %s.',
                    $item['dibutuhkan'] ?? 0,
                    $item['available'] ?? 0,
                    $item['qty_booking'] ?? 0,
                    $item['qty_pr'] ?? 0,
                ),
            ];
        })->values()->all();
    }
}