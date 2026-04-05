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
        $project = Project::query()->find($projectId);
        $kebutuhanDelta = $this->hitungDeltaKebutuhan($projectId, $kebutuhan);

        $instruksiAi = $this->parseInstruksiOperasionalDariCatatan(
            catatanTeknis: (string) ($project?->catatan_teknis ?? ''),
            projectId: $projectId,
        );

        $tanpaPotonganDariPrompt = (bool) ($instruksiAi['tanpa_potongan'] ?? false);

        $hasilAnalisis = [];

        foreach ($kebutuhanDelta as $item) {
            $material = Material::find($item['material_id']);
            if (!$material) continue;

            $hasil = $this->analisaSatuItem(
                material: $material,
                dibutuhkan: $item['qty'],
                projectId: $projectId,
                tanpaPotongan: $tanpaPotonganDariPrompt,
                alasanInstruksi: (string) ($instruksiAi['alasan'] ?? ''),
            );

            $hasil['kebutuhan_asli'] = (float) ($item['qty_original'] ?? $item['qty']);
            $hasil['kebutuhan_setelah_buffer'] = (float) ($item['qty'] ?? 0);
            $hasil['kebutuhan_belum_tercover'] = (float) ($item['qty'] ?? 0);

            $hasilAnalisis[] = $hasil;
        }

        if (empty($hasilAnalisis)) {
            $this->sinkronTaskTanpaTambahan($projectId);
            return [];
        }

        // Kirim semua hasil ke Gemini untuk summary
        $summary = $this->mintaSummaryDariGemini(
            hasilAnalisis: $hasilAnalisis,
            projectId: $projectId,
            catatanTeknis: (string) ($project?->catatan_teknis ?? ''),
            tanpaPotonganDariPrompt: $tanpaPotonganDariPrompt,
            instruksiAi: $instruksiAi,
        );

        // Simpan/Update Task untuk Staff Adm (sinkron per project)
        $this->sinkronTaskUntukStaff($summary, $projectId, $hasilAnalisis, $instruksiAi);

        return $hasilAnalisis;
    }

    /**
     * Analisa 1 material: cukup / sebagian / kosong
     */
    private function analisaSatuItem(Material $material, float $dibutuhkan, int $projectId, bool $tanpaPotongan = false, string $alasanInstruksi = ''): array
    {
        $applyNoCut = $tanpaPotongan && $this->isNoCutRelevantMaterial($material);

        if ($applyNoCut) {
            $rollStandar = (float) ($material->default_roll_length ?? 0);
            $minimumUtuh = $rollStandar > 0 ? $rollStandar : $dibutuhkan;
            $stokTersediaFaktual = (float) $material->available;
            $stokSisaPotonganPieces = MaterialPiece::query()
                ->where('material_id', $material->id)
                ->where('status', 'available')
                ->where('panjang', '<', $minimumUtuh)
                ->orderByDesc('panjang')
                ->pluck('panjang')
                ->map(fn ($value) => (float) $value)
                ->values()
                ->all();

            $stokSisaPotongan = array_sum($stokSisaPotonganPieces);
            $stokSisaPotonganList = collect($stokSisaPotonganPieces)
                ->take(6)
                ->map(fn (float $value) => $this->formatQty($value))
                ->values()
                ->all();

            $potonganBreakdown = empty($stokSisaPotonganList)
                ? ''
                : 'potongan tersedia ' . implode(' + ', $stokSisaPotonganList);

            $stokUtuhCukup = MaterialPiece::query()
                ->where('material_id', $material->id)
                ->where('status', 'available')
                ->whereIn('sumber', ['inbound_baru', 'opening_balance'])
                ->where('panjang', '>=', $minimumUtuh)
                ->exists();

            return [
                'material_id' => $material->id,
                'nama_material' => $material->nama_material,
                'dibutuhkan' => $dibutuhkan,
                // Tampilkan stok faktual agar user tetap melihat kondisi gudang real-time.
                'available' => round($stokTersediaFaktual, 2),
                'aksi' => $stokUtuhCukup ? 'full_booking' : 'full_pr',
                'qty_booking' => $stokUtuhCukup ? $dibutuhkan : 0,
                'qty_pr' => $stokUtuhCukup ? 0 : $dibutuhkan,
                'tanpa_potongan' => true,
                'stok_sisa_potongan' => $stokSisaPotongan,
                'stok_sisa_potongan_list' => $stokSisaPotonganList,
                'alasan_ai' => $stokUtuhCukup
                    ? ('instruksi tidak boleh potongan, jadi sistem hanya memakai roll utuh yang memenuhi kebutuhan. ' . trim($alasanInstruksi))
                    : ('instruksi tidak boleh potongan dan stok roll utuh tidak mencukupi' . ($stokSisaPotongan > 0 ? ', stok sisa ' . $this->formatQty($stokSisaPotongan) . ' tidak bisa dipakai' : '') . ($potonganBreakdown !== '' ? ', ' . $potonganBreakdown . ' tidak bisa dipakai' : '') . ', sehingga kebutuhan dialihkan ke PR. ' . trim($alasanInstruksi)),
            ];
        }

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
                'tanpa_potongan' => false,
                'alasan_ai' => 'Karena stok tersedia mencukupi, kebutuhan dipenuhi dengan booking penuh tanpa PR.',
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
                'tanpa_potongan' => false,
                'alasan_ai' => 'Karena stok hanya mencukupi sebagian, sistem booking sesuai stok dan sisanya dibuat PR.',
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
                'tanpa_potongan' => false,
                'alasan_ai' => 'Karena stok kosong, seluruh kebutuhan material dibuatkan PR.',
            ];
        }
    }

    /**
     * Minta Gemini bikin summary yang readable untuk Staff
     */
    private function mintaSummaryDariGemini(array $hasilAnalisis, int $projectId, string $catatanTeknis = '', bool $tanpaPotonganDariPrompt = false, array $instruksiAi = []): string
    {
        $dataJson = json_encode($hasilAnalisis, JSON_PRETTY_PRINT);
        $catatanPrompt = $catatanTeknis !== '' ? $catatanTeknis : '(kosong)';
        $aturanPotongan = $tanpaPotonganDariPrompt
            ? 'Instruksi Manager Teknik mengarah ke TANPA POTONGAN.'
            : 'Tidak ada instruksi eksplisit TANPA POTONGAN.';
        $instruksiRingkas = (string) ($instruksiAi['ringkas'] ?? '-');

        $prompt = <<<PROMPT
        Kamu adalah asisten sistem inventory gudang yang cerdas.
        
        Ada proyek baru (Project ID: {$projectId}) yang membutuhkan material.
        Catatan teknis dari Manager Teknik: {$catatanPrompt}
        Interpretasi aturan potongan: {$aturanPotongan}
        Ringkasan instruksi terstruktur: {$instruksiRingkas}
        Berikut hasil analisis ketersediaan stok:
        
        {$dataJson}
        
        Berikan ringkasan yang JELAS dan SINGKAT dalam Bahasa Indonesia untuk Staff Administrasi Gudang.
        PENTING: Dilarang membuat angka baru. Semua angka harus persis dari data analisis di atas.
        Gaya bahasa: natural, human-friendly, seperti penjelasan rekan kerja operasional.
        Jangan kaku seperti template sistem, tapi juga jangan terlalu chatty.
        Batasan: maksimal 1-2 kalimat per item, hindari filler seperti "btw", "hehe", "tentunya", "sangat-sangat".
        Untuk setiap item rekomendasi, tulis detail ringkas dengan pola salah satu ini:
        - "Butuh X, tersedia total Z, dibooking A, dikarenakan ..."
        - "Butuh X, tersedia total Z, generate PR B, dikarenakan ..."
        - "Butuh X, tersedia total Z, dibooking A, generate PR B, dikarenakan ..."
        Bagian "dikarenakan ..." wajib menjelaskan alasan keputusan secara praktis sesuai kondisi stok dan aturan project.
        
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

    private function parseInstruksiOperasionalDariCatatan(string $catatanTeknis, int $projectId): array
    {
        $text = trim($catatanTeknis);

        if ($text === '') {
            return [
                'tanpa_potongan' => false,
                'ringkas' => 'Tidak ada instruksi khusus.',
                'alasan' => '',
                'sumber' => 'empty',
                'confidence' => 'rendah',
            ];
        }

        // Explicit allow-cut intent should override no-cut assumptions.
        if ((bool) preg_match('/boleh\s*potong|boleh\s*potongan|potong\s*aja|pakai\s*potongan|gunakan\s*potongan/i', $text)) {
            return [
                'tanpa_potongan' => false,
                'ringkas' => 'Catatan mengizinkan penggunaan potongan.',
                'alasan' => 'Instruksi pengguna mengizinkan potongan.',
                'sumber' => 'rule_allow_cut',
                'confidence' => 'tinggi',
            ];
        }

        // Fast-path for highly explicit no-cut phrases commonly typed in field notes.
        if ((bool) preg_match('/jangan[^\n\r]{0,40}potong|jgn[^\n\r]{0,40}potong|tanpa\s*potong|tnp\s*potong|harus\s*utuh|wajib\s*utuh|roll\s*utuh|full\s*roll|no\s*cut|no\s*splice|tidak\s*boleh\s*sambung|ga\s*boleh\s*sambung|nggak\s*boleh\s*sambung/i', $text)) {
            return [
                'tanpa_potongan' => true,
                'ringkas' => 'Catatan mewajibkan material utuh tanpa potongan/sambungan.',
                'alasan' => 'Terdeteksi instruksi eksplisit tidak boleh potongan.',
                'sumber' => 'rule_no_cut',
                'confidence' => 'tinggi',
            ];
        }

        $prompt = <<<PROMPT
        Kamu adalah classifier instruksi teknis proyek gudang.

        Project ID: {$projectId}
        Catatan teknis manager:
        "{$text}"

        Tugas: tentukan apakah catatan di atas menginstruksikan material KABEL harus utuh / tidak boleh potongan / tidak boleh sambungan.
        Catatan bisa informal, singkatan, typo, atau bahasa campur.

        Contoh yang berarti YA:
        - "jgn potongan"
        - "roll harus utuh"
        - "no cut"
        - "ga boleh sambung"

        Contoh yang berarti TIDAK:
        - "boleh potongan"
        - "pakai sisa potong juga gapapa"
        - "bebas"

        Jawab HANYA JSON valid (tanpa teks lain):
        {
          "tanpa_potongan": true|false,
          "ringkas": "ringkasan aturan 1 kalimat",
          "alasan": "alasan singkat kenapa diklasifikasi begitu"
        }
        PROMPT;

        try {
            $responseRaw = (string) $this->gemini->ask($prompt);
            $response = trim((string) preg_replace('/```[a-z]*|```/', '', $responseRaw));
            $decoded = json_decode($response, true);

            if (is_array($decoded) && array_key_exists('tanpa_potongan', $decoded)) {
                return [
                    'tanpa_potongan' => (bool) $decoded['tanpa_potongan'],
                    'ringkas' => (string) ($decoded['ringkas'] ?? ''),
                    'alasan' => (string) ($decoded['alasan'] ?? ''),
                    'sumber' => 'llm_json',
                    'confidence' => 'sedang',
                ];
            }

            $lower = mb_strtolower($response);

            if (preg_match('/\b(ya|yes|true|1)\b/u', $lower)) {
                return [
                    'tanpa_potongan' => true,
                    'ringkas' => 'Catatan mewajibkan material utuh tanpa potongan.',
                    'alasan' => 'Classifier AI mengembalikan YA.',
                    'sumber' => 'llm_text',
                    'confidence' => 'sedang',
                ];
            }

            if (preg_match('/\b(tidak|no|false|0)\b/u', $lower)) {
                return [
                    'tanpa_potongan' => false,
                    'ringkas' => 'Catatan tidak mewajibkan larangan potongan.',
                    'alasan' => 'Classifier AI mengembalikan TIDAK.',
                    'sumber' => 'llm_text',
                    'confidence' => 'sedang',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal klasifikasi aturan tanpa potongan via AI, fallback regex.', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);
        }

        // Last safety fallback for terse/ambiguous notes.
        $fallbackNoCut = (bool) preg_match('/utuh|full\s*roll|no\s*cut|jgn\s*potong|jangan\s*potong/i', $text);

        return [
            'tanpa_potongan' => $fallbackNoCut,
            'ringkas' => $fallbackNoCut
                ? 'Fallback regex: terdeteksi instruksi tanpa potongan.'
                : 'Fallback regex: tidak terdeteksi instruksi tanpa potongan.',
            'alasan' => 'Fallback lokal saat classifier AI tidak memberi output terstruktur.',
            'sumber' => 'fallback_regex',
            'confidence' => 'rendah',
        ];
    }

    /**
     * Buat Task di database untuk Staff Adm Gudang
     */
    private function sinkronTaskUntukStaff(string $summaryJson, int $projectId, array $detail, array $instruksiAi = []): void
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
        'ai_instruction' => [
            'tanpa_potongan' => (bool) ($instruksiAi['tanpa_potongan'] ?? false),
            'ringkas' => (string) ($instruksiAi['ringkas'] ?? ''),
            'alasan' => (string) ($instruksiAi['alasan'] ?? ''),
            'sumber' => (string) ($instruksiAi['sumber'] ?? 'unknown'),
            'confidence' => (string) ($instruksiAi['confidence'] ?? 'rendah'),
        ],
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
                'qty' => round($qtyDelta, 2),
                'qty_original' => round($qtyRequested, 2),
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
        // Selalu gunakan angka deterministik hasil engine backend untuk menghindari halusinasi LLM.
        $rekomendasiList = collect($rekomendasi)
            ->filter(fn ($item) => is_array($item))
            ->values();

        $rekomendasiByMaterial = collect($rekomendasi)
            ->filter(fn ($item) => is_array($item))
            ->keyBy(fn (array $item) => mb_strtolower(trim((string) ($item['material'] ?? ''))));

        return collect($detail)->values()->map(function (array $item, int $index) use ($rekomendasiByMaterial, $rekomendasiList) {
            $aksi = match ($item['aksi'] ?? null) {
                'full_booking' => 'booking',
                'partial' => 'booking sebagian + PR',
                'full_pr' => 'buat PR',
                default => 'review manual',
            };

            $namaMaterial = (string) ($item['nama_material'] ?? ('Material #' . ($item['material_id'] ?? '-')));
            $materialKey = mb_strtolower(trim($namaMaterial));
            $llmItem = $rekomendasiByMaterial->get($materialKey, []);

            if (empty($llmItem)) {
                $llmItem = (array) ($rekomendasiList->get($index) ?? []);
            }

            $fallbackReason = trim((string) ($item['alasan_ai'] ?? 'mengikuti aturan instruksi proyek dan kondisi stok aktual'));
            $reasonFromLlm = $this->extractReasonPhrase((string) ($llmItem['detail'] ?? ''));
            $reason = $this->normalizeReasonFromLlm($reasonFromLlm, $fallbackReason);
            $reason = $this->sinkronkanAngkaAlasan($reason, $item);
            $reason = $this->sesuaikanAlasanDenganTipeMaterial($reason, $item);
            $reason = rtrim($reason, '.');

            if ((bool) ($item['tanpa_potongan'] ?? false)) {
                $aksiNoCut = (string) ($item['aksi'] ?? '');
                $stokSisa = $this->formatQty((float) ($item['stok_sisa_potongan'] ?? 0));

                $reason = match ($aksiNoCut) {
                    'full_booking' => 'aturan tanpa potongan aktif, stok roll utuh tersedia sehingga kebutuhan bisa langsung dibooking',
                    'partial' => 'aturan tanpa potongan aktif, stok roll utuh hanya menutup sebagian kebutuhan sehingga sisanya dibuat PR',
                    'full_pr' => "aturan tanpa potongan aktif, stok roll utuh tidak mencukupi sehingga dibuat PR (stok potongan {$stokSisa} tidak dipakai)",
                    default => $reason,
                };
            }

            $butuh = $this->formatQty((float) ($item['kebutuhan_asli'] ?? ($item['dibutuhkan'] ?? 0)));
            $tersedia = $this->formatQty((float) ($item['available'] ?? 0));
            $booking = $this->formatQty((float) ($item['qty_booking'] ?? 0));
            $pr = $this->formatQty((float) ($item['qty_pr'] ?? 0));

            $detailText = match ($item['aksi'] ?? null) {
                'full_booking' => "Butuh {$butuh}, tersedia total {$tersedia}, dibooking {$booking}, dikarenakan {$reason}.",
                'full_pr' => "Butuh {$butuh}, tersedia total {$tersedia}, generate PR {$pr}, dikarenakan {$reason}.",
                'partial' => "Butuh {$butuh}, tersedia total {$tersedia}, dibooking {$booking}, generate PR {$pr}, dikarenakan {$reason}.",
                default => "Butuh {$butuh}, tersedia total {$tersedia}, dibooking {$booking}, generate PR {$pr}, dikarenakan {$reason}.",
            };

            return [
                'material' => $namaMaterial,
                'aksi' => $aksi,
                'detail' => $detailText,
            ];
        })->values()->all();
    }

    private function extractReasonPhrase(string $detail): string
    {
        $text = trim($detail);

        if ($text === '') {
            return '';
        }

        if (preg_match('/dikarenakan\s+(.+)$/iu', $text, $match)) {
            return trim((string) ($match[1] ?? ''));
        }

        if (preg_match('/karena\s+(.+)$/iu', $text, $match)) {
            return trim((string) ($match[1] ?? ''));
        }

        if (preg_match('/alasan\s*[:\-]\s*(.+)$/iu', $text, $match)) {
            return trim((string) ($match[1] ?? ''));
        }

        return $text;
    }

    private function normalizeReasonFromLlm(string $reasonFromLlm, string $fallbackReason): string
    {
        $text = trim($reasonFromLlm !== '' ? $reasonFromLlm : $fallbackReason);

        if ($text === '') {
            return 'aturan project dan kondisi stok saat ini mengarah ke keputusan tersebut';
        }

        $normalized = preg_replace('/\s+/', ' ', (string) $text);
        $normalized = trim((string) $normalized);
        $normalized = preg_replace('/^(karena|dikarenakan)\s+/iu', '', (string) $normalized);
        $normalized = rtrim((string) $normalized, '.');

        return (string) $normalized;
    }

    private function sinkronkanAngkaAlasan(string $reason, array $item): string
    {
        $normalized = trim($reason);

        if ($normalized === '') {
            return $normalized;
        }

        if ((bool) ($item['tanpa_potongan'] ?? false)) {
            $aksi = (string) ($item['aksi'] ?? '');
            $qtyPr = (float) ($item['qty_pr'] ?? 0);
            $perluAlasanPotongan = in_array($aksi, ['full_pr', 'partial'], true) || $qtyPr > 0;

            $stokSisa = (float) ($item['stok_sisa_potongan'] ?? 0);
            $stokSisaText = $this->formatQty($stokSisa);
            $stokSisaList = collect($item['stok_sisa_potongan_list'] ?? [])
                ->map(fn ($value) => $this->formatQty((float) $value))
                ->filter()
                ->values()
                ->all();

            if ($stokSisa > 0) {
                if (preg_match('/stok\s+sisa\s+\d+(?:\.\d+)?/iu', $normalized)) {
                    $normalized = preg_replace(
                        '/stok\s+sisa\s+\d+(?:\.\d+)?/iu',
                        'stok sisa ' . $stokSisaText,
                        $normalized,
                        1,
                    );
                }
            }

            if ($perluAlasanPotongan && !empty($stokSisaList)) {
                $potonganText = implode(' + ', $stokSisaList);
                if (!preg_match('/potongan\s+tersedia\s+/iu', $normalized)) {
                    $normalized = rtrim($normalized, '.');
                    $normalized .= ". Potongan tersedia {$potonganText}, jadi tidak bisa dipakai karena aturan tanpa potongan";
                }
            }
        }

        return $this->ringkasAlasanJikaPanjang($normalized, $item);
    }

    private function ringkasAlasanJikaPanjang(string $reason, array $item): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $reason));
        $aksi = (string) ($item['aksi'] ?? '');
        $qtyPr = (float) ($item['qty_pr'] ?? 0);
        $perluAlasanPotongan = in_array($aksi, ['full_pr', 'partial'], true) || $qtyPr > 0;

        $shouldShorten = mb_strlen($text) > 190;

        if ((bool) ($item['tanpa_potongan'] ?? false)
            && $perluAlasanPotongan
            && preg_match('/potongan\s+tersedia\s+/iu', $text)) {
            $shouldShorten = true;
        }

        if ($text === '' || !$shouldShorten) {
            return $text;
        }

        if ((bool) ($item['tanpa_potongan'] ?? false)) {
            if (!$perluAlasanPotongan) {
                return 'aturan tanpa potongan aktif dan stok roll utuh tersedia, sehingga kebutuhan bisa langsung dibooking.';
            }

            $stokSisa = (float) ($item['stok_sisa_potongan'] ?? 0);
            $stokSisaText = $this->formatQty($stokSisa);
            $stokSisaList = collect($item['stok_sisa_potongan_list'] ?? [])
                ->map(fn ($value) => $this->formatQty((float) $value))
                ->filter()
                ->take(4)
                ->values()
                ->all();

            if (!empty($stokSisaList)) {
                $potonganText = implode(' + ', $stokSisaList);
                return "aturan tanpa potongan aktif, stok yang ada hanya potongan {$potonganText} (total {$stokSisaText}) jadi tidak bisa dipakai.";
            }

            return 'aturan tanpa potongan aktif dan stok roll utuh tidak mencukupi, sehingga kebutuhan dialihkan ke PR.';
        }

        return 'stok tersedia belum mencukupi kebutuhan, sehingga sistem melakukan penyesuaian booking dan PR.';
    }

    private function isNoCutRelevantMaterial(Material $material): bool
    {
        if ((float) ($material->default_roll_length ?? 0) > 0) {
            return true;
        }

        $text = mb_strtolower(trim((string) ($material->nama_material ?? '')));

        return (bool) preg_match('/kabel|cable|fiber|fo\b|optic|utp|ftp|coax|coaxial|wire|roll/i', $text);
    }

    private function sesuaikanAlasanDenganTipeMaterial(string $reason, array $item): string
    {
        $text = trim($reason);

        if ($text === '') {
            return $text;
        }

        if ((bool) ($item['tanpa_potongan'] ?? false)) {
            return $text;
        }

        if (!preg_match('/potong|potongan|roll\s+utuh|tanpa\s+potongan/i', $text)) {
            return $text;
        }

        $aksi = (string) ($item['aksi'] ?? '');
        $available = (float) ($item['available'] ?? 0);
        $qtyBooking = (float) ($item['qty_booking'] ?? 0);

        return match ($aksi) {
            'full_booking' => 'stok tersedia mencukupi sehingga kebutuhan bisa langsung dibooking',
            'partial' => 'stok tersedia hanya mencukupi sebagian, sehingga sisanya perlu dibuatkan PR',
            'full_pr' => $available > 0 && $qtyBooking <= 0
                ? 'stok yang tersedia belum memenuhi syarat kebutuhan item ini, sehingga dialihkan ke PR'
                : 'stok tersedia tidak mencukupi sehingga kebutuhan dialihkan ke PR',
            default => 'keputusan mengikuti kondisi stok aktual untuk material ini',
        };
    }

    private function formatQty(float|int $value): string
    {
        $number = (float) $value;

        if (fmod($number, 1.0) === 0.0) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}