<?php

namespace App\Services\AI;

use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\ShelfLocation;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

class BookingAIService
{
    protected GeminiService $gemini;
    protected bool $skipGeminiPieceSelection = false;

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
        $projectCode = (string) ($project?->kode_proyek ?: ('#' . $projectId));
        $catatanTeknis = (string) ($project?->catatan_teknis ?? '');
        $kebutuhanDelta = $this->hitungDeltaKebutuhan($projectId, $kebutuhan);

        if (empty($kebutuhanDelta)) {
            $this->sinkronTaskTanpaTambahan($projectId);
            return [];
        }

        $stockData = $this->prepareStockDataForGemini($kebutuhanDelta);
        $kebutuhanItems = collect($kebutuhanDelta)->map(function (array $item): array {
            $material = Material::query()->find((int) $item['material_id']);

            return [
                'material_id' => (int) ($item['material_id'] ?? 0),
                'material_name' => (string) ($material?->nama_material ?: ('Material #' . (int) ($item['material_id'] ?? 0))),
                'qty' => round((float) ($item['qty'] ?? 0), 2),
                'satuan' => (string) ($material?->satuan ?: 'unit'),
                'qty_original' => round((float) ($item['qty_original'] ?? $item['qty'] ?? 0), 2),
            ];
        })->values()->all();

        $hasilAnalisis = [];
        $instruksiAi = [
            'tanpa_potongan' => false,
            'ringkas' => 'Tidak ada instruksi khusus soal potongan.',
            'alasan' => '',
            'sumber' => 'gemini_full_analysis',
            'confidence' => 'sedang',
        ];
        $ringkasanGemini = 'Silakan review rekomendasi booking/PR dari AI untuk project ini.';
        $rekomendasiGemini = [];

        $geminiFailedTotal = false;
        $geminiResponse = [];

        try {
            $historicalContext = $this->prepareHistoricalContext($kebutuhanDelta);

            $geminiResponse = $this->askGeminiFullAnalysis(
                projectId: $projectId,
                projectCode: $projectCode,
                catatanTeknis: $catatanTeknis,
                kebutuhanItems: $kebutuhanItems,
                materialsStock: $stockData,
                historicalContext: $historicalContext,
            );
        } catch (\Throwable $e) {
            $geminiFailedTotal = true;

            Log::warning('Gemini full analysis gagal total, fallback lokal untuk semua material.', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);
        }

