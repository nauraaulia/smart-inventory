<?php

namespace App\Services\AI;

use App\Models\Material;
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
        $hasilAnalisis = [];

        foreach ($kebutuhan as $item) {
            $material = Material::find($item['material_id']);
            if (!$material) continue;

            $hasil = $this->analisaSatuItem($material, $item['qty'], $projectId);
            $hasilAnalisis[] = $hasil;
        }

        // Kirim semua hasil ke Gemini untuk summary
        $summary = $this->mintaSummaryDariGemini($hasilAnalisis, $projectId);

        // Simpan sebagai Task untuk Staff Adm
        $this->buatTaskUntukStaff($summary, $projectId, $hasilAnalisis);

        return $hasilAnalisis;
    }

    /**
     * Analisa 1 material: cukup / sebagian / kosong
     */
    private function analisaSatuItem(Material $material, int $dibutuhkan, int $projectId): array
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
    private function buatTaskUntukStaff(string $summaryJson, int $projectId, array $detail): void
{
    $decoded = json_decode($summaryJson, true);

    Task::create([
        'for_role'     => 'Staff Adm Gudang',
        'type'         => 'ai_booking_suggestion',
        'title'        => "AI: Konfirmasi Kebutuhan Material Proyek #{$projectId}",
        'message'      => $decoded['ringkasan'] ?? 'AI menyarankan booking/PR untuk proyek baru',
        'payload'      => [
            'project_id'   => $projectId,
            'detail'       => $detail,
            'ai_summary'   => $decoded,
        ],
        'status'       => 'pending',
        'related_id'   => $projectId,
        'related_type' => 'project',
    ]);
}
}