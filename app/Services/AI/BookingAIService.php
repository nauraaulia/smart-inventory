<?php

namespace App\Services\AI;

use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\ShelfLocation;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BookingAIService
{
    protected GeminiService $gemini;

    protected bool $skipGeminiPieceSelection = false;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    public function prosesKebutuhanProyek(int $projectId, array $kebutuhan, bool $allowRetryOnTransient = false, string $triggerSource = 'system_sync'): array
    {
        $lock = Cache::lock('ai_booking_project_'.$projectId, 60);

        if (! $lock->get()) {
            Log::info('AI booking skip - sedang diproses', ['project_id' => $projectId]);

            return [];
        }

        try {
            $project = Project::query()->find($projectId);
            $projectCode = (string) ($project?->kode_proyek ?: ('#'.$projectId));
            $catatanTeknis = (string) ($project?->catatan_teknis ?? '');
            $projectTimeline = $this->buildProjectTimelineContext($project);
            $kebutuhanDelta = $this->hitungDeltaKebutuhan($projectId, $kebutuhan);

            if (empty($kebutuhanDelta)) {
                $this->sinkronTaskTanpaTambahan($projectId);

                return [];
            }

            $this->sinkronTaskSedangDiproses($projectId, $projectCode, $catatanTeknis, null, $triggerSource);

            $stockData = $this->prepareStockDataForGemini($kebutuhanDelta);
            $kebutuhanItems = collect($kebutuhanDelta)->map(function (array $item): array {
                $material = Material::query()->find((int) $item['material_id']);

                return [
                    'material_id' => (int) ($item['material_id'] ?? 0),
                    'material_name' => (string) ($material?->nama_material ?: ('Material #'.(int) ($item['material_id'] ?? 0))),
                    'qty' => round((float) ($item['qty'] ?? 0), 2),
                    'satuan' => (string) ($material?->resolveTrackingUnit() ?: 'unit'),
                    'qty_original' => round((float) ($item['qty_original'] ?? $item['qty'] ?? 0), 2),
                ];
            })->values()->all();

            $hasilAnalisis = [];
            $instruksiAi = [
                'tanpa_potongan' => false,
                'require_no_joint' => false,
                'requested_segments' => [],
                'requested_piece_count' => 0,
                'ringkas' => 'Tidak ada instruksi khusus soal potongan.',
                'alasan' => '',
                'sumber' => 'gemini_full_analysis',
                'confidence' => 'sedang',
            ];
            $ringkasanGemini = 'Silakan review rekomendasi booking/PR dari AI untuk project ini.';
            $rekomendasiGemini = [];
            $geminiFailedTotal = false;
            $geminiResponse = [];
            $geminiFailureReason = '';

            try {
                $historicalContext = (bool) config('services.gemini.booking_include_history', false)
                    ? $this->prepareHistoricalContext($kebutuhanDelta)
                    : [];
                $geminiResponse = $this->askGeminiFullAnalysis(
                    projectId: $projectId,
                    projectCode: $projectCode,
                    catatanTeknis: $catatanTeknis,
                    kebutuhanItems: $kebutuhanItems,
                    materialsStock: $stockData,
                    projectTimeline: $projectTimeline,
                    historicalContext: $historicalContext,
                );
            } catch (\Throwable $e) {
                $errorMessage = trim((string) $e->getMessage());

                if ($this->isQuotaGeminiException($errorMessage)) {
                    $geminiFailedTotal = true;
                    $geminiFailureReason = 'API Quota Limit Terlampaui';

                    Log::warning('Gemini quota habis, langsung pindah ke fallback lokal.', [
                        'project_id' => $projectId,
                        'error' => $errorMessage,
                    ]);

                    goto FALLBACK_FLOW;
                }

                $isTransient = $this->isTransientGeminiException($errorMessage);

                if ($isTransient && $allowRetryOnTransient) {
                    Log::info('Gemini transient terdeteksi, job akan gagal agar queue bisa retry.', [
                        'project_id' => $projectId,
                        'error' => $errorMessage,
                    ]);
                }

                if (! $allowRetryOnTransient) {
                    $geminiFailedTotal = true;
                    $geminiFailureReason = $errorMessage;
                    Log::warning('Gemini gagal, fallback darurat lokal dipakai.', [
                        'project_id' => $projectId,
                        'error' => $errorMessage,
                    ]);
                    goto FALLBACK_FLOW;
                }

                Log::warning('Gemini gagal. Fallback dimatikan, error dilempar ke queue untuk retry.', [
                    'project_id' => $projectId,
                    'error' => $errorMessage,
                ]);

                $this->sinkronTaskSedangDiproses(
                    $projectId,
                    $projectCode,
                    $catatanTeknis,
                    'AI sedang diproses ulang karena kendala sementara: '.$errorMessage,
                    $triggerSource,
                );

                throw $e;
            }

            FALLBACK_FLOW:
            if ($geminiFailedTotal) {
                $instruksiAi = $this->parseInstruksiOperasionalDariCatatan($catatanTeknis, $projectId);
                $tanpaPotonganDariPrompt = (bool) ($instruksiAi['tanpa_potongan'] ?? false);

                $hasilAnalisis = $this->buildFallbackAnalysis($projectId, $kebutuhanDelta, $stockData, $instruksiAi);

                $ringkasanGemini = 'AI sedang tidak tersedia (quota/error), rekomendasi lokal digunakan. Silakan review dan sesuaikan bila perlu.';
                if ($geminiFailureReason !== '') {
                    $ringkasanGemini .= ' ('.$geminiFailureReason.')';
                }
                $rekomendasiGemini = [];

            } else {
                $instruksiNode = is_array($geminiResponse['instruksi_manager'] ?? null) ? $geminiResponse['instruksi_manager'] : [];
                $requestedSegmentsFromNode = collect($instruksiNode['requested_segments'] ?? [])
                    ->map(fn ($value): float => round((float) $value, 2))
                    ->filter(fn (float $value): bool => $value > 0)
                    ->values()
                    ->all();
                $requestedPieceCountFromNode = max(0, (int) ($instruksiNode['requested_piece_count'] ?? 0));
                $hasReliableSegmentSpec = ! empty($requestedSegmentsFromNode)
                    && ! ($requestedPieceCountFromNode > 1 && count($requestedSegmentsFromNode) <= 1);
                $requestedSegmentsFinal = $hasReliableSegmentSpec ? $requestedSegmentsFromNode : [];
                $requireNoJointMerged = (bool) ($instruksiNode['require_no_joint'] ?? false)
                    || $hasReliableSegmentSpec;
                $instruksiAi = [
                    'tanpa_potongan' => (bool) ($instruksiNode['tanpa_potongan'] ?? false),
                    'require_no_joint' => $requireNoJointMerged,
                    'requested_segments' => $requestedSegmentsFinal,
                    'requested_piece_count' => $requestedPieceCountFromNode,
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
                    if ($materialId <= 0 || $dibutuhkan <= 0 || ! $material) {
                        continue;
                    }

                    $available = (float) data_get($stockData, (string) $materialId.'.available', 0);
                    $pieces = (array) data_get($stockData, (string) $materialId.'.pieces', []);
                    $materialGemini = $materialsNode[(string) $materialId] ?? null;

                    $aksi = '';
                    $selectedIds = [];
                    $alasanPerPiece = [];
                    $overallReason = '';
                    $sisaAnalysis = '';
                    $geminiAllocations = [];
                    $purchaseRecommendation = [];

                    if (! is_array($materialGemini)) {
                        throw new \RuntimeException("Gemini tidak mengembalikan analisis untuk material_id {$materialId}.");
                    } else {
                        $aksi = (string) ($materialGemini['aksi'] ?? '');
                        $selectedIds = collect($materialGemini['selected_piece_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
                        $alasanPerPiece = is_array($materialGemini['alasan_per_piece'] ?? null) ? collect($materialGemini['alasan_per_piece'])->mapWithKeys(fn ($reason, $id): array => [(string) ((int) $id) => trim((string) $reason)])->all() : [];
                        $overallReason = trim((string) ($materialGemini['overall_reason'] ?? ''));
                        $sisaAnalysis = trim((string) ($materialGemini['sisa_analysis'] ?? ''));
                        $geminiAllocations = $this->extractGeminiPieceAllocations($materialGemini);

                        if (! in_array($aksi, ['full_booking', 'partial', 'full_pr'], true)) {
                            throw new \RuntimeException("Gemini mengembalikan aksi tidak valid '{$aksi}' untuk material_id {$materialId}.");
                        }
                        $allowedIds = collect($pieces)->pluck('id')->map(fn ($id): int => (int) $id)->all();
                        if (collect($selectedIds)->contains(fn (int $id): bool => ! in_array($id, $allowedIds, true))) {
                            throw new \RuntimeException("Gemini memilih piece yang tidak valid untuk material_id {$materialId}.");
                        }
                        if (collect($geminiAllocations)->contains(fn (array $alloc): bool => ! in_array((int) ($alloc['piece_id'] ?? 0), $allowedIds, true))) {
                            throw new \RuntimeException("Gemini mengembalikan alokasi piece_id yang tidak valid untuk material_id {$materialId}.");
                        }
                        if (in_array($aksi, ['full_booking', 'partial'], true) && empty($selectedIds) && empty($geminiAllocations)) {
                            throw new \RuntimeException("Gemini meminta booking tetapi selected_piece_ids kosong untuk material_id {$materialId}.");
                        }
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
                    $purchaseRecommendation = $this->buildPurchaseRecommendation(
                        project: $project,
                        material: $material,
                        qtyPr: $qtyPr,
                        geminiNode: is_array($materialGemini['purchase_recommendation'] ?? null) ? $materialGemini['purchase_recommendation'] : [],
                    );

                    $pieceById = collect($pieces)->keyBy(fn (array $piece): int => (int) ($piece['id'] ?? 0));

                    $segmentsForNeed = $this->normalizeRequestedSegmentsForTarget(
                        requestedSegments: (array) ($instruksiAi['requested_segments'] ?? []),
                        targetQty: $dibutuhkan,
                    );

                    $normalizedSegments = ! empty($segmentsForNeed)
                        ? $segmentsForNeed
                        : $this->normalizeRequestedSegmentsForTarget(
                            requestedSegments: (array) ($instruksiAi['requested_segments'] ?? []),
                            targetQty: $qtyBooking,
                        );

                    $shouldUsePureFallback = false;

                    if ((bool) ($instruksiAi['require_no_joint'] ?? false) && ! empty($segmentsForNeed)) {
                        $limitBooking = min($available, $dibutuhkan);
                        $segmentPlan = $this->pickSegmentSubsetWithinLimit($segmentsForNeed, $limitBooking);

                        if (! empty($segmentPlan)) {
                            $normalizedSegments = $segmentPlan;
                            $qtyBooking = round((float) array_sum($segmentPlan), 2);
                            $qtyPr = max(0, round($dibutuhkan - $qtyBooking, 2));
                            $aksi = $qtyPr > 0.00001 ? 'partial' : 'full_booking';
                        } else {
                            $shouldUsePureFallback = true;
                        }
                    }

                    if ((bool) ($instruksiAi['require_no_joint'] ?? false) && ! empty($normalizedSegments) && empty($geminiAllocations)) {
                        $shouldUsePureFallback = true;
                    }

                    ['chosen' => $chosen, 'remaining' => $remaining] = ! empty($geminiAllocations)
                        ? $this->buildChosenPiecesFromGeminiAllocations(
                            allocations: $geminiAllocations,
                            pieceById: $pieceById,
                            qtyBooking: $qtyBooking,
                            requireNoJoint: (bool) ($instruksiAi['require_no_joint'] ?? false),
                            requestedSegments: $normalizedSegments,
                        )
                        : $this->buildChosenPiecesFromSelection(selectedIds: $selectedIds, pieceById: $pieceById, dibutuhkan: $qtyBooking);

                    $requestedPieceCount = (int) ($instruksiAi['requested_piece_count'] ?? 0);
                    if (! (bool) ($instruksiAi['require_no_joint'] ?? false)
                        && $requestedPieceCount > 1
                        && empty($normalizedSegments)
                        && ! empty($chosen)
                    ) {
                        $chosen = $this->enforceRequestedPieceCount($chosen, $requestedPieceCount);
                        $pickedTotal = round((float) collect($chosen)->sum(fn (array $piece): float => (float) ($piece['take_qty'] ?? 0)), 2);
                        $remaining = max(0, round($qtyBooking - $pickedTotal, 2));
                    }

                    if (! (bool) ($instruksiAi['require_no_joint'] ?? false)
                        && $requestedPieceCount <= 0
                        && empty($normalizedSegments)
                        && ! empty($chosen)
                    ) {
                        $chosen = $this->rebalanceNonSegmentedChosenPieces($chosen);
                        $pickedTotal = round((float) collect($chosen)->sum(fn (array $piece): float => (float) ($piece['take_qty'] ?? 0)), 2);
                        $remaining = max(0, round($qtyBooking - $pickedTotal, 2));
                    }

                    if (! empty($geminiAllocations)) {
                        $allocReasonByPiece = collect($geminiAllocations)
                            ->mapWithKeys(function (array $alloc): array {
                                $pieceId = (int) ($alloc['piece_id'] ?? 0);
                                $reason = trim((string) ($alloc['reason'] ?? ''));
                                if ($pieceId <= 0 || $reason === '') {
                                    return [];
                                }

                                return [(string) $pieceId => $reason];
                            })
                            ->all();

                        if (! empty($allocReasonByPiece)) {
                            $alasanPerPiece = array_merge($alasanPerPiece, $allocReasonByPiece);
                        }
                    }

                    if ((bool) ($instruksiAi['require_no_joint'] ?? false) && ! empty($normalizedSegments) && empty($chosen)) {
                        $shouldUsePureFallback = true;
                    }

                    if ($qtyBooking > 0 && empty($chosen)) {
                        $shouldUsePureFallback = true;
                    }

                    if ($shouldUsePureFallback) {
                        $fallbackRows = $this->buildFallbackAnalysis(
                            $projectId,
                            [[
                                'material_id' => $materialId,
                                'qty' => $dibutuhkan,
                                'qty_original' => (float) ($item['qty_original'] ?? $dibutuhkan),
                            ]],
                            $stockData,
                            $instruksiAi,
                        );

                        $fallbackRow = (array) ($fallbackRows[0] ?? []);

                        if (empty($fallbackRow)) {
                            throw new \RuntimeException("Fallback tidak menghasilkan analisis untuk material_id {$materialId}.");
                        }

                        $hasilAnalisis[] = $fallbackRow;
                        $rekomendasiGemini[] = [
                            'material' => (string) ($fallbackRow['nama_material'] ?? $material->nama_material),
                            'aksi' => match ($fallbackRow['aksi'] ?? null) {
                                'full_booking' => 'booking',
                                'partial' => 'booking sebagian + PR',
                                'full_pr' => 'buat PR',
                                default => 'review manual',
                            },
                            'detail' => (string) ($fallbackRow['alasan_ai'] ?? ''),
                        ];

                        continue;
                    }

                    $perPieceReasonLabel = $material->usesDiscreteUnitTracking() ? 'Item' : 'Piece';
                    $perPieceReasonText = collect($chosen)
                        ->unique(fn (array $piece): int => (int) ($piece['id'] ?? 0))
                        ->map(function (array $piece) use ($alasanPerPiece, $perPieceReasonLabel): string {
                            $pieceId = (int) ($piece['id'] ?? 0);
                            $reason = trim((string) ($alasanPerPiece[(string) $pieceId] ?? ''));

                            return $reason !== '' ? ($perPieceReasonLabel.' #'.$pieceId.': '.$reason) : '';
                        })
                        ->filter()
                        ->unique()
                        ->implode('. ');
                    $geminiReason = implode('. ', array_values(array_filter([$overallReason, $perPieceReasonText, $sisaAnalysis], fn ($segment): bool => trim((string) $segment) !== '')));

                    if ($geminiReason === '') {
                        $fallbackRows = $this->buildFallbackAnalysis(
                            $projectId,
                            [[
                                'material_id' => $materialId,
                                'qty' => $dibutuhkan,
                                'qty_original' => (float) ($item['qty_original'] ?? $dibutuhkan),
                            ]],
                            $stockData,
                            $instruksiAi,
                        );

                        $fallbackRow = (array) ($fallbackRows[0] ?? []);

                        if (empty($fallbackRow)) {
                            throw new \RuntimeException("Fallback tidak menghasilkan analisis untuk material_id {$materialId}.");
                        }

                        $hasilAnalisis[] = $fallbackRow;
                        $rekomendasiGemini[] = [
                            'material' => (string) ($fallbackRow['nama_material'] ?? $material->nama_material),
                            'aksi' => match ($fallbackRow['aksi'] ?? null) {
                                'full_booking' => 'booking',
                                'partial' => 'booking sebagian + PR',
                                'full_pr' => 'buat PR',
                                default => 'review manual',
                            },
                            'detail' => (string) ($fallbackRow['alasan_ai'] ?? ''),
                        ];

                        continue;
                    }

                    if (empty($purchaseRecommendation)) {
                        $purchaseRecommendation = $this->buildPurchaseRecommendation(
                            project: $project,
                            material: $material,
                            qtyPr: $qtyPr,
                        );
                    }

                    $displayReason = $this->appendPurchaseRecommendationToReason(
                        $this->buildGeminiPrimarySyncedReason(
                        rawReason: $geminiReason,
                        item: [
                            'aksi' => $aksi,
                            'qty_pr' => $qtyPr,
                            'tanpa_potongan' => (bool) ($instruksiAi['tanpa_potongan'] ?? false),
                            'available' => $available,
                            'qty_booking' => $qtyBooking,
                        ],
                        materialName: (string) $material->nama_material,
                        satuan: $material->resolveTrackingUnit(),
                        dibutuhkan: $dibutuhkan,
                        qtyBooking: $qtyBooking,
                        qtyPr: $qtyPr,
                        chosenPieces: $chosen,
                        requestedSegments: $normalizedSegments,
                        requireNoJoint: (bool) ($instruksiAi['require_no_joint'] ?? false),
                        ),
                        $purchaseRecommendation,
                    );

                    $hasilAnalisis[] = ['material_id' => $materialId, 'nama_material' => (string) $material->nama_material, 'dibutuhkan' => $dibutuhkan, 'available' => round($available, 2), 'aksi' => $aksi, 'qty_booking' => round($qtyBooking, 2), 'qty_pr' => round($qtyPr, 2), 'tanpa_potongan' => (bool) ($instruksiAi['tanpa_potongan'] ?? false), 'recommended_piece_ids' => collect($chosen)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(), 'recommended_qty' => round($qtyBooking - max(0, $remaining), 2), 'recommended_pieces' => $chosen, 'alasan_ai' => $displayReason, 'alasan_ai_gemini' => $geminiReason, 'ai_reason_source' => 'gemini', 'purchase_recommendation' => $purchaseRecommendation, 'kebutuhan_asli' => (float) ($item['qty_original'] ?? $dibutuhkan), 'kebutuhan_setelah_buffer' => $dibutuhkan, 'kebutuhan_belum_tercover' => round($qtyPr, 2)];
                    $rekomendasiGemini[] = ['material' => (string) $material->nama_material, 'aksi' => match ($aksi) {
                        'full_booking' => 'booking', 'partial' => 'booking sebagian + PR', 'full_pr' => 'buat PR', default => 'review manual'
                    }, 'detail' => $displayReason];
                }
            }

            if (empty($hasilAnalisis)) {
                $this->sinkronTaskTanpaTambahan($projectId);

                return [];
            }

            $summary = json_encode(['ringkasan' => $ringkasanGemini, 'rekomendasi' => $rekomendasiGemini, 'perlu_konfirmasi' => true], JSON_UNESCAPED_UNICODE);
            if (! is_string($summary) || trim($summary) === '') {
                $summary = '{"ringkasan":"Silakan review rekomendasi booking/PR dari AI untuk project ini.","rekomendasi":[],"perlu_konfirmasi":true}';
            }
            $this->sinkronTaskUntukStaff($summary, $projectId, $hasilAnalisis, $instruksiAi, $triggerSource);

            return $hasilAnalisis;
        } finally {
            $lock->release();
        }
    }

    private function isTransientGeminiException(string $message): bool
    {
        $text = mb_strtolower(trim($message));
        if (str_contains($text, 'resource_exhausted') || str_contains($text, 'quota_exceeded') || str_contains($text, 'quotafailure') || str_contains($text, 'exceeded your current quota') || str_contains($text, 'billing')) {
            return false;
        }

        return str_contains($text, 'cooldown aktif') || str_contains($text, 'sementara tidak tersedia') || str_contains($text, 'high demand') || str_contains($text, '503');
    }

    private function isQuotaGeminiException(string $message): bool
    {
        $text = mb_strtolower(trim($message));

        return str_contains($text, 'quota')
            || str_contains($text, 'resource_exhausted')
            || str_contains($text, 'rate limited')
            || str_contains($text, 'limit terlampaui')
            || str_contains($text, 'billing');
    }

    private function isGeminiTransientErrorPayload(?array $decoded): bool
    {
        if (! is_array($decoded)) {
            return false;
        }
        $status = (int) ($decoded['status'] ?? 0);
        if ($status === 503) {
            return true;
        }
        if ($status === 429) {
            $bodyStr = mb_strtolower((string) json_encode($decoded['body'] ?? $decoded));
            if (str_contains($bodyStr, 'resource_exhausted') || str_contains($bodyStr, 'quota_exceeded') || str_contains($bodyStr, 'quotafailure') || str_contains($bodyStr, 'exceeded your current quota') || str_contains($bodyStr, 'billing')) {
                return false;
            }

            return true;
        }
        $errorText = mb_strtolower(trim((string) ($decoded['error'] ?? '')));

        return str_contains($errorText, 'cooldown aktif') || str_contains($errorText, 'sementara tidak tersedia') || str_contains($errorText, 'high demand');
    }

    private function buildProjectTimelineContext(?Project $project): array
    {
        $today = now()->toDateString();
        if (! $project || blank($project->tanggal_mulai)) {
            return [
                'today' => $today,
                'tanggal_mulai' => null,
                'h_minus_persiapan' => 0,
                'target_ready_date' => null,
            ];
        }

        $hMinus = max(0, (int) ($project->h_minus_persiapan ?? 0));
        $targetReadyDate = Carbon::parse($project->tanggal_mulai)->startOfDay()->subDays($hMinus);

        return [
            'today' => $today,
            'tanggal_mulai' => Carbon::parse($project->tanggal_mulai)->toDateString(),
            'h_minus_persiapan' => $hMinus,
            'target_ready_date' => $targetReadyDate->toDateString(),
        ];
    }

    private function resolveLeadTimeDays(?Material $material): int
    {
        $leadTime = (int) ($material?->defaultSupplier?->lead_time_hari ?? 0);

        return $leadTime > 0 ? $leadTime : 7;
    }

    private function buildPurchaseRecommendation(?Project $project, Material $material, float $qtyPr, array $geminiNode = []): array
    {
        $supplier = $material->relationLoaded('defaultSupplier')
            ? $material->defaultSupplier
            : $material->defaultSupplier()->first(['id', 'nama_supplier', 'lead_time_hari']);
        $leadTimeDays = $this->resolveLeadTimeDays($material->setRelation('defaultSupplier', $supplier));
        $targetReadyDate = $this->targetReadyDate($project);

        if ($qtyPr <= 0.00001) {
            return [
                'urgency' => 'tidak_perlu',
                'urgency_label' => 'Tidak perlu beli',
                'recommended_purchase_date' => null,
                'latest_order_date' => null,
                'target_ready_date' => $targetReadyDate?->toDateString(),
                'lead_time_hari' => $leadTimeDays,
                'supplier_name' => (string) ($supplier?->nama_supplier ?? 'Supplier belum diset'),
                'reason' => 'Stok yang bisa dibooking sudah menutup kebutuhan, jadi belum perlu pembelian tambahan untuk material ini.',
            ];
        }

        $today = now()->startOfDay();
        $readyDate = $targetReadyDate ?? $today->copy()->addDays($leadTimeDays + 7);
        $latestOrderDate = $readyDate->copy()->subDays($leadTimeDays)->startOfDay();
        $daysUntilLatestOrder = (int) $today->diffInDays($latestOrderDate, false);
        $urgency = $this->purchaseUrgencyFromDays($daysUntilLatestOrder);
        $recommendedPurchaseDate = $latestOrderDate->lessThanOrEqualTo($today) ? $today : $latestOrderDate;
        $supplierName = (string) ($supplier?->nama_supplier ?? 'Supplier belum diset');
        $aiReason = trim((string) ($geminiNode['reason'] ?? ''));
        $reason = $aiReason !== ''
            ? $aiReason
            : 'Ada kekurangan stok yang masuk PR. Dengan lead time supplier '.$leadTimeDays.' hari, order paling lambat '.$latestOrderDate->toDateString().' agar barang siap pada '.$readyDate->toDateString().'.';

        return [
            'urgency' => $urgency,
            'urgency_label' => $this->purchaseUrgencyLabel($urgency),
            'recommended_purchase_date' => $recommendedPurchaseDate->toDateString(),
            'latest_order_date' => $latestOrderDate->toDateString(),
            'target_ready_date' => $readyDate->toDateString(),
            'lead_time_hari' => $leadTimeDays,
            'supplier_name' => $supplierName,
            'days_until_latest_order' => $daysUntilLatestOrder,
            'reason' => $reason,
        ];
    }

    private function targetReadyDate(?Project $project): ?Carbon
    {
        if (! $project || blank($project->tanggal_mulai)) {
            return null;
        }

        return Carbon::parse($project->tanggal_mulai)
            ->startOfDay()
            ->subDays(max(0, (int) ($project->h_minus_persiapan ?? 0)));
    }

    private function purchaseUrgencyFromDays(int $daysUntilLatestOrder): string
    {
        if ($daysUntilLatestOrder <= 0) {
            return 'urgent';
        }

        if ($daysUntilLatestOrder <= 7) {
            return 'high';
        }

        if ($daysUntilLatestOrder <= 14) {
            return 'medium';
        }

        return 'low';
    }

    private function purchaseUrgencyLabel(string $urgency): string
    {
        return match ($urgency) {
            'urgent' => 'Urgent',
            'high' => 'Tinggi',
            'medium' => 'Sedang',
            'low' => 'Rendah',
            'tidak_perlu' => 'Tidak perlu beli',
            default => 'Review manual',
        };
    }

    private function appendPurchaseRecommendationToReason(string $reason, array $purchaseRecommendation): string
    {
        if (($purchaseRecommendation['urgency'] ?? '') === 'tidak_perlu') {
            return $reason;
        }

        $latestOrderDate = (string) ($purchaseRecommendation['latest_order_date'] ?? '');
        $recommendedPurchaseDate = (string) ($purchaseRecommendation['recommended_purchase_date'] ?? $latestOrderDate);
        $readyDate = (string) ($purchaseRecommendation['target_ready_date'] ?? '');
        $urgencyLabel = (string) ($purchaseRecommendation['urgency_label'] ?? 'Review manual');
        $supplierName = (string) ($purchaseRecommendation['supplier_name'] ?? 'Supplier belum diset');
        $leadTime = (int) ($purchaseRecommendation['lead_time_hari'] ?? 0);

        if ($latestOrderDate === '' || $readyDate === '') {
            return $reason;
        }

        $purchaseText = "Rekomendasi beli: {$urgencyLabel}, order ke {$supplierName} paling lambat {$latestOrderDate}";
        if ($recommendedPurchaseDate !== '' && $recommendedPurchaseDate !== $latestOrderDate) {
            $purchaseText .= " (disarankan beli {$recommendedPurchaseDate})";
        }
        $purchaseText .= " karena lead time {$leadTime} hari dan barang harus siap {$readyDate}.";

        return trim(rtrim($reason, '. ').'. '.$purchaseText);
    }

    private function formatPurchaseRecommendationForDisplay(array $purchaseRecommendation): string
    {
        if (empty($purchaseRecommendation)) {
            return '-';
        }

        if (($purchaseRecommendation['urgency'] ?? '') === 'tidak_perlu') {
            return (string) ($purchaseRecommendation['reason'] ?? 'Belum perlu pembelian tambahan.');
        }

        $urgencyLabel = (string) ($purchaseRecommendation['urgency_label'] ?? 'Review manual');
        $supplierName = (string) ($purchaseRecommendation['supplier_name'] ?? 'Supplier belum diset');
        $latestOrderDate = (string) ($purchaseRecommendation['latest_order_date'] ?? '-');
        $recommendedPurchaseDate = (string) ($purchaseRecommendation['recommended_purchase_date'] ?? $latestOrderDate);
        $readyDate = (string) ($purchaseRecommendation['target_ready_date'] ?? '-');
        $leadTime = (int) ($purchaseRecommendation['lead_time_hari'] ?? 0);

        $line = "Urgensi: {$urgencyLabel}\nSupplier: {$supplierName}\nBeli paling lambat: {$latestOrderDate}";
        if ($recommendedPurchaseDate !== '' && $recommendedPurchaseDate !== $latestOrderDate) {
            $line .= "\nDisarankan beli: {$recommendedPurchaseDate}";
        }
        $line .= "\nLead time: {$leadTime} hari\nBarang harus siap: {$readyDate}";

        return $line;
    }

    private function prepareStockDataForGemini(array $kebutuhanDelta): array
    {
        $rackMap = ShelfLocation::query()->get(['id', 'kode_lokasi', 'nama_lokasi'])->mapWithKeys(fn ($row): array => [(int) $row->id => (string) ($row->kode_lokasi ?: ($row->nama_lokasi ?: ('#'.(int) $row->id)))])->toArray();
        $result = [];
        foreach ($kebutuhanDelta as $item) {
            $materialId = (int) ($item['material_id'] ?? 0);
            $dibutuhkan = (float) ($item['qty'] ?? 0);
            if ($materialId <= 0 || $dibutuhkan <= 0) {
                continue;
            }
            $material = Material::query()->with('defaultSupplier:id,nama_supplier,lead_time_hari')->find($materialId);
            if (! $material) {
                continue;
            }
            $pieces = MaterialPiece::query()->with('inbound:id,entry_date')->where('material_id', $materialId)->where('status', 'available')->orderByRaw("CASE WHEN sumber LIKE 'split_%' THEN 0 ELSE 1 END ASC")->orderBy('panjang')->orderByRaw('COALESCE((SELECT entry_date FROM inbounds WHERE inbounds.id = material_pieces.inbound_id), material_pieces.created_at) ASC')->get(['id', 'material_id', 'inbound_id', 'panjang', 'sumber', 'shelf_location_id', 'created_at'])->map(function (MaterialPiece $piece) use ($rackMap): array {
                $ageDays = (int) now()->diffInDays($piece->inbound?->entry_date ? Carbon::parse($piece->inbound->entry_date) : $piece->created_at, false) * -1;

                return ['id' => (int) $piece->id, 'panjang' => round((float) $piece->panjang, 2), 'sumber' => (string) $piece->sumber, 'rack_label' => (string) ($rackMap[(int) ($piece->shelf_location_id ?? 0)] ?? '-'), 'umur_hari' => max(0, $ageDays), 'shelf_location_id' => (int) ($piece->shelf_location_id ?? 0), 'status' => 'available'];
            })->values()->all();
            $supplier = $material->defaultSupplier;
            $leadTimeDays = $this->resolveLeadTimeDays($material);
            $result[$materialId] = ['material_id' => $materialId, 'material_name' => (string) $material->nama_material, 'dibutuhkan' => round($dibutuhkan, 2), 'satuan' => $material->resolveTrackingUnit(), 'available' => round((float) $material->available, 2), 'supplier' => ['id' => (int) ($supplier?->id ?? 0), 'name' => (string) ($supplier?->nama_supplier ?? 'Supplier belum diset'), 'lead_time_hari' => $leadTimeDays], 'pieces' => $pieces];
        }

        return $result;
    }

    private function prepareHistoricalContext(array $kebutuhanDelta): array
    {
        $history = [];
        foreach ($kebutuhanDelta as $item) {
            $materialId = (int) ($item['material_id'] ?? 0);
            if ($materialId <= 0) {
                continue;
            }
            $material = Material::query()->find($materialId);
            if (! $material) {
                continue;
            }
            $avgBookingPerProject = (float) MaterialPiece::query()->where('material_id', $materialId)->where('status', 'booked')->where('booked_at', '>=', now()->subMonths(3))->selectRaw('booked_for_project_id, SUM(panjang) as total')->groupBy('booked_for_project_id')->pluck('total')->avg();
            $deadStockPieces = (int) MaterialPiece::query()->where('material_id', $materialId)->where('status', 'available')->where('created_at', '<=', now()->subDays(90))->count();
            $usageBySource = MaterialPiece::query()->where('material_id', $materialId)->whereIn('status', ['booked', 'used'])->where('booked_at', '>=', now()->subMonths(3))->selectRaw('sumber, COUNT(*) as jumlah')->groupBy('sumber')->pluck('jumlah', 'sumber')->toArray();
            $wastedCuts = (int) MaterialPiece::query()->where('material_id', $materialId)->where('status', 'available')->whereIn('sumber', ['split_auto_allocation', 'split_booking', 'split_booking_residue'])->where('panjang', '<', 20)->where('created_at', '<=', now()->subDays(60))->count();
            $projectCount = (int) MaterialPiece::query()->where('material_id', $materialId)->whereIn('status', ['booked', 'used'])->where('booked_at', '>=', now()->subMonths(3))->distinct('booked_for_project_id')->count('booked_for_project_id');
            $history[$materialId] = ['material_name' => (string) $material->nama_material, 'avg_booking_per_project' => round($avgBookingPerProject, 2), 'dead_stock_pieces' => $deadStockPieces, 'usage_by_source' => $usageBySource, 'wasted_small_cuts' => $wastedCuts, 'projects_using_3months' => $projectCount];
            if (! ($projectCount > 0 || (float) $avgBookingPerProject > 0 || ! empty($usageBySource))) {
                unset($history[$materialId]);
            }
        }

        return $history;
    }

    private function askGeminiFullAnalysis(int $projectId, string $projectCode, string $catatanTeknis, array $kebutuhanItems, array $materialsStock, array $projectTimeline = [], array $historicalContext = []): array
    {
        $dataset = collect($kebutuhanItems)->map(function (array $item) use ($materialsStock): array {
            $materialId = (int) ($item['material_id'] ?? 0);
            $stock = (array) ($materialsStock[$materialId] ?? []);
            $pieces = collect((array) ($stock['pieces'] ?? []))
                ->sortBy(fn (array $piece): int => (int) ($piece['umur_hari'] ?? 0))
                ->values()
                ->map(function (array $piece): array {
                    return [
                        'id' => (int) ($piece['id'] ?? 0),
                        'panjang' => round((float) ($piece['panjang'] ?? 0), 2),
                        'umur_hari' => (int) ($piece['umur_hari'] ?? 0),
                        'sumber' => (string) ($piece['sumber'] ?? '-'),
                        'rack_label' => (string) ($piece['rack_label'] ?? '-'),
                        'status' => (string) ($piece['status'] ?? 'available'),
                    ];
                })
                ->all();

            $supplier = (array) ($stock['supplier'] ?? []);

            return [
                'material_id' => $materialId,
                'material_name' => (string) ($item['material_name'] ?? ('Material #'.$materialId)),
                'dibutuhkan' => round((float) ($item['qty'] ?? 0), 2),
                'satuan' => (string) ($item['satuan'] ?? 'unit'),
                'available' => round((float) ($stock['available'] ?? 0), 2),
                'supplier' => [
                    'name' => (string) ($supplier['name'] ?? 'Supplier belum diset'),
                    'lead_time_hari' => max(0, (int) ($supplier['lead_time_hari'] ?? 7)),
                ],
                'pieces' => $pieces,
            ];
        })->filter(fn (array $row): bool => (int) ($row['material_id'] ?? 0) > 0 && (float) ($row['dibutuhkan'] ?? 0) > 0)->values()->all();

        if (empty($dataset)) {
            throw new \RuntimeException('Tidak ada material kebutuhan valid untuk dikirim ke Gemini.');
        }
        $requiredMaterialIds = collect($dataset)->pluck('material_id')->map(fn ($id): string => (string) ((int) $id))->unique()->values()->all();
        $dataJson = json_encode($dataset, JSON_UNESCAPED_UNICODE);
        if (! is_string($dataJson) || $dataJson === '') {
            throw new \RuntimeException('Gagal menyiapkan payload full analysis untuk Gemini.');
        }

        $catatanPrompt = trim($catatanTeknis) !== '' ? trim($catatanTeknis) : '(kosong - tidak ada instruksi khusus)';
        $timelineJson = json_encode($projectTimeline, JSON_UNESCAPED_UNICODE);
        if (! is_string($timelineJson) || $timelineJson === '') {
            $timelineJson = '{}';
        }
        $historyCompact = collect($historicalContext)
            ->mapWithKeys(function (array $row, $materialId): array {
                return [
                    (string) ((int) $materialId) => [
                        'avg_booking_per_project' => round((float) ($row['avg_booking_per_project'] ?? 0), 2),
                        'dead_stock_pieces' => (int) ($row['dead_stock_pieces'] ?? 0),
                        'usage_by_source' => (array) ($row['usage_by_source'] ?? []),
                        'wasted_small_cuts' => (int) ($row['wasted_small_cuts'] ?? 0),
                        'projects_using_3months' => (int) ($row['projects_using_3months'] ?? 0),
                    ],
                ];
            })
            ->all();
        $historyJson = json_encode($historyCompact, JSON_UNESCAPED_UNICODE);
        if (! is_string($historyJson) || $historyJson === '') {
            $historyJson = '{}';
        }

        $systemInstruction = 'Kamu adalah Inventory Math Expert. Balas hanya JSON valid tunggal. Aksi yang diizinkan: full_booking|partial|full_pr. ATURAN KETAT: (1) Jika manager meminta segmen tertentu (contoh 100,100,105), kamu WAJIB membagi alokasi_kabel per segmen sebagai entry terpisah, termasuk bila 2 segmen diambil dari piece yang sama. (2) ambil_panjang adalah panjang POTONGAN yang diambil, bukan total panjang piece. (3) Hitung sisa secara akurat, jangan halusinasi angka. (4) Gunakan FIFO sebagai prioritas saat memungkinkan. (5) Untuk full_booking/partial WAJIB isi alokasi_kabel berisi piece_id + ambil_panjang numerik. selected_piece_ids boleh tetap dikirim sebagai ringkasan. 
        
        (6) Jika manager menyebut ukuran-ukuran spesifik (contoh "100 100 100" atau "bikin 3 potongan 100m"), OTOMATIS set require_no_joint = true dan requested_segments diisi dengan ukuran-ukuran tersebut. Manager tidak perlu tulis "jangan sambung" secara eksplisit — kalau ada angka-angka ukuran, itu sudah berarti minta potongan terpisah.';
        $prompt = <<<PROMPT
Proyek: {$projectCode} (ID {$projectId})
Instruksi manager: {$catatanPrompt}
Timeline proyek: {$timelineJson}
Data material: {$dataJson}
Histori ringkas: {$historyJson}

Prioritas pemilihan piece: kepatuhan instruksi -> ambil piece SESEDIKIT MUNGKIN (hindari potong kalau bisa ambil utuh) -> FIFO (umur lebih lama) -> minimalkan sisa tidak terpakai.

ATURAN PENTING kalau tidak ada instruksi segmen:
- Cari kombinasi piece yang TEPAT atau LEBIH BESAR dari kebutuhan dengan JUMLAH PIECE PALING SEDIKIT
- Kalau ada 1 piece yang cukup → ambil 1 piece saja
- Kalau ada 2 piece yang totalnya pas → ambil 2 piece, jangan 3
- Jangan potong piece kalau bisa kombinasi piece lain yang hasilnya sama
- Sisa potongan kecil itu lebih buruk dari mengambil 1 piece besar
- Jangan pakai piece ID yang tidak ada pada Data material.
- Jika instruksi segmen adalah 100,100,105 maka alokasi_kabel wajib memiliki 3 baris take: 100, 100, 105 (tidak boleh digabung jadi 200 + 105).
- Untuk material dengan qty PR > 0, gunakan supplier.lead_time_hari dan Timeline proyek untuk memberi purchase_recommendation: tanggal beli paling lambat = target_ready_date dikurangi lead_time_hari; urgency = urgent kalau tanggal beli sudah lewat/hari ini, high kalau sisa <= 7 hari, medium kalau sisa <= 14 hari, low kalau lebih lama.
- Jelaskan rekomendasi beli di overall_reason/sisa_analysis: kapan harus beli, lead time supplier berapa hari, dan tingkat urgensinya. Kalau qty PR = 0, purchase_recommendation boleh berstatus "tidak_perlu".


Output schema:
    {"instruksi_manager":{"tanpa_potongan":false,"require_no_joint":false,"requested_segments":[100,100,100],"ringkas":"...","alasan":"..."},"ringkasan":"...","materials":{"<material_id>":{"aksi":"full_booking|partial|full_pr","selected_piece_ids":[1],"alokasi_kabel":[{"piece_id":1,"ambil_panjang":100,"alasan":"..."}],"alasan_per_piece":{"1":"..."},"overall_reason":"...","sisa_analysis":"...","purchase_recommendation":{"urgency":"urgent|high|medium|low|tidak_perlu","recommended_purchase_date":"YYYY-MM-DD|null","latest_order_date":"YYYY-MM-DD|null","lead_time_hari":7,"reason":"..."}}}}
PROMPT;

        Log::info('Gemini full analysis - prompt dikirim', [
            'project_id' => $projectId,
            'materials_count' => count($dataset),
            'materials_ids' => $requiredMaterialIds,
        ]);

        $responseRaw = (string) $this->gemini->ask($prompt, $systemInstruction);
        $responseClean = $this->extractJsonObjectFromText($responseRaw);

        Log::info('Gemini full analysis - raw response', [
            'project_id' => $projectId,
            'response_length' => strlen($responseRaw),
            'response_preview' => mb_substr($responseClean, 0, 1200),
        ]);

        $decoded = json_decode($responseClean, true);
        if (is_array($decoded)) {
            $decoded = $this->normalizeGeminiFullAnalysisResponse($decoded);
        }

        if ($this->isGeminiTransientErrorPayload($decoded)) {
            $retrySeconds = $this->extractGeminiRetrySeconds($decoded);
            throw new \RuntimeException($retrySeconds > 0 ? ('Gemini cooldown aktif '.$retrySeconds.' detik (rate limit/high demand).') : 'Gemini sementara tidak tersedia (rate limit/high demand).');
        }

        if (is_array($decoded) && isset($decoded['status'])) {
            $bodyStr = mb_strtolower((string) json_encode($decoded));
            if (in_array((int) ($decoded['status'] ?? 0), [429, 503], true) && (str_contains($bodyStr, 'resource_exhausted') || str_contains($bodyStr, 'quota') || str_contains($bodyStr, 'billing'))) {
                throw new \RuntimeException('Gemini quota habis: '.($decoded['error'] ?? 'RESOURCE_EXHAUSTED'));
            }
        }

        $decodedIsMalformed = ! is_array($decoded);
        $decodedIsInvalidSchema = is_array($decoded) && ! $this->isValidGeminiFullAnalysisResponse($decoded, $requiredMaterialIds);
        $allowConfiguredCompactRetry = (bool) config('services.gemini.enable_compact_retry_prompt', false);

        // Always force one compact retry when Gemini output is malformed/truncated JSON.
        if (($decodedIsMalformed || $decodedIsInvalidSchema) && ($allowConfiguredCompactRetry || $decodedIsMalformed)) {
            $compactPrompt = "Proyek: {$projectCode} (ID {$projectId}). Instruksi manager: {$catatanPrompt}. Timeline proyek: {$timelineJson}. Data material: {$dataJson}. Histori ringkas: {$historyJson}. Balas JSON sesuai schema dan WAJIB memuat semua material_id pada data material, termasuk purchase_recommendation untuk item PR.";
            Log::warning('Gemini response belum valid, retry prompt ringkas.', ['project_id' => $projectId]);

            $retryRaw = (string) $this->gemini->ask($compactPrompt, $systemInstruction);
            $retryClean = $this->extractJsonObjectFromText($retryRaw);
            $retryDecoded = json_decode($retryClean, true);
            if (is_array($retryDecoded)) {
                $retryDecoded = $this->normalizeGeminiFullAnalysisResponse($retryDecoded);
            }

            if ($this->isGeminiTransientErrorPayload(is_array($retryDecoded) ? $retryDecoded : null)) {
                throw new \RuntimeException('Gemini sementara tidak tersedia saat retry.');
            }

            $decoded = is_array($retryDecoded) ? $retryDecoded : $decoded;
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException('Response Gemini full analysis bukan JSON valid.');
        }

        if (! $this->isValidGeminiFullAnalysisResponse($decoded, $requiredMaterialIds)) {
            throw new \RuntimeException('Response Gemini tidak memiliki key materials yang valid.');
        }

        return $decoded;
    }

    private function extractJsonObjectFromText(string $responseRaw): string
    {
        $clean = trim((string) str_replace(['```json', '```'], '', $responseRaw));
        $firstBrace = strpos($clean, '{');
        $lastBrace = strrpos($clean, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            return substr($clean, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        return $clean;
    }

    private function normalizeGeminiFullAnalysisResponse(array $decoded): array
    {
        $materials = $decoded['materials'] ?? null;
        if (is_array($materials) && array_is_list($materials)) {
            $mapped = [];
            foreach ($materials as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $materialId = (string) ((int) ($node['material_id'] ?? 0));
                if ($materialId === '0') {
                    continue;
                }

                $mapped[$materialId] = $node;
            }

            $decoded['materials'] = $mapped;
        }

        return $decoded;
    }

    private function isValidGeminiFullAnalysisResponse(?array $decoded, array $requiredMaterialIds): bool
    {
        if (! is_array($decoded)) {
            return false;
        }
        $materials = $decoded['materials'] ?? null;
        if (! is_array($materials)) {
            return false;
        }
        foreach ($requiredMaterialIds as $materialId) {
            $node = $materials[$materialId] ?? null;
            if (! is_array($node)) {
                return false;
            }
            if (! in_array((string) ($node['aksi'] ?? ''), ['full_booking', 'partial', 'full_pr'], true)) {
                return false;
            }
        }

        return true;
    }

    private function extractGeminiRetrySeconds(?array $decoded): int
    {
        if (! is_array($decoded)) {
            return 0;
        }
        $retryAfter = (int) ($decoded['retry_after_seconds'] ?? 0);
        if ($retryAfter > 0) {
            return $retryAfter;
        }
        $retryDelay = data_get($decoded, 'body.error.details.2.retryDelay') ?? data_get($decoded, 'error.details.2.retryDelay') ?? data_get($decoded, 'retryDelay');
        if (is_string($retryDelay) && preg_match('/(\d+)/', $retryDelay, $match)) {
            return max(0, (int) $match[1]);
        }

        return 0;
    }

    private function analisaSatuItem(Material $material, float $dibutuhkan, int $projectId, bool $tanpaPotongan = false, string $alasanInstruksi = ''): array
    {
        $applyNoCut = $tanpaPotongan && $this->isNoCutRelevantMaterial($material);
        if ($applyNoCut) {
            $rollStandar = (float) ($material->default_roll_length ?? 0);
            $minimumUtuh = $rollStandar > 0 ? $rollStandar : $dibutuhkan;
            $stokTersediaFaktual = (float) $material->available;
            $stokSisaPotonganPieces = MaterialPiece::query()->where('material_id', $material->id)->where('status', 'available')->where('panjang', '<', $minimumUtuh)->orderByDesc('panjang')->pluck('panjang')->map(fn ($value) => (float) $value)->values()->all();
            $stokSisaPotongan = array_sum($stokSisaPotonganPieces);
            $stokSisaPotonganList = collect($stokSisaPotonganPieces)->take(6)->map(fn (float $value) => $this->formatQty($value))->values()->all();
            $potonganBreakdown = empty($stokSisaPotonganList) ? '' : 'potongan tersedia '.implode(' + ', $stokSisaPotonganList);
            $stokUtuhCukup = MaterialPiece::query()->where('material_id', $material->id)->where('status', 'available')->whereIn('sumber', ['inbound_baru', 'opening_balance'])->where('panjang', '>=', $minimumUtuh)->exists();

            return ['material_id' => $material->id, 'nama_material' => $material->nama_material, 'dibutuhkan' => $dibutuhkan, 'available' => round($stokTersediaFaktual, 2), 'aksi' => $stokUtuhCukup ? 'full_booking' : 'full_pr', 'qty_booking' => $stokUtuhCukup ? $dibutuhkan : 0, 'qty_pr' => $stokUtuhCukup ? 0 : $dibutuhkan, 'tanpa_potongan' => true, 'stok_sisa_potongan' => $stokSisaPotongan, 'stok_sisa_potongan_list' => $stokSisaPotonganList, 'alasan_ai' => $stokUtuhCukup ? ('instruksi tidak boleh potongan, jadi sistem hanya memakai roll utuh. '.trim($alasanInstruksi)) : ('instruksi tidak boleh potongan dan stok roll utuh tidak mencukupi'.($stokSisaPotongan > 0 ? ', stok sisa '.$this->formatQty($stokSisaPotongan).' tidak bisa dipakai' : '').($potonganBreakdown !== '' ? ', '.$potonganBreakdown.' tidak bisa dipakai' : '').', sehingga kebutuhan dialihkan ke PR. '.trim($alasanInstruksi))];
        }
        $available = $material->available;
        if ($available >= $dibutuhkan) {
            return ['material_id' => $material->id, 'nama_material' => $material->nama_material, 'dibutuhkan' => $dibutuhkan, 'available' => $available, 'aksi' => 'full_booking', 'qty_booking' => $dibutuhkan, 'qty_pr' => 0, 'tanpa_potongan' => false, 'alasan_ai' => 'Karena stok tersedia mencukupi, kebutuhan dipenuhi dengan booking penuh tanpa PR.'];
        }
        if ($available > 0) {
            return ['material_id' => $material->id, 'nama_material' => $material->nama_material, 'dibutuhkan' => $dibutuhkan, 'available' => $available, 'aksi' => 'partial', 'qty_booking' => $available, 'qty_pr' => $dibutuhkan - $available, 'tanpa_potongan' => false, 'alasan_ai' => 'Karena stok hanya mencukupi sebagian, sistem booking sesuai stok dan sisanya dibuat PR.'];
        }

        return ['material_id' => $material->id, 'nama_material' => $material->nama_material, 'dibutuhkan' => $dibutuhkan, 'available' => 0, 'aksi' => 'full_pr', 'qty_booking' => 0, 'qty_pr' => $dibutuhkan, 'tanpa_potongan' => false, 'alasan_ai' => 'Karena stok kosong, seluruh kebutuhan material dibuatkan PR.'];
    }

    private function parseInstruksiOperasionalDariCatatan(string $catatanTeknis, int $projectId): array
    {
        $text = trim($catatanTeknis);
        unset($projectId);
        if ($text === '') {
            return ['tanpa_potongan' => false, 'require_no_joint' => false, 'requested_segments' => [], 'requested_piece_count' => 0, 'ringkas' => 'Tidak ada instruksi khusus.', 'alasan' => '', 'sumber' => 'fallback_empty', 'confidence' => 'rendah', 'preferensi_sumber' => 'auto', 'preferensi_umur_stok' => 'lama', 'preferred_piece_ids' => [], 'preferred_rack_labels' => [], 'preferred_rack_ids' => []];
        }

        $allowCut = (bool) preg_match('/boleh\s*potong|boleh\s*potongan|potong\s*aja|pakai\s*potongan|gunakan\s*potongan/i', $text);
        $fallbackNoCut = (bool) preg_match('/jangan[^\n\r]{0,40}potong|jgn[^\n\r]{0,40}potong|tanpa\s*potong|tnp\s*potong|harus\s*utuh|wajib\s*utuh|roll\s*utuh|full\s*roll|no\s*cut|no\s*splice|tidak\s*boleh\s*sambung|ga\s*boleh\s*sambung|nggak\s*boleh\s*sambung|utuh/i', $text);
        $requireNoJoint = (bool) preg_match('/tanpa\s*sambung|tidak\s*boleh\s*sambung|jangan\s*sambung|no\s*splice|tanpa\s*splice/i', $text);
        $requestedSegments = $this->extractRequestedSegmentsFromText($text);
        $requestedPieceCount = 0;
        if (preg_match('/\b(?:jadi\s*)?(\d+)\s*(?:pcs|piece|potongan)\b/i', $text, $pieceCountMatch)) {
            $requestedPieceCount = max(0, (int) ($pieceCountMatch[1] ?? 0));
        }
        $looksLikeSegmentDemand = ! empty($requestedSegments)
            && (
                (bool) preg_match('/\b(\d+)\s*(pcs|piece|potongan)\b|\bjadi\s*\d+\s*(pcs|piece|potongan)\b|\bpotongan\b|\bbikin\b|\bbuat\b|\bpotong\b|\bcut\b|\bambil\b/i', $text)
                || count($requestedSegments) >= 2
            );

        $hasReliableSegmentSpec = ! empty($requestedSegments)
            && ! ($requestedPieceCount > 1 && count($requestedSegments) <= 1);

        if (! $hasReliableSegmentSpec) {
            $requestedSegments = [];
        }

        if (! $requireNoJoint && $looksLikeSegmentDemand && $hasReliableSegmentSpec) {
            $requireNoJoint = true;
        }

        preg_match_all('/(?:piece|id)\s*#?\s*(\d+)/i', $text, $pieceMatches);
        $preferredPieceIds = collect($pieceMatches[1] ?? [])->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();

        preg_match_all('/rak\s*([a-z0-9\-]+)/i', $text, $rackMatches);
        $preferredRackLabels = collect($rackMatches[1] ?? [])->map(fn ($label): string => strtoupper(trim((string) $label)))->filter()->unique()->values()->all();
        $preferredRackIds = [];
        if (! empty($preferredRackLabels)) {
            $preferredRackIds = ShelfLocation::query()->get(['id', 'kode_lokasi'])
                ->filter(fn ($row): bool => in_array(strtoupper(trim((string) ($row->kode_lokasi ?? ''))), $preferredRackLabels, true))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        $preferensiSumber = 'auto';
        if ((bool) preg_match('/(pakai|gunakan|prioritas|utamakan)[^\n\r]{0,40}(potongan|sisa)/i', $text)) {
            $preferensiSumber = 'potongan';
        } elseif ((bool) preg_match('/(pakai|gunakan|prioritas|utamakan)[^\n\r]{0,50}(roll\s*baru|inbound\s*baru|barang\s*baru|stok\s*baru|utuh\s*baru)/i', $text)) {
            $preferensiSumber = 'baru';
        }

        $preferensiUmur = 'lama';
        if ((bool) preg_match('/(yang\s*)?paling\s*baru|baru\s*datang|lifo|terbaru/i', $text)) {
            $preferensiUmur = 'baru';
        } elseif ((bool) preg_match('/(yang\s*)?paling\s*lama|stok\s*lama|fifo|terlama/i', $text)) {
            $preferensiUmur = 'lama';
        }

        return [
            'tanpa_potongan' => $allowCut ? false : $fallbackNoCut,
            'require_no_joint' => $requireNoJoint,
            'requested_segments' => $requestedSegments,
            'requested_piece_count' => $requestedPieceCount,
            'ringkas' => $allowCut
                ? 'Fallback regex: catatan mengizinkan penggunaan potongan dengan preferensi manager.'
                : ($fallbackNoCut
                    ? 'Fallback regex: terdeteksi instruksi tanpa potongan + preferensi manager.'
                    : 'Fallback regex: gunakan preferensi manager untuk pemilihan stok.'),
            'alasan' => 'Fallback lokal saat classifier AI tidak memberi output terstruktur.',
            'sumber' => $allowCut ? 'fallback_regex_allow_cut' : 'fallback_regex',
            'confidence' => $allowCut ? 'sedang' : 'rendah',
            'preferensi_sumber' => $preferensiSumber,
            'preferensi_umur_stok' => $preferensiUmur,
            'preferred_piece_ids' => $preferredPieceIds,
            'preferred_rack_labels' => $preferredRackLabels,
            'preferred_rack_ids' => $preferredRackIds,
        ];
    }

    private function sinkronTaskUntukStaff(string $summaryJson, int $projectId, array $detail, array $instruksiAi = [], string $triggerSource = 'system_sync'): void
    {
        $decoded = json_decode($summaryJson, true);
        $project = Project::query()->find($projectId, ['id', 'kode_proyek', 'catatan_teknis']);
        $projectCode = (string) ($project?->kode_proyek ?: "#{$projectId}");
        $projectSpecialInstruction = trim((string) ($project?->catatan_teknis ?? ''));
        if (! is_array($decoded)) {
            $decoded = ['ringkasan' => 'AI summary tidak valid, gunakan hasil analisis detail.', 'rekomendasi' => [], 'perlu_konfirmasi' => true];
        }
        $decoded['rekomendasi'] = $this->normalisasiRekomendasi($decoded['rekomendasi'] ?? [], $detail);
        if (blank($decoded['ringkasan'] ?? null)) {
            $decoded['ringkasan'] = 'Silakan review rekomendasi booking/PR dari AI untuk project ini.';
        }

        $payload = ['project_id' => $projectId, 'project_code' => $projectCode, 'project_special_instruction' => $projectSpecialInstruction, 'detail' => $detail, 'items' => $this->buildPerMaterialItemsPayload($detail), 'ai_summary' => $decoded, 'ai_instruction' => ['tanpa_potongan' => (bool) ($instruksiAi['tanpa_potongan'] ?? false), 'require_no_joint' => (bool) ($instruksiAi['require_no_joint'] ?? false), 'requested_segments' => collect($instruksiAi['requested_segments'] ?? [])->map(fn ($value): float => round((float) $value, 2))->filter(fn (float $value): bool => $value > 0)->values()->all(), 'requested_piece_count' => max(0, (int) ($instruksiAi['requested_piece_count'] ?? 0)), 'ringkas' => (string) ($instruksiAi['ringkas'] ?? ''), 'alasan' => (string) ($instruksiAi['alasan'] ?? ''), 'sumber' => (string) ($instruksiAi['sumber'] ?? 'unknown'), 'confidence' => (string) ($instruksiAi['confidence'] ?? 'rendah'), 'preferensi_sumber' => (string) ($instruksiAi['preferensi_sumber'] ?? 'auto'), 'preferensi_umur_stok' => (string) ($instruksiAi['preferensi_umur_stok'] ?? 'lama'), 'preferred_piece_ids' => collect($instruksiAi['preferred_piece_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->values()->all(), 'preferred_rack_labels' => collect($instruksiAi['preferred_rack_labels'] ?? [])->map(fn ($label): string => strtoupper(trim((string) $label)))->filter()->values()->all()], 'ai_trigger_source' => $triggerSource, 'approval_trail' => ['staff' => null, 'manager' => null, 'finance' => null], 'last_synced_at' => now()->toDateTimeString()];

        $taskPending = Task::query()->where('type', 'ai_booking_suggestion')->where('related_type', 'project')->where('related_id', $projectId)->where('status', 'pending')->orderByDesc('id')->get();
        $taskTarget = $taskPending->first();
        if ($taskPending->count() > 1) {
            $taskPending->slice(1)->each(function (Task $task) {
                $p = $task->payload ?? [];
                data_set($p, 'superseded', true);
                data_set($p, 'superseded_at', now()->toDateTimeString());
                $task->update(['status' => 'done', 'handled_at' => now(), 'message' => 'Task ini digantikan oleh task AI terbaru.', 'payload' => $p]);
            });
        }
        if ($taskTarget) {
            $taskTarget->update(['title' => "AI: Konfirmasi Kebutuhan Material Proyek {$projectCode}", 'message' => $decoded['ringkasan'] ?? 'AI menyarankan booking/PR untuk proyek baru', 'payload' => $payload]);
            Log::info("AI task di-update untuk Project #{$projectId}");

            return;
        }
        Task::create(['for_role' => 'Staff Administrasi Gudang', 'type' => 'ai_booking_suggestion', 'title' => "AI: Konfirmasi Kebutuhan Material Proyek {$projectCode}", 'message' => $decoded['ringkasan'] ?? 'AI menyarankan booking/PR untuk proyek baru', 'payload' => $payload, 'status' => 'pending', 'related_id' => $projectId, 'related_type' => 'project']);
        Log::info("AI task dibuat untuk Project #{$projectId}");
    }

    private function sinkronTaskSedangDiproses(int $projectId, string $projectCode, string $catatanTeknis, ?string $message = null, string $triggerSource = 'system_sync'): void
    {
        $ringkasan = trim((string) $message);
        if ($ringkasan === '') {
            $ringkasan = 'AI sedang memproses kebutuhan terbaru project. Mohon tunggu sebentar, task akan ter-update otomatis.';
        }

        $taskPending = Task::query()
            ->where('type', 'ai_booking_suggestion')
            ->where('related_type', 'project')
            ->where('related_id', $projectId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        $basePayload = [
            'project_id' => $projectId,
            'project_code' => $projectCode,
            'project_special_instruction' => trim($catatanTeknis),
            'ai_trigger_source' => $triggerSource,
            'detail' => [],
            'items' => [],
            'ai_summary' => [
                'ringkasan' => $ringkasan,
                'rekomendasi' => [],
                'perlu_konfirmasi' => true,
            ],
            'last_synced_at' => now()->toDateTimeString(),
        ];

        if ($taskPending) {
            $payload = is_array($taskPending->payload) ? $taskPending->payload : [];
            $payload = array_merge($payload, $basePayload);
            $taskPending->update([
                'title' => "AI: Konfirmasi Kebutuhan Material Proyek {$projectCode}",
                'message' => $ringkasan,
                'payload' => $payload,
            ]);

            return;
        }

        Task::create([
            'for_role' => 'Staff Administrasi Gudang',
            'type' => 'ai_booking_suggestion',
            'title' => "AI: Konfirmasi Kebutuhan Material Proyek {$projectCode}",
            'message' => $ringkasan,
            'payload' => $basePayload,
            'status' => 'pending',
            'related_id' => $projectId,
            'related_type' => 'project',
        ]);
    }

    private function buildPerMaterialItemsPayload(array $detail): array
    {
        return collect($detail)->map(function (array $row): array {
            $materialId = (int) ($row['material_id'] ?? 0);
            $dibutuhkan = (float) ($row['dibutuhkan'] ?? 0);
            $material = $materialId > 0 ? Material::query()->find($materialId) : null;
            $satuan = (string) ($material?->resolveTrackingUnit() ?: 'unit');
            $recommendedPieces = is_array($row['recommended_pieces'] ?? null) ? array_values($row['recommended_pieces']) : [];
            $reason = trim((string) ($row['alasan_ai'] ?? ''));

            if ($reason === '') {
                $reason = $this->buildSystemReasonFromPieces(
                    materialName: (string) ($row['nama_material'] ?? ('Material #'.$materialId)),
                    satuan: $satuan,
                    dibutuhkan: $dibutuhkan,
                    qtyBooking: (float) ($row['qty_booking'] ?? 0),
                    qtyPr: (float) ($row['qty_pr'] ?? 0),
                    chosenPieces: $recommendedPieces,
                    requestedSegments: [],
                    requireNoJoint: false,
                );
            }

            $purchaseRecommendation = (array) ($row['purchase_recommendation'] ?? []);

            return [
                'material_id' => $materialId,
                'material_name' => (string) ($row['nama_material'] ?? ('Material #'.$materialId)),
                'dibutuhkan' => round($dibutuhkan, 2),
                'satuan' => $satuan,
                'available_qty' => round((float) ($row['available'] ?? $row['available_qty'] ?? 0), 2),
                'recommended_booking_qty' => round((float) ($row['qty_booking'] ?? 0), 2),
                'recommended_pr_qty' => round((float) ($row['qty_pr'] ?? 0), 2),
                'recommended_piece_ids' => collect($row['recommended_piece_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->values()->all(),
                'recommended_qty' => round((float) ($row['recommended_qty'] ?? ($row['qty_booking'] ?? 0)), 2),
                'recommended_pieces' => $recommendedPieces,
                'purchase_recommendation' => $purchaseRecommendation,
                'purchase_recommendation_text' => $this->formatPurchaseRecommendationForDisplay($purchaseRecommendation),
                'tanpa_potongan' => (bool) ($row['tanpa_potongan'] ?? false),
                'ai_reason' => $reason,
                'ai_reason_source' => (string) ($row['ai_reason_source'] ?? 'unknown'),
                'status' => 'pending',
            ];
        })->values()->all();
    }

    /**
     * Build reason text from final PHP allocation so explanation always matches selected pieces.
     *
     * @param  array<int, array<string, mixed>>  $chosenPieces
     * @param  array<int, float|int|string>  $requestedSegments
     */
    private function buildSystemReasonFromPieces(
        string $materialName,
        string $satuan,
        float $dibutuhkan,
        float $qtyBooking,
        float $qtyPr,
        array $chosenPieces,
        array $requestedSegments,
        bool $requireNoJoint,
    ): string {
        $isDiscreteUnit = Material::usesDiscreteUnitTrackingFor($satuan);
        $itemLabel = $isDiscreteUnit ? 'item' : 'piece';
        $itemLabelTitle = ucfirst($itemLabel);
        $qtyNeedText = $this->formatQty($dibutuhkan).' '.$satuan;
        $qtyBookText = $this->formatQty($qtyBooking).' '.$satuan;
        $qtyPrText = $this->formatQty($qtyPr).' '.$satuan;

        if ($qtyBooking <= 0.00001) {
            return "Sistem PHP tidak menemukan piece yang bisa dibooking untuk {$materialName}. Seluruh kebutuhan {$qtyNeedText} dialihkan ke PR ({$qtyPrText}).";
        }

        $pieceCount = count($chosenPieces);
        $segmentsText = collect($requestedSegments)
            ->map(fn ($segment): float => round((float) $segment, 2))
            ->filter(fn (float $segment): bool => $segment > 0)
            ->map(fn (float $segment): string => $this->formatQty($segment).' '.$satuan)
            ->values()
            ->all();

        $pieceLines = collect($chosenPieces)
            ->map(function (array $piece) use ($isDiscreteUnit, $itemLabelTitle, $satuan): string {
                $pieceId = (int) ($piece['id'] ?? 0);
                $length = (float) ($piece['panjang'] ?? 0);
                $take = (float) ($piece['take_qty'] ?? 0);
                $left = max(0, $length - $take);
                $rack = (string) ($piece['rack_label'] ?? '-');

                if ($take + 0.00001 < $length) {
                    if ($isDiscreteUnit) {
                        return $itemLabelTitle.' #'.$pieceId.' ambil '.$this->formatQty($take).' '.$satuan.' dari total '.$this->formatQty($length).' '.$satuan.' di Rak '.$rack;
                    }

                    return $itemLabelTitle.' #'.$pieceId.' potong '.$this->formatQty($take).' '.$satuan.' dari '.$this->formatQty($length).' '.$satuan.' (sisa '.$this->formatQty($left).' '.$satuan.') di Rak '.$rack;
                }

                return $itemLabelTitle.' #'.$pieceId.' ambil utuh '.$this->formatQty($length).' '.$satuan.' di Rak '.$rack;
            })
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->values()
            ->all();

        $head = "Sistem PHP mengalokasikan {$pieceCount} {$itemLabel} untuk {$materialName}: booking {$qtyBookText} dari kebutuhan {$qtyNeedText}.";
        if ($qtyPr > 0.00001) {
            $head .= ' Sisa kebutuhan masuk PR '.$qtyPrText.'.';
        }

        if ($requireNoJoint && ! empty($segmentsText)) {
            $head .= ' Target segmen tanpa sambungan: '.implode(' + ', $segmentsText).'.';
        }

        if (! empty($pieceLines)) {
            $head .= ' '.implode('. ', $pieceLines).'.';
        }

        return trim($head);
    }

    /**
     * Keep Gemini reason pure: only show Gemini wording, without PHP-added allocation narrative.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, array<string, mixed>>  $chosenPieces
     * @param  array<int, float|int|string>  $requestedSegments
     */
    private function buildGeminiPrimarySyncedReason(
        string $rawReason,
        array $item,
        string $materialName,
        string $satuan,
        float $dibutuhkan,
        float $qtyBooking,
        float $qtyPr,
        array $chosenPieces,
        array $requestedSegments,
        bool $requireNoJoint,
    ): string {
        $strategicReason = trim($rawReason);
        $strategicReason = str_replace(
            ['require_no_joint', 'requested_segments', 'tanpa_potongan'],
            ['tidak boleh disambung', 'segmen yang diminta', 'tanpa potongan'],
            $strategicReason
        );

        // Hapus catatan teknis dalam kurung seperti "(aturan require_no_joint)"
        $strategicReason = preg_replace('/\(\s*aturan[^)]*\)/iu', '', $strategicReason);

        // Kalau ada kurung gantung yang kepotong, buang ekornya
        if (substr_count($strategicReason, '(') > substr_count($strategicReason, ')')) {
            $strategicReason = preg_replace('/\([^\)]*$/u', '', $strategicReason);
        }

        // Rapikan spasi dan tanda baca
        $strategicReason = preg_replace('/\s+/', ' ', $strategicReason);
        $strategicReason = preg_replace('/\s+\./', '.', $strategicReason);
        $strategicReason = preg_replace('/\.{2,}/', '.', $strategicReason);
        $strategicReason = trim((string) $strategicReason);
        $strategicReason = trim((string) preg_replace('/\s+/', ' ', str_replace(['..', ' .', '. .'], '.', $strategicReason)));
        if ($strategicReason === '') {
            return $this->buildSystemReasonFromPieces(
                materialName: $materialName,
                satuan: $satuan,
                dibutuhkan: $dibutuhkan,
                qtyBooking: $qtyBooking,
                qtyPr: $qtyPr,
                chosenPieces: $chosenPieces,
                requestedSegments: $requestedSegments,
                requireNoJoint: $requireNoJoint,
            );
        }

        return 'Analisis AI: '.rtrim($strategicReason, '. ').'.';
    }

    /**
     * @param  array<string, mixed>  $materialGemini
     * @return array<int, array{piece_id:int,take_qty:float,reason:string}>
     */
    private function extractGeminiPieceAllocations(array $materialGemini): array
    {
        $raw = $materialGemini['alokasi_kabel'] ?? $materialGemini['allocations'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row): array {
                $pieceId = (int) ($row['piece_id'] ?? $row['id'] ?? 0);
                $takeQty = round((float) ($row['ambil_panjang'] ?? $row['take'] ?? $row['take_qty'] ?? 0), 2);
                $reason = trim((string) ($row['alasan'] ?? $row['reason'] ?? ''));

                return [
                    'piece_id' => $pieceId,
                    'take_qty' => $takeQty,
                    'reason' => $reason,
                ];
            })
            ->filter(fn (array $row): bool => ($row['piece_id'] ?? 0) > 0 && ($row['take_qty'] ?? 0) > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{piece_id:int,take_qty:float,reason:string}>  $allocations
     * @param  array<int, float|int|string>  $requestedSegments
     * @return array{chosen: array<int, array<string, mixed>>, remaining: float}
     */
    private function buildChosenPiecesFromGeminiAllocations(
        array $allocations,
        Collection $pieceById,
        float $qtyBooking,
        bool $requireNoJoint = false,
        array $requestedSegments = [],
    ): array {
        if (empty($allocations) || $qtyBooking <= 0) {
            return ['chosen' => [], 'remaining' => $qtyBooking];
        }

        $allocTotal = round((float) collect($allocations)->sum(fn (array $row): float => (float) ($row['take_qty'] ?? 0)), 2);
        if (abs($allocTotal - round($qtyBooking, 2)) > 0.1) {
            return ['chosen' => [], 'remaining' => $qtyBooking];
        }

        if ($requireNoJoint && ! empty($requestedSegments)) {
            $requestedSorted = collect($requestedSegments)
                ->map(fn ($value): float => round((float) $value, 2))
                ->filter(fn (float $value): bool => $value > 0)
                ->sort()
                ->values()
                ->all();

            $allocSorted = collect($allocations)
                ->map(fn (array $row): float => round((float) ($row['take_qty'] ?? 0), 2))
                ->filter(fn (float $value): bool => $value > 0)
                ->sort()
                ->values()
                ->all();

            if ($requestedSorted !== $allocSorted) {
                return ['chosen' => [], 'remaining' => $qtyBooking];
            }
        }

        $virtualRemaining = $pieceById
            ->mapWithKeys(fn (array $piece): array => [(int) ($piece['id'] ?? 0) => round((float) ($piece['panjang'] ?? 0), 2)])
            ->all();

        $chosen = [];
        foreach ($allocations as $alloc) {
            $pieceId = (int) ($alloc['piece_id'] ?? 0);
            $takeQty = round((float) ($alloc['take_qty'] ?? 0), 2);
            $piece = $pieceById->get($pieceId);

            if (! $piece || $takeQty <= 0) {
                return ['chosen' => [], 'remaining' => $qtyBooking];
            }

            $before = (float) ($virtualRemaining[$pieceId] ?? 0);
            if ($before + 0.00001 < $takeQty) {
                return ['chosen' => [], 'remaining' => $qtyBooking];
            }

            $virtualRemaining[$pieceId] = round($before - $takeQty, 2);
            $chosen[] = [
                'id' => $pieceId,
                'panjang' => round($before, 2),
                'take_qty' => $takeQty,
                'sumber' => (string) ($piece['sumber'] ?? '-'),
                'age_days' => (int) ($piece['umur_hari'] ?? 0),
                'shelf_location_id' => (int) ($piece['shelf_location_id'] ?? 0),
                'rack_label' => (string) ($piece['rack_label'] ?? '-'),
            ];
        }

        return ['chosen' => $chosen, 'remaining' => max(0, round($qtyBooking - $allocTotal, 2))];
    }

    private function buildChosenPiecesFromSelection(array $selectedIds, Collection $pieceById, float $dibutuhkan): array
    {
        $remaining = $dibutuhkan;
        $chosen = [];
        foreach ($selectedIds as $pieceId) {
            if ($remaining <= 0.00001) {
                break;
            }
            $piece = $pieceById->get((int) $pieceId);
            if (! $piece || (string) ($piece['status'] ?? '') !== 'available') {
                continue;
            }
            $pieceLength = (float) ($piece['panjang'] ?? 0);
            if ($pieceLength <= 0) {
                continue;
            }
            $takeQty = min($pieceLength, $remaining);
            $chosen[] = ['id' => (int) $pieceId, 'panjang' => round($pieceLength, 2), 'take_qty' => round($takeQty, 2), 'sumber' => (string) ($piece['sumber'] ?? '-'), 'age_days' => (int) ($piece['umur_hari'] ?? 0), 'shelf_location_id' => (int) ($piece['shelf_location_id'] ?? 0), 'rack_label' => (string) ($piece['rack_label'] ?? '-')];
            $remaining -= $takeQty;
        }

        return ['chosen' => $chosen, 'remaining' => $remaining];
    }

    /**
     * Reduce fragment count for regular booking by absorbing small takes
     * into an already-split piece when there is capacity.
     *
     * @param  array<int, array<string, mixed>>  $chosen
     * @return array<int, array<string, mixed>>
     */
    private function rebalanceNonSegmentedChosenPieces(array $chosen): array
    {
        if (count($chosen) <= 1) {
            return $chosen;
        }

        $rows = array_values($chosen);

        while (true) {
            $splitIndex = null;
            $splitSlack = 0.0;

            foreach ($rows as $idx => $row) {
                $length = round((float) ($row['panjang'] ?? 0), 2);
                $take = round((float) ($row['take_qty'] ?? 0), 2);
                $slack = round($length - $take, 2);

                if ($length <= 0 || $slack <= 0.00001) {
                    continue;
                }

                if ($slack > $splitSlack) {
                    $splitSlack = $slack;
                    $splitIndex = $idx;
                }
            }

            if ($splitIndex === null || $splitSlack <= 0.00001) {
                break;
            }

            $splitPieceId = (int) ($rows[$splitIndex]['id'] ?? 0);
            if ($splitPieceId <= 0) {
                break;
            }

            $candidates = collect($rows)
                ->map(fn (array $row, int $idx): array => ['idx' => $idx, 'take' => round((float) ($row['take_qty'] ?? 0), 2), 'len' => round((float) ($row['panjang'] ?? 0), 2)])
                ->filter(fn (array $row): bool => $row['idx'] !== $splitIndex && $row['take'] > 0 && abs($row['take'] - $row['len']) <= 0.01)
                ->sortBy('take')
                ->values();

            $absorbedAny = false;
            foreach ($candidates as $candidate) {
                $idx = (int) ($candidate['idx'] ?? -1);
                $take = round((float) ($candidate['take'] ?? 0), 2);

                if ($idx < 0 || $take <= 0 || $splitSlack + 0.00001 < $take) {
                    continue;
                }

                $rows[$splitIndex]['take_qty'] = round((float) ($rows[$splitIndex]['take_qty'] ?? 0) + $take, 2);
                $splitSlack = round($splitSlack - $take, 2);
                unset($rows[$idx]);
                $rows = array_values($rows);

                // Keep split index aligned after reindex.
                foreach ($rows as $newIdx => $row) {
                    if ((int) ($row['id'] ?? 0) === $splitPieceId) {
                        $splitIndex = $newIdx;
                        break;
                    }
                }

                $absorbedAny = true;

                if ($splitSlack <= 0.00001) {
                    break;
                }
            }

            if (! $absorbedAny) {
                break;
            }
        }

        return array_values($rows);
    }

    /**
     * Respect explicit manager request for piece count in non-segment mode.
     *
     * @param  array<int, array<string, mixed>>  $chosen
     * @return array<int, array<string, mixed>>
     */
    private function enforceRequestedPieceCount(array $chosen, int $requestedPieceCount): array
    {
        $target = max(1, $requestedPieceCount);
        $rows = array_values($chosen);

        if (count($rows) === $target) {
            return $rows;
        }

        while (count($rows) < $target) {
            $splitIndex = collect($rows)
                ->keys()
                ->sortByDesc(fn (int $idx): float => (float) ($rows[$idx]['take_qty'] ?? 0))
                ->first(fn (int $idx): bool => (float) ($rows[$idx]['take_qty'] ?? 0) > 0.01);

            if ($splitIndex === null) {
                break;
            }

            $take = round((float) ($rows[$splitIndex]['take_qty'] ?? 0), 2);
            $first = round($take / 2, 2);
            $second = round($take - $first, 2);

            if ($first <= 0.00001 || $second <= 0.00001) {
                break;
            }

            $rows[$splitIndex]['take_qty'] = $first;
            $newRow = $rows[$splitIndex];
            $newRow['take_qty'] = $second;
            array_splice($rows, $splitIndex + 1, 0, [$newRow]);
        }

        while (count($rows) > $target) {
            $smallestIndex = collect($rows)
                ->keys()
                ->sortBy(fn (int $idx): float => (float) ($rows[$idx]['take_qty'] ?? 0))
                ->first();

            if ($smallestIndex === null) {
                break;
            }

            $smallTake = round((float) ($rows[$smallestIndex]['take_qty'] ?? 0), 2);
            if ($smallTake <= 0.00001) {
                unset($rows[$smallestIndex]);
                $rows = array_values($rows);

                continue;
            }

            $targetIndex = collect($rows)
                ->keys()
                ->first(function (int $idx) use ($rows, $smallestIndex, $smallTake): bool {
                    if ($idx === $smallestIndex) {
                        return false;
                    }

                    $length = round((float) ($rows[$idx]['panjang'] ?? 0), 2);
                    $take = round((float) ($rows[$idx]['take_qty'] ?? 0), 2);

                    return $length - $take + 0.00001 >= $smallTake;
                });

            if ($targetIndex === null) {
                break;
            }

            $rows[$targetIndex]['take_qty'] = round((float) ($rows[$targetIndex]['take_qty'] ?? 0) + $smallTake, 2);
            unset($rows[$smallestIndex]);
            $rows = array_values($rows);
        }

        return array_values($rows);
    }

    /**
     * Keep only segment numbers that can exactly cover target qty.
     * This avoids polluted numbers from free-text notes (piece IDs, ages, etc).
     *
     * @param  array<int, float|int|string>  $requestedSegments
     * @return array<int, float>
     */
    private function normalizeRequestedSegmentsForTarget(array $requestedSegments, float $targetQty): array
    {
        $target = round((float) $targetQty, 2);
        if ($target <= 0) {
            return [];
        }

        $segments = collect($requestedSegments)
            ->map(fn ($value): float => round((float) $value, 2))
            ->filter(fn (float $value): bool => $value > 0)
            ->values()
            ->all();

        if (empty($segments)) {
            return [];
        }

        $sum = round((float) array_sum($segments), 2);
        if (abs($sum - $target) <= 0.01) {
            return $segments;
        }

        $subset = $this->pickSegmentSubsetForTarget($segments, $target);
        if (! empty($subset)) {
            return $subset;
        }

        // Common real-world pattern: manager writes one nominal length repeatedly.
        $freq = collect($segments)
            ->countBy(fn (float $value): string => number_format($value, 2, '.', ''))
            ->sortDesc();

        $nominal = (float) ($freq->keys()->first() ?? 0);
        if ($nominal > 0) {
            $count = (int) round($target / $nominal);
            if ($count > 0 && abs(round($nominal * $count, 2) - $target) <= 0.01) {
                return array_fill(0, $count, round($nominal, 2));
            }
        }

        return [];
    }

    /**
     * @param  array<int, float>  $segments
     * @return array<int, float>
     */
    private function pickSegmentSubsetForTarget(array $segments, float $target): array
    {
        $targetInt = (int) round($target * 100);
        if ($targetInt <= 0) {
            return [];
        }

        $segmentsInt = array_map(fn (float $value): int => (int) round($value * 100), $segments);
        $n = count($segmentsInt);
        if ($n === 0 || $n > 30) {
            return [];
        }

        $dp = [0 => []];

        foreach ($segmentsInt as $idx => $valueInt) {
            $next = $dp;
            foreach ($dp as $sum => $pickedIdx) {
                $newSum = $sum + $valueInt;
                if ($newSum > $targetInt) {
                    continue;
                }

                $newPicked = $pickedIdx;
                $newPicked[] = $idx;

                if (! array_key_exists($newSum, $next)) {
                    $next[$newSum] = $newPicked;

                    continue;
                }

                $current = (array) ($next[$newSum] ?? []);
                $candidateCount = count($newPicked);
                $currentCount = count($current);

                if ($candidateCount > $currentCount) {
                    $next[$newSum] = $newPicked;

                    continue;
                }

                if ($candidateCount === $currentCount && $candidateCount > 0) {
                    $candidateMax = max(array_map(fn (int $i): int => (int) ($segmentsInt[$i] ?? 0), $newPicked));
                    $currentMax = max(array_map(fn (int $i): int => (int) ($segmentsInt[$i] ?? 0), $current));

                    if ($candidateMax < $currentMax) {
                        $next[$newSum] = $newPicked;
                    }
                }
            }
            $dp = $next;
        }

        if (! array_key_exists($targetInt, $dp)) {
            return [];
        }

        $picked = $dp[$targetInt];

        return array_values(array_map(fn (int $idx): float => round((float) ($segments[$idx] ?? 0), 2), $picked));
    }

    /**
     * Pick the best exact-segment subset with maximum total that does not exceed limit.
     *
     * @param  array<int, float>  $segments
     * @return array<int, float>
     */
    private function pickSegmentSubsetWithinLimit(array $segments, float $limit): array
    {
        $limitInt = (int) round(max(0, $limit) * 100);
        if ($limitInt <= 0) {
            return [];
        }

        $segmentsInt = array_map(fn (float $value): int => (int) round(max(0, $value) * 100), $segments);
        $n = count($segmentsInt);
        if ($n === 0 || $n > 30) {
            return [];
        }

        $dp = [0 => []];

        foreach ($segmentsInt as $idx => $valueInt) {
            if ($valueInt <= 0) {
                continue;
            }

            $next = $dp;
            foreach ($dp as $sum => $pickedIdx) {
                $newSum = $sum + $valueInt;
                if ($newSum > $limitInt) {
                    continue;
                }

                $newPicked = $pickedIdx;
                $newPicked[] = $idx;

                if (! array_key_exists($newSum, $next)) {
                    $next[$newSum] = $newPicked;

                    continue;
                }

                $current = (array) ($next[$newSum] ?? []);
                if (count($newPicked) > count($current)) {
                    $next[$newSum] = $newPicked;
                }
            }

            $dp = $next;
        }

        if (empty($dp)) {
            return [];
        }

        $bestSum = max(array_keys($dp));
        if ($bestSum <= 0) {
            return [];
        }

        $picked = (array) ($dp[$bestSum] ?? []);

        return array_values(array_map(fn (int $idx): float => round((float) ($segments[$idx] ?? 0), 2), $picked));
    }

    private function buildChosenPiecesFromSegments(array $selectedIds, Collection $pieceById, float $qtyBooking, array $requestedSegments): array
    {
        $segments = collect($requestedSegments)
            ->map(fn ($value): float => round((float) $value, 2))
            ->filter(fn (float $value): bool => $value > 0)
            ->values();

        if ($segments->isEmpty()) {
            return ['chosen' => [], 'remaining' => $qtyBooking];
        }

        $segmentsTotal = round((float) $segments->sum(), 2);
        if (abs($segmentsTotal - round($qtyBooking, 2)) > 0.01) {
            return ['chosen' => [], 'remaining' => $qtyBooking];
        }

        $allPieces = $pieceById
            ->map(fn (array $piece): array => [
                'id' => (int) ($piece['id'] ?? 0),
                'panjang' => round((float) ($piece['panjang'] ?? 0), 2),
                'umur_hari' => (int) ($piece['umur_hari'] ?? 0),
                'rack_label' => (string) ($piece['rack_label'] ?? '-'),
                'sumber' => (string) ($piece['sumber'] ?? '-'),
                'shelf_location_id' => (int) ($piece['shelf_location_id'] ?? 0),
            ])
            ->filter(fn (array $piece): bool => $piece['id'] > 0 && $piece['panjang'] > 0)
            ->values();

        if ($allPieces->isEmpty()) {
            return ['chosen' => [], 'remaining' => $qtyBooking];
        }

        $virtualRemaining = $allPieces->mapWithKeys(fn (array $piece): array => [$piece['id'] => $piece['panjang']])->all();
        $priorityIds = collect($selectedIds)->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->values()->all();
        $chosen = [];

        foreach ($segments as $segment) {
            $candidate = $this->pickPieceForSegment((float) $segment, $allPieces, $virtualRemaining, $priorityIds);
            if ($candidate === null) {
                return ['chosen' => [], 'remaining' => $qtyBooking];
            }

            $pieceId = (int) $candidate['id'];
            $before = (float) ($virtualRemaining[$pieceId] ?? 0);
            $virtualRemaining[$pieceId] = round($before - (float) $segment, 2);

            $chosen[] = [
                'id' => $pieceId,
                'panjang' => round($before, 2),
                'take_qty' => round((float) $segment, 2),
                'sumber' => (string) ($candidate['sumber'] ?? '-'),
                'age_days' => (int) ($candidate['umur_hari'] ?? 0),
                'shelf_location_id' => (int) ($candidate['shelf_location_id'] ?? 0),
                'rack_label' => (string) ($candidate['rack_label'] ?? '-'),
            ];
        }

        return ['chosen' => $chosen, 'remaining' => 0.0];
    }

    private function pickPieceForSegment(float $segment, Collection $allPieces, array $virtualRemaining, array $priorityIds): ?array
    {
        if ($segment <= 0) {
            return null;
        }

        $priority = $allPieces
            ->filter(fn (array $piece): bool => in_array((int) $piece['id'], $priorityIds, true) && ((float) ($virtualRemaining[(int) $piece['id']] ?? 0)) >= $segment)
            ->sortBy([
                fn (array $piece): float => (float) ($virtualRemaining[(int) $piece['id']] ?? 0),
                fn (array $piece): int => (int) ($piece['umur_hari'] ?? 0),
            ])
            ->first();

        if (is_array($priority)) {
            return $priority;
        }

        $fallback = $allPieces
            ->filter(fn (array $piece): bool => ((float) ($virtualRemaining[(int) $piece['id']] ?? 0)) >= $segment)
            ->sortBy([
                fn (array $piece): float => (float) ($virtualRemaining[(int) $piece['id']] ?? 0),
                fn (array $piece): int => (int) ($piece['umur_hari'] ?? 0),
            ])
            ->first();

        return is_array($fallback) ? $fallback : null;
    }

    private function extractRequestedSegmentsFromText(string $text): array
    {
        $segments = collect();

        preg_match_all('/(\d+(?:[\.,]\d+)?)\s*(?:m|meter|meters)\b/i', $text, $matchesWithUnit);
        if (! empty($matchesWithUnit[1] ?? [])) {
            $segments = collect($matchesWithUnit[1]);
        }

        if ($segments->isEmpty() && preg_match('/kabel|potong|piece|pcs|roll|jadi|bikin|buat|ambil|cut/i', $text)) {
            preg_match_all('/\b(\d{2,4}(?:[\.,]\d+)?)\b/', $text, $matchesRaw);
            $segments = collect($matchesRaw[1] ?? []);
        }

        // Handle terse manager notes like "bikin 100 100 100" even without explicit unit keywords.
        if ($segments->isEmpty()) {
            preg_match_all('/\b(\d{2,4}(?:[\.,]\d+)?)\b/', $text, $matchesBareNumbers);
            $bareNumbers = collect($matchesBareNumbers[1] ?? [])
                ->map(function ($value): float {
                    $normalized = str_replace(',', '.', (string) $value);

                    return round((float) $normalized, 2);
                })
                ->filter(fn (float $value): bool => $value >= 20 && $value <= 2000)
                ->values();

            if ($bareNumbers->count() >= 2) {
                $segments = $bareNumbers;
            }
        }

        $normalized = $segments
            ->map(function ($value): float {
                $normalized = str_replace(',', '.', (string) $value);

                return round((float) $normalized, 2);
            })
            ->filter(fn (float $value): bool => $value > 0 && $value <= 2000)
            ->values()
            ->take(20);

        if ($matchesWithUnit[1] ?? false) {
            return $normalized->all();
        }

        return $normalized
            ->filter(fn (float $value): bool => $value >= 20)
            ->values()
            ->all();
    }

    private function fallbackPieceSelection(int $materialId, float $dibutuhkan, string $materialName = '', string $satuan = 'unit', array $instruksiAi = []): array
    {
        $preferSumber = (string) ($instruksiAi['preferensi_sumber'] ?? 'auto');
        $preferUmur = (string) ($instruksiAi['preferensi_umur_stok'] ?? 'lama');
        $preferredPieceIds = collect($instruksiAi['preferred_piece_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->values()->all();
        $preferredRackIds = collect($instruksiAi['preferred_rack_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->values()->all();

        $remaining = $dibutuhkan;
        $selectedIds = [];
        while ($remaining > 0.00001) {
            $query = MaterialPiece::query()
                ->where('material_id', $materialId)
                ->where('status', 'available')
                ->when(! empty($selectedIds), fn ($q) => $q->whereNotIn('id', $selectedIds));

            if (! empty($preferredPieceIds)) {
                $idsSql = implode(',', $preferredPieceIds);
                $query->orderByRaw("CASE WHEN id IN ({$idsSql}) THEN 0 ELSE 1 END ASC");
            }

            if (! empty($preferredRackIds)) {
                $racksSql = implode(',', $preferredRackIds);
                $query->orderByRaw("CASE WHEN shelf_location_id IN ({$racksSql}) THEN 0 ELSE 1 END ASC");
            }

            if ($preferSumber === 'baru') {
                $query->orderByRaw("CASE WHEN sumber IN ('inbound_baru', 'opening_balance') THEN 0 WHEN sumber IN ('split_auto_allocation', 'split_booking', 'split_booking_residue') THEN 1 ELSE 2 END ASC");
            } elseif ($preferSumber === 'potongan') {
                $query->orderByRaw("CASE WHEN sumber IN ('split_auto_allocation', 'split_booking', 'split_booking_residue') THEN 0 WHEN sumber IN ('inbound_baru', 'opening_balance') THEN 1 ELSE 2 END ASC");
            } else {
                $query->orderByRaw("CASE WHEN sumber IN ('split_auto_allocation', 'split_booking', 'split_booking_residue') THEN 1 WHEN sumber IN ('inbound_baru', 'opening_balance') THEN 2 ELSE 3 END ASC");
            }

            $query->orderByRaw('CASE WHEN panjang >= ? THEN 0 ELSE 1 END ASC', [$remaining])
                ->orderByRaw('CASE WHEN panjang >= ? THEN panjang ELSE -panjang END ASC', [$remaining]);

            if ($preferUmur === 'baru') {
                $query->orderByRaw('COALESCE((SELECT entry_date FROM inbounds WHERE inbounds.id = material_pieces.inbound_id), material_pieces.created_at) DESC');
            } else {
                $query->orderByRaw('COALESCE((SELECT entry_date FROM inbounds WHERE inbounds.id = material_pieces.inbound_id), material_pieces.created_at) ASC');
            }

            $candidate = $query->first(['id', 'panjang']);
            if (! $candidate) {
                break;
            }
            $selectedIds[] = (int) $candidate->id;
            $remaining -= min((float) $candidate->panjang, $remaining);
        }

        $preferReasonSegments = [];
        if (! empty($preferredPieceIds)) {
            $preferReasonSegments[] = 'mengutamakan piece yang disebut manager';
        }
        if (! empty($preferredRackIds)) {
            $preferReasonSegments[] = 'mengutamakan rak yang disebut manager';
        }
        if ($preferSumber === 'potongan') {
            $preferReasonSegments[] = 'mengutamakan stok potongan sesuai catatan manager';
        }
        if ($preferSumber === 'baru') {
            $preferReasonSegments[] = 'mengutamakan stok baru/roll utuh sesuai catatan manager';
        }
        if ($preferUmur === 'baru') {
            $preferReasonSegments[] = 'memprioritaskan stok terbaru sesuai instruksi manager';
        }
        if ($preferUmur === 'lama') {
            $preferReasonSegments[] = 'memprioritaskan stok terlama (FIFO)';
        }
        $preferReason = empty($preferReasonSegments) ? '' : (implode(', ', $preferReasonSegments).'. ');

        $isUnitBased = in_array(strtolower($satuan), ['unit', 'buah', 'pcs', 'set', 'pasang'], true);
        if (empty($selectedIds)) {
            $overallReason = "{$preferReason}Stok {$materialName} kosong di gudang, seluruh kebutuhan akan dibuatkan PR.";
            $sisaPartial = 'Seluruh kebutuhan dibuatkan PR karena stok tidak tersedia.';
            $sisaFull = '';
        } elseif ($isUnitBased) {
            $overallReason = $preferReason.(count($selectedIds) <= 1 ? "Mengambil 1 unit {$materialName} sesuai prioritas instruksi manager." : 'Mengambil '.count($selectedIds)." unit {$materialName} sesuai prioritas instruksi manager.");
            $sisaFull = 'Seluruh kebutuhan unit terpenuhi dari stok gudang.';
            $sisaPartial = 'Stok unit yang tersedia belum mencukupi, sisanya perlu dibuatkan PR.';
        } else {
            $overallReason = $preferReason.(count($selectedIds) <= 1 ? 'Mengambil piece sesuai prioritas instruksi manager untuk meminimalkan mismatch kebutuhan.' : 'Mengkombinasikan '.count($selectedIds).' piece sesuai prioritas instruksi manager.');
            $sisaFull = 'Kebutuhan terpenuhi, sisa potongan dikembalikan ke gudang dan masih bisa dipakai proyek lain.';
            $sisaPartial = 'Stok piece yang tersedia belum mencukupi seluruh kebutuhan, sisanya perlu dibuatkan PR.';
        }

        return ['selected_piece_ids' => $selectedIds, 'alasan_per_piece' => [], 'overall_reason' => $overallReason, 'sisa_analysis' => $remaining > 0.00001 ? $sisaPartial : $sisaFull];
    }

    private function hitungDeltaKebutuhan(int $projectId, array $kebutuhan): array
    {
        $pendingOperationalByMaterial = Task::query()
            ->where('type', 'ai_inbound_allocation_suggestion')
            ->where('related_type', 'project')
            ->where('related_id', $projectId)
            ->where('status', 'pending')
            ->get(['id', 'payload'])
            ->filter(function (Task $task): bool {
                return (int) data_get($task->payload, 'material_id') > 0
                    && (float) data_get($task->payload, 'qty_dibutuhkan', 0) > 0;
            })
            ->sortByDesc('id')
            ->groupBy(fn (Task $task): int => (int) data_get($task->payload, 'material_id'))
            ->map(fn ($tasks): float => round((float) data_get($tasks->first()?->payload, 'qty_dibutuhkan', 0), 2))
            ->all();

        return collect($kebutuhan)->map(function (array $item) use ($pendingOperationalByMaterial, $projectId) {
            $materialId = (int) ($item['material_id'] ?? 0);
            $qtyRequested = (float) ($item['qty'] ?? 0);
            if ($materialId <= 0 || $qtyRequested <= 0) {
                return null;
            }
            $qtyBooked = (float) MaterialPiece::query()->where('booked_for_project_id', $projectId)->where('material_id', $materialId)->whereIn('status', ['booked', 'used'])->sum('panjang');
            $qtyPr = (float) PurchaseRequest::query()->where('project_id', $projectId)->where('material_id', $materialId)->whereIn('status', ['draft', 'pending_manager', 'pending_finance', 'approved', 'ordered', 'received'])->sum('quantity');
            $qtyPendingOperational = (float) ($pendingOperationalByMaterial[$materialId] ?? 0);
            $qtyDelta = max(0, $qtyRequested - ($qtyBooked + $qtyPr + $qtyPendingOperational));
            if ($qtyDelta <= 0) {
                return null;
            }

            return ['material_id' => $materialId, 'qty' => round($qtyDelta, 2), 'qty_original' => round($qtyRequested, 2)];
        })->filter()->values()->all();
    }

    private function sinkronTaskTanpaTambahan(int $projectId): void
    {
        $project = Project::query()->find($projectId, ['id', 'kode_proyek', 'catatan_teknis']);
        $projectCode = (string) ($project?->kode_proyek ?: "#{$projectId}");
        $projectSpecialInstruction = trim((string) ($project?->catatan_teknis ?? ''));
        $pendingTasks = Task::query()
            ->where('type', 'ai_booking_suggestion')
            ->where('related_type', 'project')
            ->where('related_id', $projectId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get();

        if ($pendingTasks->isEmpty()) {
            return;
        }

        $pendingTasks->each(function (Task $task, int $index) use ($projectCode, $projectSpecialInstruction): void {
            $payload = $task->payload ?? [];
            data_set($payload, 'project_special_instruction', $projectSpecialInstruction);
            data_set($payload, 'detail', []);
            data_set($payload, 'ai_summary.rekomendasi', []);
            data_set($payload, 'ai_summary.ringkasan', 'Tidak ada tambahan kebutuhan baru. Data kebutuhan sebelumnya sudah ter-cover booking/PR.');
            data_set($payload, 'last_synced_at', now()->toDateTimeString());

            if ($index > 0) {
                data_set($payload, 'superseded', true);
                data_set($payload, 'superseded_at', now()->toDateTimeString());
            }

            $task->update([
                'status' => 'done',
                'handled_at' => now(),
                'title' => "AI: Konfirmasi Kebutuhan Material Proyek {$projectCode}",
                'message' => 'Tidak ada tambahan kebutuhan baru. Task ditutup otomatis.',
                'payload' => $payload,
            ]);
        });
    }

    private function buildFallbackAnalysis(int $projectId, array $kebutuhanDelta, array $stockData, array $instruksiAi): array
    {
        $tanpaPotonganDariPrompt = (bool) ($instruksiAi['tanpa_potongan'] ?? false);
        $hasilAnalisis = [];

        foreach ($kebutuhanDelta as $item) {
            $material = Material::query()->find((int) ($item['material_id'] ?? 0));
            if (! $material) {
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
                satuan: $material->resolveTrackingUnit(),
                instruksiAi: $instruksiAi,
            );

            $pieceById = collect((array) data_get($stockData, (string) $material->id.'.pieces', []))
                ->keyBy(fn (array $piece): int => (int) ($piece['id'] ?? 0));

            ['chosen' => $chosen, 'remaining' => $remaining] = $this->buildChosenPiecesFromSelection(
                selectedIds: collect($fallbackSelection['selected_piece_ids'] ?? [])->map(fn ($id): int => (int) $id)->values()->all(),
                pieceById: $pieceById,
                dibutuhkan: (float) ($fallbackAnalisa['qty_booking'] ?? 0),
            );

            $fallbackAnalisa['recommended_piece_ids'] = collect($chosen)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $fallbackAnalisa['recommended_qty'] = round((float) ($fallbackAnalisa['qty_booking'] ?? 0) - max(0, $remaining), 2);
            $fallbackAnalisa['recommended_pieces'] = $chosen;
            $fallbackAnalisa['alasan_ai_gemini'] = '';
            $fallbackAnalisa['purchase_recommendation'] = $this->buildPurchaseRecommendation(
                project: Project::query()->find($projectId),
                material: $material,
                qtyPr: (float) ($fallbackAnalisa['qty_pr'] ?? 0),
            );
            $fallbackAnalisa['alasan_ai'] = $this->appendPurchaseRecommendationToReason($this->buildSystemReasonFromPieces(
                materialName: (string) ($material->nama_material ?? ('Material #'.(int) $material->id)),
                satuan: $material->resolveTrackingUnit(),
                dibutuhkan: (float) ($item['qty'] ?? 0),
                qtyBooking: (float) ($fallbackAnalisa['qty_booking'] ?? 0),
                qtyPr: (float) ($fallbackAnalisa['qty_pr'] ?? 0),
                chosenPieces: $chosen,
                requestedSegments: (array) ($instruksiAi['requested_segments'] ?? []),
                requireNoJoint: (bool) ($instruksiAi['require_no_joint'] ?? false),
            ), $fallbackAnalisa['purchase_recommendation']);
            $fallbackAnalisa['ai_reason_source'] = 'fallback_local';
            $fallbackAnalisa['kebutuhan_asli'] = (float) ($item['qty_original'] ?? $item['qty'] ?? 0);
            $fallbackAnalisa['kebutuhan_setelah_buffer'] = (float) ($item['qty'] ?? 0);
            $fallbackAnalisa['kebutuhan_belum_tercover'] = max(0, (float) ($fallbackAnalisa['qty_pr'] ?? 0));

            $hasilAnalisis[] = $fallbackAnalisa;
        }

        return $hasilAnalisis;
    }

    private function normalisasiRekomendasi(array $rekomendasi, array $detail): array
    {
        $rekomendasiList = collect($rekomendasi)->filter(fn ($item) => is_array($item))->values();
        $rekomendasiByMaterial = collect($rekomendasi)->filter(fn ($item) => is_array($item))->keyBy(fn (array $item) => mb_strtolower(trim((string) ($item['material'] ?? ''))));

        return collect($detail)->values()->map(function (array $item, int $index) use ($rekomendasiByMaterial, $rekomendasiList) {
            $aksi = match ($item['aksi'] ?? null) {
                'full_booking' => 'booking', 'partial' => 'booking sebagian + PR', 'full_pr' => 'buat PR', default => 'review manual'
            };
            $namaMaterial = (string) ($item['nama_material'] ?? ('Material #'.($item['material_id'] ?? '-')));
            $llmItem = $rekomendasiByMaterial->get(mb_strtolower(trim($namaMaterial)), []);
            if (empty($llmItem)) {
                $llmItem = (array) ($rekomendasiList->get($index) ?? []);
            }
            $fallbackReason = trim((string) ($item['alasan_ai'] ?? 'mengikuti aturan instruksi proyek dan kondisi stok aktual'));
            $reason = $this->normalizeReasonFromLlm($this->extractReasonPhrase((string) ($llmItem['detail'] ?? '')), $fallbackReason);
            $reason = $this->sinkronkanAngkaAlasan($reason, $item);
            $reason = $this->sesuaikanAlasanDenganTipeMaterial($reason, $item);
            $reason = rtrim($reason, '.');
            if ((bool) ($item['tanpa_potongan'] ?? false)) {
                $stokSisa = $this->formatQty((float) ($item['stok_sisa_potongan'] ?? 0));
                $reason = match ((string) ($item['aksi'] ?? '')) {
                    'full_booking' => 'aturan tanpa potongan aktif, stok roll utuh tersedia sehingga kebutuhan bisa langsung dibooking', 'partial' => 'aturan tanpa potongan aktif, stok roll utuh hanya menutup sebagian kebutuhan sehingga sisanya dibuat PR', 'full_pr' => "aturan tanpa potongan aktif, stok roll utuh tidak mencukupi sehingga dibuat PR (stok potongan {$stokSisa} tidak dipakai)", default => $reason
                };
            }
            $butuh = $this->formatQty((float) ($item['kebutuhan_asli'] ?? ($item['dibutuhkan'] ?? 0)));
            $tersedia = $this->formatQty((float) ($item['available'] ?? 0));
            $booking = $this->formatQty((float) ($item['qty_booking'] ?? 0));
            $pr = $this->formatQty((float) ($item['qty_pr'] ?? 0));
            $detailText = match ($item['aksi'] ?? null) {
                'full_booking' => "Butuh {$butuh}, tersedia total {$tersedia}, dibooking {$booking}, dikarenakan {$reason}.", 'full_pr' => "Butuh {$butuh}, tersedia total {$tersedia}, generate PR {$pr}, dikarenakan {$reason}.", default => "Butuh {$butuh}, tersedia total {$tersedia}, dibooking {$booking}, generate PR {$pr}, dikarenakan {$reason}."
            };
            $detailText = $this->appendPurchaseRecommendationToReason($detailText, (array) ($item['purchase_recommendation'] ?? []));

            return ['material' => $namaMaterial, 'aksi' => $aksi, 'detail' => $detailText];
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
        $normalized = trim((string) preg_replace('/\s+/', ' ', (string) $text));

        return rtrim((string) preg_replace('/^(karena|dikarenakan)\s+/iu', '', $normalized), '.');
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
            $stokSisaList = collect($item['stok_sisa_potongan_list'] ?? [])->map(fn ($value) => $this->formatQty((float) $value))->filter()->values()->all();
            if ($stokSisa > 0 && preg_match('/stok\s+sisa\s+\d+(?:\.\d+)?/iu', $normalized)) {
                $normalized = (string) preg_replace('/stok\s+sisa\s+\d+(?:\.\d+)?/iu', 'stok sisa '.$this->formatQty($stokSisa), $normalized, 1);
            }
            if ($perluAlasanPotongan && ! empty($stokSisaList) && ! preg_match('/potongan\s+tersedia\s+/iu', $normalized)) {
                $normalized = rtrim($normalized, '.').'. Potongan tersedia '.implode(' + ', $stokSisaList).', jadi tidak bisa dipakai karena aturan tanpa potongan';
            }
        }

        return $this->ringkasAlasanJikaPanjang($normalized, $item);
    }

    private function ringkasAlasanJikaPanjang(string $reason, array $item): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $reason));
        $aksi = (string) ($item['aksi'] ?? '');
        $qtyPr = (float) ($item['qty_pr'] ?? 0);
        $perluAlasanPotongan = in_array($aksi, ['full_pr', 'partial'], true) || $qtyPr > 0;
        $shouldShorten = mb_strlen($text) > 190;
        if ((bool) ($item['tanpa_potongan'] ?? false) && $perluAlasanPotongan && preg_match('/potongan\s+tersedia\s+/iu', $text)) {
            $shouldShorten = true;
        }
        if ($text === '' || ! $shouldShorten) {
            return $text;
        }
        if ((bool) ($item['tanpa_potongan'] ?? false)) {
            if (! $perluAlasanPotongan) {
                return 'aturan tanpa potongan aktif dan stok roll utuh tersedia, sehingga kebutuhan bisa langsung dibooking.';
            }
            $stokSisaList = collect($item['stok_sisa_potongan_list'] ?? [])->map(fn ($value) => $this->formatQty((float) $value))->filter()->take(4)->values()->all();
            if (! empty($stokSisaList)) {
                $stokSisaText = $this->formatQty((float) ($item['stok_sisa_potongan'] ?? 0));

                return 'aturan tanpa potongan aktif, stok yang ada hanya potongan '.implode(' + ', $stokSisaList)." (total {$stokSisaText}) jadi tidak bisa dipakai.";
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

        return (bool) preg_match('/kabel|cable|fiber|fo\b|optic|utp|ftp|coax|coaxial|wire|roll/i', mb_strtolower(trim((string) ($material->nama_material ?? ''))));
    }

    private function sesuaikanAlasanDenganTipeMaterial(string $reason, array $item): string
    {
        $text = trim($reason);
        if ($text === '' || (bool) ($item['tanpa_potongan'] ?? false)) {
            return $text;
        }
        if (! preg_match('/potong|potongan|roll\s+utuh|tanpa\s+potongan/i', $text)) {
            return $text;
        }
        $aksi = (string) ($item['aksi'] ?? '');
        $available = (float) ($item['available'] ?? 0);
        $qtyBooking = (float) ($item['qty_booking'] ?? 0);

        return match ($aksi) {
            'full_booking' => 'stok tersedia mencukupi sehingga kebutuhan bisa langsung dibooking', 'partial' => 'stok tersedia hanya mencukupi sebagian, sehingga sisanya perlu dibuatkan PR', 'full_pr' => $available > 0 && $qtyBooking <= 0 ? 'stok yang tersedia belum memenuhi syarat kebutuhan item ini, sehingga dialihkan ke PR' : 'stok tersedia tidak mencukupi sehingga kebutuhan dialihkan ke PR', default => 'keputusan mengikuti kondisi stok aktual untuk material ini'
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