        if ($geminiFailedTotal) {
            $instruksiAi = $this->parseInstruksiOperasionalDariCatatan($catatanTeknis, $projectId);
            $tanpaPotonganDariPrompt = (bool) ($instruksiAi['tanpa_potongan'] ?? false);

            foreach ($kebutuhanDelta as $item) {
                $material = Material::query()->find((int) $item['material_id']);
                if (!$material) {
                    continue;
                }

                $fallbackAnalisa = $this->analisaSatuItem(
                    material: $material,
                    dibutuhkan: (float) ($item['qty'] ?? 0),
                    projectId: $projectId,
                    tanpaPotongan: $tanpaPotonganDariPrompt,
                    alasanInstruksi: (string) ($instruksiAi['alasan'] ?? ''),
                );

                $fallbackSelection = $this->fallbackPieceSelection(
                    materialId: (int) $material->id,
                    dibutuhkan: (float) ($fallbackAnalisa['qty_booking'] ?? 0),
                    materialName: (string) ($material->nama_material ?? ''),
                    satuan: (string) ($material->satuan ?? 'unit'),
                );

                $pieceById = collect((array) data_get($stockData, (string) $material->id . '.pieces', []))
                    ->keyBy(fn (array $piece): int => (int) ($piece['id'] ?? 0));

                ['chosen' => $chosen, 'remaining' => $remaining] = $this->buildChosenPiecesFromSelection(
                    selectedIds: collect($fallbackSelection['selected_piece_ids'] ?? [])->map(fn ($id): int => (int) $id)->values()->all(),
                    pieceById: $pieceById,
                    dibutuhkan: (float) ($fallbackAnalisa['qty_booking'] ?? 0),
                );

                $fallbackAnalisa['recommended_piece_ids'] = collect($chosen)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
                $fallbackAnalisa['recommended_qty'] = round((float) ($fallbackAnalisa['qty_booking'] ?? 0) - max(0, $remaining), 2);
                $fallbackAnalisa['recommended_pieces'] = $chosen;
                $fallbackAnalisa['alasan_ai'] = trim(implode('. ', array_filter([
                    (string) ($fallbackSelection['overall_reason'] ?? ''),
                    (string) ($fallbackAnalisa['alasan_ai'] ?? ''),
                ])));
                $fallbackAnalisa['kebutuhan_asli'] = (float) ($item['qty_original'] ?? $item['qty'] ?? 0);
                $fallbackAnalisa['kebutuhan_setelah_buffer'] = (float) ($item['qty'] ?? 0);
                $fallbackAnalisa['kebutuhan_belum_tercover'] = max(0, (float) ($fallbackAnalisa['qty_pr'] ?? 0));

                $hasilAnalisis[] = $fallbackAnalisa;
            }

            $ringkasanGemini = 'Gemini sedang sibuk, sistem tetap menyiapkan rekomendasi agar operasional tidak berhenti.';
            $rekomendasiGemini = [];
        } else {
            $instruksiNode = is_array($geminiResponse['instruksi_manager'] ?? null)
                ? $geminiResponse['instruksi_manager']
                : [];

            $instruksiAi = [
                'tanpa_potongan' => (bool) ($instruksiNode['tanpa_potongan'] ?? false),
                'ringkas' => (string) ($instruksiNode['ringkas'] ?? 'Tidak ada instruksi khusus soal potongan.'),
                'alasan' => (string) ($instruksiNode['alasan'] ?? ''),
                'sumber' => 'gemini_full_analysis',
                'confidence' => 'sedang',
            ];

            $ringkasanGemini = (string) ($geminiResponse['ringkasan'] ?? $ringkasanGemini);
            $materialsNode = is_array($geminiResponse['materials'] ?? null) ? $geminiResponse['materials'] : [];

            foreach ($kebutuhanItems as $item) {
                $materialId = (int) ($item['material_id'] ?? 0);
                $dibutuhkan = (float) ($item['qty'] ?? 0);
                $material = Material::query()->find($materialId);

                if ($materialId <= 0 || $dibutuhkan <= 0 || !$material) {
                    continue;
                }

                $available = (float) data_get($stockData, (string) $materialId . '.available', 0);
                $pieces = (array) data_get($stockData, (string) $materialId . '.pieces', []);
                $materialGemini = $materialsNode[(string) $materialId] ?? null;

                $useMaterialFallback = false;
                $aksi = '';
                $selectedIds = [];
                $alasanPerPiece = [];
                $overallReason = '';
                $sisaAnalysis = '';

                if (!is_array($materialGemini)) {
                    $useMaterialFallback = true;
                } else {
                    $aksi = (string) ($materialGemini['aksi'] ?? '');
                    $selectedIds = collect($materialGemini['selected_piece_ids'] ?? [])
                        ->map(fn ($id): int => (int) $id)
                        ->filter(fn (int $id): bool => $id > 0)
                        ->unique()
                        ->values()
                        ->all();

                    $alasanPerPiece = is_array($materialGemini['alasan_per_piece'] ?? null)
                        ? collect($materialGemini['alasan_per_piece'])
                            ->mapWithKeys(fn ($reason, $id): array => [(string) ((int) $id) => trim((string) $reason)])
                            ->all()
                        : [];

                    $overallReason = trim((string) ($materialGemini['overall_reason'] ?? ''));
                    $sisaAnalysis = trim((string) ($materialGemini['sisa_analysis'] ?? ''));

                    if (!in_array($aksi, ['full_booking', 'partial', 'full_pr'], true)) {
                        $useMaterialFallback = true;
                    }

                    $allowedIds = collect($pieces)->pluck('id')->map(fn ($id): int => (int) $id)->all();
                    $hasInvalidPiece = collect($selectedIds)
                        ->contains(fn (int $id): bool => !in_array($id, $allowedIds, true));

                    if ($hasInvalidPiece) {
                        $useMaterialFallback = true;
                    }

                    if (in_array($aksi, ['full_booking', 'partial'], true) && empty($selectedIds)) {
                        $useMaterialFallback = true;
                    }
                }

                if ($useMaterialFallback) {
                    $fallbackAnalisa = $this->analisaSatuItem(
                        material: $material,
                        dibutuhkan: $dibutuhkan,
                        projectId: $projectId,
                        tanpaPotongan: (bool) ($instruksiAi['tanpa_potongan'] ?? false),
                        alasanInstruksi: (string) ($instruksiAi['alasan'] ?? ''),
                    );

                    $fallbackSelection = $this->fallbackPieceSelection(
                        materialId: $materialId,
                        dibutuhkan: (float) ($fallbackAnalisa['qty_booking'] ?? 0),
                        materialName: (string) ($material->nama_material ?? ''),
                        satuan: (string) ($material->satuan ?? 'unit'),
                    );

                    $pieceById = collect($pieces)->keyBy(fn (array $piece): int => (int) ($piece['id'] ?? 0));
                    ['chosen' => $chosen, 'remaining' => $remaining] = $this->buildChosenPiecesFromSelection(
                        selectedIds: collect($fallbackSelection['selected_piece_ids'] ?? [])->map(fn ($id): int => (int) $id)->values()->all(),
                        pieceById: $pieceById,
                        dibutuhkan: (float) ($fallbackAnalisa['qty_booking'] ?? 0),
                    );

                    $fallbackAnalisa['recommended_piece_ids'] = collect($chosen)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
                    $fallbackAnalisa['recommended_qty'] = round((float) ($fallbackAnalisa['qty_booking'] ?? 0) - max(0, $remaining), 2);
                    $fallbackAnalisa['recommended_pieces'] = $chosen;
                    $fallbackAnalisa['kebutuhan_asli'] = (float) ($item['qty_original'] ?? $dibutuhkan);
                    $fallbackAnalisa['kebutuhan_setelah_buffer'] = $dibutuhkan;
                    $fallbackAnalisa['kebutuhan_belum_tercover'] = max(0, (float) ($fallbackAnalisa['qty_pr'] ?? 0));

                    $hasilAnalisis[] = $fallbackAnalisa;
                    continue;
                }

                [$qtyBooking, $qtyPr] = match ($aksi) {
                    'full_booking' => [$dibutuhkan, 0.0],
                    'partial' => [min($available, $dibutuhkan), max(0, $dibutuhkan - min($available, $dibutuhkan))],
                    'full_pr' => [0.0, $dibutuhkan],
                    default => [0.0, $dibutuhkan],
                };

                if ($qtyBooking > $available + 0.00001) {
                    $qtyBooking = max(0, $available);
                    $qtyPr = max(0, $dibutuhkan - $qtyBooking);
                }

                $pieceById = collect($pieces)->keyBy(fn (array $piece): int => (int) ($piece['id'] ?? 0));
                ['chosen' => $chosen, 'remaining' => $remaining] = $this->buildChosenPiecesFromSelection(
                    selectedIds: $selectedIds,
                    pieceById: $pieceById,
                    dibutuhkan: $qtyBooking,
                );

                if ($qtyBooking > 0 && empty($chosen)) {
                    $fallbackAnalisa = $this->analisaSatuItem(
                        material: $material,
                        dibutuhkan: $dibutuhkan,
                        projectId: $projectId,
                        tanpaPotongan: (bool) ($instruksiAi['tanpa_potongan'] ?? false),
                        alasanInstruksi: (string) ($instruksiAi['alasan'] ?? ''),
                    );

                    $fallbackSelection = $this->fallbackPieceSelection(
                        materialId: $materialId,
                        dibutuhkan: (float) ($fallbackAnalisa['qty_booking'] ?? 0),
                        materialName: (string) ($material->nama_material ?? ''),
                        satuan: (string) ($material->satuan ?? 'unit'),
                    );
                    ['chosen' => $chosen, 'remaining' => $remaining] = $this->buildChosenPiecesFromSelection(
                        selectedIds: collect($fallbackSelection['selected_piece_ids'] ?? [])->map(fn ($id): int => (int) $id)->values()->all(),
                        pieceById: $pieceById,
                        dibutuhkan: (float) ($fallbackAnalisa['qty_booking'] ?? 0),
                    );

                    $fallbackAnalisa['recommended_piece_ids'] = collect($chosen)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
                    $fallbackAnalisa['recommended_qty'] = round((float) ($fallbackAnalisa['qty_booking'] ?? 0) - max(0, $remaining), 2);
                    $fallbackAnalisa['recommended_pieces'] = $chosen;
                    $fallbackAnalisa['kebutuhan_asli'] = (float) ($item['qty_original'] ?? $dibutuhkan);
                    $fallbackAnalisa['kebutuhan_setelah_buffer'] = $dibutuhkan;
                    $fallbackAnalisa['kebutuhan_belum_tercover'] = max(0, (float) ($fallbackAnalisa['qty_pr'] ?? 0));
                    $hasilAnalisis[] = $fallbackAnalisa;
                    continue;
                }

                $perPieceReasonText = collect($chosen)
                    ->map(function (array $piece) use ($alasanPerPiece): string {
                        $pieceId = (int) ($piece['id'] ?? 0);
                        $reason = trim((string) ($alasanPerPiece[(string) $pieceId] ?? ''));

                        return $reason !== '' ? ('Piece #' . $pieceId . ': ' . $reason) : '';
                    })
                    ->filter()
                    ->implode('. ');

                $reason = implode('. ', array_values(array_filter([
                    $overallReason,
                    $perPieceReasonText,
                    $sisaAnalysis,
                ], fn ($segment): bool => trim((string) $segment) !== '')));

                $hasilAnalisis[] = [
                    'material_id' => $materialId,
                    'nama_material' => (string) $material->nama_material,
                    'dibutuhkan' => $dibutuhkan,
                    'available' => round($available, 2),
                    'aksi' => $aksi,
                    'qty_booking' => round($qtyBooking, 2),
                    'qty_pr' => round($qtyPr, 2),
                    'tanpa_potongan' => (bool) ($instruksiAi['tanpa_potongan'] ?? false),
                    'recommended_piece_ids' => collect($chosen)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                    'recommended_qty' => round($qtyBooking - max(0, $remaining), 2),
                    'recommended_pieces' => $chosen,
                    'alasan_ai' => $reason !== ''
                        ? $reason
                        : 'Pemilihan piece dilakukan AI dan divalidasi oleh perhitungan sistem.',
                    'kebutuhan_asli' => (float) ($item['qty_original'] ?? $dibutuhkan),
                    'kebutuhan_setelah_buffer' => $dibutuhkan,
                    'kebutuhan_belum_tercover' => round($qtyPr, 2),
                ];

                $rekomendasiGemini[] = [
                    'material' => (string) $material->nama_material,
                    'aksi' => match ($aksi) {
                        'full_booking' => 'booking',
                        'partial' => 'booking sebagian + PR',
                        'full_pr' => 'buat PR',
                        default => 'review manual',
                    },
                    'detail' => $reason,
                ];
            }
        }

        if (empty($hasilAnalisis)) {
            $this->sinkronTaskTanpaTambahan($projectId);
            return [];
        }

        $summary = json_encode([
            'ringkasan' => $ringkasanGemini,
            'rekomendasi' => $rekomendasiGemini,
            'perlu_konfirmasi' => true,
        ], JSON_UNESCAPED_UNICODE);

        if (!is_string($summary) || trim($summary) === '') {
            $summary = '{"ringkasan":"Silakan review rekomendasi booking/PR dari AI untuk project ini.","rekomendasi":[],"perlu_konfirmasi":true}';
        }

        $this->sinkronTaskUntukStaff($summary, $projectId, $hasilAnalisis, $instruksiAi);

        return $hasilAnalisis;
    }

    /**
     * @param  array<int, array{material_id:int,qty:float,qty_original?:float}>  $kebutuhanDelta
     * @return array<int, array{material_id:int,material_name:string,dibutuhkan:float,satuan:string,available:float,pieces:array<int, array<string,mixed>>}>
     */
    private function prepareStockDataForGemini(array $kebutuhanDelta): array
    {
        $rackMap = ShelfLocation::query()
            ->get(['id', 'kode_lokasi', 'nama_lokasi'])
            ->mapWithKeys(fn ($row): array => [
                (int) $row->id => (string) ($row->kode_lokasi ?: ($row->nama_lokasi ?: ('#' . (int) $row->id))),
            ])
            ->toArray();

        $result = [];

        foreach ($kebutuhanDelta as $item) {
            $materialId = (int) ($item['material_id'] ?? 0);
            $dibutuhkan = (float) ($item['qty'] ?? 0);

            if ($materialId <= 0 || $dibutuhkan <= 0) {
                continue;
            }

            $material = Material::query()->find($materialId);
            if (!$material) {
                continue;
            }

            $pieces = MaterialPiece::query()
                ->with('inbound:id,entry_date')
                ->where('material_id', $materialId)
                ->where('status', 'available')
                ->orderByRaw("CASE WHEN sumber LIKE 'split_%' THEN 0 ELSE 1 END ASC")
                ->orderBy('panjang')
                ->orderByRaw("COALESCE((SELECT entry_date FROM inbounds WHERE inbounds.id = material_pieces.inbound_id), material_pieces.created_at) ASC")
                ->limit(20)
                ->get(['id', 'material_id', 'inbound_id', 'panjang', 'sumber', 'shelf_location_id', 'created_at'])
                ->map(function (MaterialPiece $piece) use ($rackMap): array {
                    $ageDays = (int) now()->diffInDays(
                        $piece->inbound?->entry_date
                            ? \Carbon\Carbon::parse($piece->inbound->entry_date)
                            : $piece->created_at,
                        false,
                    ) * -1;

                    return [
                        'id' => (int) $piece->id,
                        'panjang' => round((float) $piece->panjang, 2),
                        'sumber' => (string) $piece->sumber,
                        'rack_label' => (string) ($rackMap[(int) ($piece->shelf_location_id ?? 0)] ?? '-'),
                        'umur_hari' => max(0, $ageDays),
                        'shelf_location_id' => (int) ($piece->shelf_location_id ?? 0),
                        'status' => 'available',
                    ];
                })
                ->values()
                ->all();

            $result[$materialId] = [
                'material_id' => $materialId,
                'material_name' => (string) $material->nama_material,
                'dibutuhkan' => round($dibutuhkan, 2),
                'satuan' => (string) ($material->satuan ?: 'unit'),
                'available' => round((float) $material->available, 2),
                'pieces' => $pieces,
            ];
        }

        return $result;
    }

    /**
     * Kumpulkan data historis transaksi per material untuk konteks Gemini.
     * Gemini menggunakan data ini untuk "belajar" pola gudang dan membuat
     * keputusan yang lebih cerdas berdasarkan tren penggunaan material.
     */
    private function prepareHistoricalContext(array $kebutuhanDelta): array
    {
        $history = [];

        foreach ($kebutuhanDelta as $item) {
            $materialId = (int) ($item['material_id'] ?? 0);
            if ($materialId <= 0) {
                continue;
            }

            $material = Material::query()->find($materialId);
            if (!$material) {
                continue;
            }

            $avgBookingPerProject = (float) MaterialPiece::query()
                ->where('material_id', $materialId)
                ->where('status', 'booked')
                ->where('booked_at', '>=', now()->subMonths(3))
                ->selectRaw('booked_for_project_id, SUM(panjang) as total')
                ->groupBy('booked_for_project_id')
                ->pluck('total')
                ->avg();

            $deadStockPieces = (int) MaterialPiece::query()
                ->where('material_id', $materialId)
                ->where('status', 'available')
                ->where('created_at', '<=', now()->subDays(90))
                ->count();

            $usageBySource = MaterialPiece::query()
                ->where('material_id', $materialId)
                ->whereIn('status', ['booked', 'used'])
                ->where('booked_at', '>=', now()->subMonths(3))
                ->selectRaw("sumber, COUNT(*) as jumlah")
                ->groupBy('sumber')
                ->pluck('jumlah', 'sumber')
                ->toArray();

            $wastedCuts = (int) MaterialPiece::query()
                ->where('material_id', $materialId)
                ->where('status', 'available')
                ->whereIn('sumber', ['split_auto_allocation', 'split_booking', 'split_booking_residue'])
                ->where('panjang', '<', 20)
                ->where('created_at', '<=', now()->subDays(60))
                ->count();

            $projectCount = (int) MaterialPiece::query()
                ->where('material_id', $materialId)
                ->whereIn('status', ['booked', 'used'])
                ->where('booked_at', '>=', now()->subMonths(3))
                ->distinct('booked_for_project_id')
                ->count('booked_for_project_id');

            $history[$materialId] = [
                'material_name' => (string) $material->nama_material,
                'avg_booking_per_project' => round($avgBookingPerProject, 2),
                'dead_stock_pieces' => $deadStockPieces,
                'usage_by_source' => $usageBySource,
                'wasted_small_cuts' => $wastedCuts,
                'projects_using_3months' => $projectCount,
            ];
        }

        return $history;
    }

    /**
     * @param  array<int, array<string, mixed>>  $kebutuhanItems
     * @param  array<int, array<string, mixed>>  $materialsStock
     * @return array<string, mixed>
     */
    private function askGeminiFullAnalysis(
        int $projectId,
        string $projectCode,
        string $catatanTeknis,
        array $kebutuhanItems,
        array $materialsStock,
        array $historicalContext = []
    ): array {
        $dataset = collect($kebutuhanItems)->map(function (array $item) use ($materialsStock): array {
            $materialId = (int) ($item['material_id'] ?? 0);
            $stock = (array) ($materialsStock[$materialId] ?? []);

            return [
                'material_id' => $materialId,
                'material_name' => (string) ($item['material_name'] ?? ('Material #' . $materialId)),
                'dibutuhkan' => round((float) ($item['qty'] ?? 0), 2),
                'satuan' => (string) ($item['satuan'] ?? 'unit'),
                'available' => round((float) ($stock['available'] ?? 0), 2),
                'pieces' => array_values(array_map(function (array $piece): array {
                    return [
                        'id' => (int) ($piece['id'] ?? 0),
                        'panjang' => round((float) ($piece['panjang'] ?? 0), 2),
                        'sumber' => (string) ($piece['sumber'] ?? '-'),
                        'rack_label' => (string) ($piece['rack_label'] ?? '-'),
                        'umur_hari' => (int) ($piece['umur_hari'] ?? 0),
                    ];
                }, (array) ($stock['pieces'] ?? []))),
            ];
        })->values()->all();

        $dataJson = json_encode($dataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (!is_string($dataJson) || $dataJson === '') {
            throw new \RuntimeException('Gagal menyiapkan payload full analysis untuk Gemini.');
        }

        $catatanPrompt = trim($catatanTeknis) !== '' ? trim($catatanTeknis) : '(kosong)';
        $historyJson = !empty($historicalContext)
            ? json_encode($historicalContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : '(belum ada data historis)';

        $prompt = <<<PROMPT
Kamu adalah AI inventory analyst cerdas di gudang telekomunikasi.

PROYEK: {$projectCode} (ID: {$projectId})
CATATAN TEKNIS MANAGER: "{$catatanPrompt}"

TUGAS: Analisis kebutuhan material proyek ini dan buat keputusan lengkap untuk setiap material. Kamu bertanggung jawab atas:
A. Interpretasi instruksi manager (apakah ada aturan khusus?)
B. Keputusan per material: booking dari stok / buat PR / kombinasi
C. Pemilihan piece spesifik yang harus diambil dari gudang
D. Alasan untuk setiap keputusan

KONTEKS GUDANG:
- Material kabel/fiber bisa dipotong sesuai kebutuhan
- Material unit (CCTV, Battery, Radio) diambil utuh per unit
- Sumber piece "split_*" = sisa potongan sebelumnya
- Sumber "inbound_baru"/"opening_balance" = barang baru/utuh
- Catatan manager bisa informal/singkatan (misal "jgn potong" = jangan potong, "roll utuh" = tidak boleh potongan)

DATA KEBUTUHAN DAN STOK:
{$dataJson}

POLA HISTORIS GUDANG (3 BULAN TERAKHIR):
{$historyJson}

CARA MEMBACA DATA HISTORIS:
- avg_booking_per_project: rata-rata qty yang dipakai per proyek. Gunakan untuk memperkirakan apakah sisa potongan masih berguna untuk proyek lain.
- dead_stock_pieces: jumlah piece yang sudah lebih dari 90 hari tidak terpakai. Jika banyak, prioritaskan keluarkan stok lama.
- usage_by_source: sumber mana yang paling sering terpakai. Jika split_* banyak terpakai berarti potongan masih laku. Jika jarang berarti potongan cenderung jadi waste.
- wasted_small_cuts: potongan kecil (<20m) yang sudah lebih dari 60 hari tidak terpakai. Hindari bikin potongan kecil baru kalau bisa.
- projects_using_3months: berapa banyak proyek yang pakai material ini. Jika sering dipakai, sisa potongan kemungkinan masih berguna.

Gunakan data historis ini untuk keputusan yang LEBIH CERDAS:
- Jika wasted_small_cuts tinggi: hindari motong yang menghasilkan sisa kecil, pilih piece yang pas
- Jika dead_stock banyak: agresif keluarkan stok lama
- Jika avg_booking rendah: sisa besar kemungkinan tidak terpakai juga
- Jika material sering dipakai (projects_using tinggi): sisa masih berguna, boleh potong

PANDUAN KEPUTUSAN:

1. INSTRUKSI MANAGER
   - Jika catatan menyebut "jangan potong"/"roll utuh"/"no cut" -> material kabel HANYA boleh diambil dari roll utuh, BUKAN potongan
   - Jika tidak ada catatan soal potongan -> boleh potong

2. KEPUTUSAN BOOKING vs PR
   - Jika stok cukup -> full booking
   - Jika stok sebagian -> booking yang ada, sisanya PR
   - Jika stok kosong -> full PR
   - JANGAN MENGHITUNG jumlah stok, gunakan field "available" yang sudah dihitung sistem

3. PEMILIHAN PIECE (yang paling penting!)
   - HABISKAN POTONGAN TIDAK BULET DULU (194m, 87m -> pakai duluan. 50m, 100m, 200m -> simpan untuk proyek lain)
   - Sisa potongan (split_*) SEBELUM roll baru (inbound_baru)
   - Ukuran terkecil yang masih cukup
   - FIFO: yang lama di gudang keluar duluan (umur_hari terbesar)
   - Pertimbangkan kualitas sisa: lebih baik sisa 50m yang berguna daripada sisa 3m yang waste
   - Untuk material unit: pilih yang paling lama di gudang (FIFO)

4. ALASAN HARUS NATURAL
   - Tulis seperti rekan kerja yang menjelaskan, bukan template sistem
   - Sebutkan spesifik kenapa piece itu dipilih
   - Untuk unit: "Ambil unit yang sudah 30 hari di gudang, rotasi stok"
   - Untuk kabel: "Pakai potongan 194m yang angkanya tidak bulet, lebih baik dihabiskan sekarang daripada potong roll baru 300m"

FORMAT JAWABAN (WAJIB JSON, tanpa teks lain):
{
  "instruksi_manager": {
    "tanpa_potongan": false,
    "ringkas": "Tidak ada instruksi khusus soal potongan",
    "alasan": "Catatan teknis kosong/tidak menyebut aturan potongan"
  },
  "ringkasan": "Ringkasan 1-2 kalimat untuk staff gudang",
  "materials": {
    "5": {
      "aksi": "full_booking",
      "qty_booking": 234,
      "qty_pr": 0,
      "selected_piece_ids": [79, 24],
      "alasan_per_piece": {
        "79": "Potongan 206m angka tidak bulet, habiskan dulu",
        "24": "Cadangan dari stok lama 40 hari"
      },
      "overall_reason": "Pakai potongan 206m yang angkanya ganjil supaya gudang lebih rapi, daripada motong roll baru.",
      "sisa_analysis": "Sisa potongan masih ukuran berguna.",
      "pattern_insight": "Berdasarkan pola 3 bulan, potongan kecil material ini sering jadi waste, jadi pilih piece yang pas."
    }
  }
}

ATURAN KETAT:
- JANGAN MENGHITUNG take_qty atau sisa, cukup pilih piece_id
- piece_id HARUS dari daftar piece material yang bersangkutan
- Alasan per piece MAKSIMAL 15 kata
- overall_reason MAKSIMAL 2 kalimat, bahasa natural
- Key "materials" pakai material_id sebagai string
- qty_booking dan qty_pr AMBIL DARI field available yang sudah ada, jangan hitung sendiri
- aksi: "full_booking" / "partial" / "full_pr"
PROMPT;

        Log::info('Gemini full analysis - prompt dikirim', [
            'project_id' => $projectId,
            'materials_count' => count($dataset),
        ]);

        $responseRaw = (string) $this->gemini->ask($prompt);
        $responseClean = trim((string) preg_replace('/```json|```/i', '', $responseRaw));

        Log::info('Gemini full analysis - raw response', [
            'project_id' => $projectId,
            'response_length' => strlen($responseRaw),
            'response_preview' => mb_substr($responseClean, 0, 3000),
        ]);

        $decoded = json_decode($responseClean, true);

        if (!is_array($decoded)) {
            if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $responseClean, $match)) {
                $decoded = json_decode((string) $match[0], true);
            }
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Response Gemini full analysis bukan JSON valid.');
        }

        if (!is_array($decoded['materials'] ?? null)) {
            throw new \RuntimeException('Response Gemini tidak memiliki key materials yang valid.');
        }

        return $decoded;
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

        $available = $material->available;

        if ($available >= $dibutuhkan) {
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
     * Minta Gemini bikin summary yang readable untuk Staff (legacy, tidak dipanggil dari flow utama)
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
        $responseClean = preg_replace('/```json|```/', '', $responseRaw);

        return trim($responseClean);
    }

    private function parseInstruksiOperasionalDariCatatan(string $catatanTeknis, int $projectId): array
    {
        $text = trim($catatanTeknis);
        unset($projectId);

        if ($text === '') {
            return [
                'tanpa_potongan' => false,
                'ringkas' => 'Tidak ada instruksi khusus.',
                'alasan' => '',
                'sumber' => 'fallback_empty',
                'confidence' => 'rendah',
            ];
        }

        if ((bool) preg_match('/boleh\s*potong|boleh\s*potongan|potong\s*aja|pakai\s*potongan|gunakan\s*potongan/i', $text)) {
            return [
                'tanpa_potongan' => false,
                'ringkas' => 'Fallback regex: catatan mengizinkan penggunaan potongan.',
                'alasan' => 'Terdeteksi frasa eksplisit yang mengizinkan potongan.',
                'sumber' => 'fallback_regex_allow_cut',
                'confidence' => 'sedang',
            ];
        }

        $fallbackNoCut = (bool) preg_match('/jangan[^\n\r]{0,40}potong|jgn[^\n\r]{0,40}potong|tanpa\s*potong|tnp\s*potong|harus\s*utuh|wajib\s*utuh|roll\s*utuh|full\s*roll|no\s*cut|no\s*splice|tidak\s*boleh\s*sambung|ga\s*boleh\s*sambung|nggak\s*boleh\s*sambung|utuh/i', $text);

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
            'items'        => $this->buildPerMaterialItemsPayload($detail),
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

    private function buildPerMaterialItemsPayload(array $detail): array
    {
        return collect($detail)
            ->map(function (array $row): array {
                $materialId = (int) ($row['material_id'] ?? 0);
                $dibutuhkan = (float) ($row['dibutuhkan'] ?? 0);
                $materialName = (string) ($row['nama_material'] ?? ('Material #' . $materialId));
                $material = $materialId > 0 ? Material::query()->find($materialId) : null;
                $satuan = (string) ($material?->satuan ?: 'unit');

                $recommendedIds = collect($row['recommended_piece_ids'] ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();

                $recommendedPieces = is_array($row['recommended_pieces'] ?? null)
                    ? array_values($row['recommended_pieces'])
                    : [];

                $recommendedQty = round((float) ($row['recommended_qty'] ?? ($row['qty_booking'] ?? 0)), 2);

                $reason = trim((string) ($row['alasan_ai'] ?? ''));
                if ($reason === '') {
                    $reason = 'Belum ada piece available yang cocok untuk kebutuhan ini.';
                }

                return [
                    'material_id' => $materialId,
                    'material_name' => $materialName,
                    'dibutuhkan' => round($dibutuhkan, 2),
                    'satuan' => $satuan,
                    'recommended_booking_qty' => round((float) ($row['qty_booking'] ?? 0), 2),
                    'recommended_pr_qty' => round((float) ($row['qty_pr'] ?? 0), 2),
                    'recommended_piece_ids' => $recommendedIds,
                    'recommended_qty' => $recommendedQty,
                    'recommended_pieces' => $recommendedPieces,
                    'ai_reason' => $reason,
                    'status' => 'pending',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Legacy: recommend pieces for all materials via separate Gemini call.
     * Tidak dipanggil dari flow utama, dipertahankan untuk backward compatibility.
     */
    private function recommendPiecesForAllMaterials(array $detail): array
    {
        // Method ini dipertahankan untuk backward compatibility.
        // Flow utama sekarang menggunakan askGeminiFullAnalysis().
        return [];
    }

    /**
     * Legacy: ask Gemini for all pieces selection via separate call.
     * Tidak dipanggil dari flow utama, dipertahankan untuk backward compatibility.
     */
    private function askGeminiForAllPiecesSelection(array $materialsData, string $satuan): array
    {
        // Method ini dipertahankan untuk backward compatibility.
        // Flow utama sekarang menggunakan askGeminiFullAnalysis().
        return [];
    }

    /**
     * Legacy: recommend pieces for single material via separate Gemini call.
     * Tidak dipanggil dari flow utama, dipertahankan untuk backward compatibility.
     */
    private function recommendPiecesForMaterial(int $materialId, float $dibutuhkan): array
    {
        // Method ini dipertahankan untuk backward compatibility.
        // Flow utama sekarang menggunakan askGeminiFullAnalysis().
        return [
            'piece_ids' => [],
            'recommended_qty' => 0,
            'pieces' => [],
            'reason' => 'Method legacy, gunakan askGeminiFullAnalysis().',
        ];
    }

    /**
     * Legacy: ask Gemini for single material piece selection.
     * Tidak dipanggil dari flow utama, dipertahankan untuk backward compatibility.
     */
    private function askGeminiForPieceSelection(array $availablePieces, float $dibutuhkan, string $materialName, string $satuan): array
    {
        // Method ini dipertahankan untuk backward compatibility.
        // Flow utama sekarang menggunakan askGeminiFullAnalysis().
        return [
            'selected_piece_ids' => [],
            'alasan_per_piece' => [],
            'overall_reason' => '',
            'sisa_analysis' => '',
        ];
    }

    private function buildChosenPiecesFromSelection(array $selectedIds, \Illuminate\Support\Collection $pieceById, float $dibutuhkan): array
    {
        $remaining = $dibutuhkan;
        $chosen = [];

        foreach ($selectedIds as $pieceId) {
            if ($remaining <= 0.00001) {
                break;
            }

            $piece = $pieceById->get((int) $pieceId);
            if (!$piece || (string) ($piece['status'] ?? '') !== 'available') {
                continue;
            }

            $pieceLength = (float) ($piece['panjang'] ?? 0);
            if ($pieceLength <= 0) {
                continue;
            }

            $takeQty = min($pieceLength, $remaining);

            $chosen[] = [
                'id' => (int) $pieceId,
                'panjang' => round($pieceLength, 2),
                'take_qty' => round($takeQty, 2),
                'sumber' => (string) ($piece['sumber'] ?? '-'),
                'age_days' => (int) ($piece['umur_hari'] ?? 0),
                'shelf_location_id' => (int) ($piece['shelf_location_id'] ?? 0),
                'rack_label' => (string) ($piece['rack_label'] ?? '-'),
            ];

            $remaining -= $takeQty;
        }

        return [
            'chosen' => $chosen,
            'remaining' => $remaining,
        ];
    }

    /**
     * Fallback deterministic selector jika Gemini gagal atau output tidak valid.
     */
    private function fallbackPieceSelection(
        int $materialId,
        float $dibutuhkan,
        string $materialName = '',
        string $satuan = 'unit'
    ): array {
        $remaining = $dibutuhkan;
        $selectedIds = [];

        while ($remaining > 0.00001) {
            $candidate = MaterialPiece::query()
                ->where('material_id', $materialId)
                ->where('status', 'available')
                ->when(!empty($selectedIds), fn ($q) => $q->whereNotIn('id', $selectedIds))
                ->orderByRaw("CASE WHEN panjang >= ? THEN 0 ELSE 1 END ASC", [$remaining])
                ->orderByRaw("CASE
                    WHEN sumber IN ('split_auto_allocation', 'split_booking', 'split_booking_residue') THEN 1
                    WHEN sumber IN ('inbound_baru', 'opening_balance') THEN 2
                    ELSE 3
                END ASC")
                ->orderByRaw("CASE WHEN panjang >= ? THEN panjang ELSE -panjang END ASC", [$remaining])
                ->orderByRaw("COALESCE((SELECT entry_date FROM inbounds WHERE inbounds.id = material_pieces.inbound_id), material_pieces.created_at) ASC")
                ->first(['id', 'panjang']);

            if (!$candidate) {
                break;
            }

            $pieceId = (int) $candidate->id;
            $selectedIds[] = $pieceId;
            $remaining -= min((float) $candidate->panjang, $remaining);
        }

        $isUnitBased = in_array(strtolower($satuan), ['unit', 'buah', 'pcs', 'set', 'pasang'], true);

        if ($isUnitBased) {
            $overallReason = count($selectedIds) <= 1
                ? "Mengambil 1 unit {$materialName} yang paling lama di gudang supaya rotasi stok terjaga."
                : "Mengambil " . count($selectedIds) . " unit {$materialName} dari stok terlama di gudang untuk menjaga rotasi.";
            $sisaFull = 'Seluruh kebutuhan unit terpenuhi dari stok gudang.';
            $sisaPartial = 'Stok unit yang tersedia belum mencukupi, sisanya perlu dibuatkan PR.';
        } else {
            $overallReason = count($selectedIds) <= 1
                ? 'Mengambil dari piece terkecil yang cukup untuk meminimalkan sisa potongan yang tidak terpakai.'
                : 'Mengkombinasikan ' . count($selectedIds) . ' piece dari stok terlama untuk memenuhi kebutuhan.';
            $sisaFull = 'Kebutuhan terpenuhi, sisa potongan dikembalikan ke gudang dan masih bisa dipakai proyek lain.';
            $sisaPartial = 'Stok piece yang tersedia belum mencukupi seluruh kebutuhan, sisanya perlu dibuatkan PR.';
        }

        if (trim($overallReason) === '') {
            $overallReason = $isUnitBased
                ? 'Mengambil unit dari stok terlama di gudang untuk menjaga rotasi.'
                : 'Mengambil piece dari stok terlama untuk memenuhi kebutuhan.';
        }

        return [
            'selected_piece_ids' => $selectedIds,
            'alasan_per_piece' => [],
            'overall_reason' => $overallReason,
            'sisa_analysis' => $remaining > 0.00001 ? $sisaPartial : $sisaFull,
        ];
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
        $rekomendasiList = collect($rekomendasi)->filter(fn ($item) => is_array($item))->values();
        $rekomendasiByMaterial = collect($rekomendasi)->filter(fn ($item) => is_array($item))
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