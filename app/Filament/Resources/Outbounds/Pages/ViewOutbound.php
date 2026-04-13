<?php

namespace App\Filament\Resources\Outbounds\Pages;

use App\Filament\Resources\Outbounds\OutboundResource;
use App\Jobs\AnalyzeDeadstockAfterOutboundJob;
use App\Models\Outbound;
use App\Models\Project;
use App\Models\Task;
use App\Models\UserLog;
use App\Services\AI\DeadstockAIService;
use App\Services\AI\GeminiService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ViewOutbound extends Page
{
    protected static string $resource = OutboundResource::class;

    protected string $view = 'filament.resources.outbounds.pages.view-outbound';

    public Outbound $record;

    public string $currentDate = '';

    public function mount(Outbound $record): void
    {
        abort_unless($this->canAccessOutbound(auth()->user()?->role), 403);

        $this->record = $record->load([
            'project:id,kode_proyek,nama_proyek,status',
            'items:id,outbound_id,material_id,qty,unit',
            'items.material:id,nama_material,satuan',
        ]);

        $this->currentDate = now()->translatedFormat('d F Y');
    }

    public function getHeading(): string
    {
        $projectCode = $this->record->project?->kode_proyek ?: ('#' . $this->record->project_id);

        return 'Outbound - ' . $projectCode;
    }

    public function getSubheading(): ?string
    {
        return 'Kelola status outbound dari menunggu sampai barang diambil lengkap dengan bukti.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mark_ready')
                ->label('Tandai Siap')
                ->icon('heroicon-o-check-badge')
                ->color('info')
                ->visible(fn (): bool => $this->canPrepare() && $this->record->status === 'pending')
                ->requiresConfirmation()
                ->modalHeading('Tandai outbound siap diambil?')
                ->action(function (): void {
                    $this->record->update([
                        'status' => 'ready',
                        'ready_at' => now(),
                        'prepared_by' => auth()->id(),
                    ]);

                    Notification::make()
                        ->title('Outbound ditandai siap')
                        ->success()
                        ->send();

                    $this->record->refresh();
                }),
            Action::make('mark_taken')
                ->label('Konfirmasi Pengambilan')
                ->icon('heroicon-o-camera')
                ->color('success')
                ->visible(fn (): bool => $this->canTake() && $this->record->status === 'ready')
                ->form(function (): array {
                    $checklist = $this->buildTakeChecklist();

                    return [
                        TextInput::make('nama_pengambil')
                            ->label('Nama Pengambil di Lapangan')
                            ->required()
                            ->maxLength(120),
                        Textarea::make('catatan_pengambilan')
                            ->label('Catatan Pengambilan')
                            ->rows(3)
                            ->placeholder('Contoh: Barang potongan dipisah per rak, diterima oleh tim instalasi.'),
                        FileUpload::make('foto_bukti')
                            ->label('Foto Bukti Pengambilan')
                            ->image()
                            ->directory('outbound-proofs')
                            ->disk('public')
                            ->required(),
                        Hidden::make('all_check_ids')
                            ->default($checklist['ids']),
                        CheckboxList::make('checked_item_ids')
                            ->label('Checklist Barang yang Diambil')
                            ->helperText('Centang semua item sebelum submit konfirmasi pengambilan.')
                            ->options($checklist['options'])
                            ->allowHtml()
                            ->bulkToggleable(false)
                            ->columns(1)
                            ->required(),
                    ];
                })
                ->action(function (array $data): void {
                    $allCheckIds = collect($data['all_check_ids'] ?? [])->map(fn ($id) => (string) $id)->filter()->unique()->values();
                    $checkedIds = collect($data['checked_item_ids'] ?? [])->map(fn ($id) => (string) $id)->filter()->unique()->values();

                    if ($allCheckIds->isNotEmpty() && ($checkedIds->count() !== $allCheckIds->count() || $checkedIds->diff($allCheckIds)->isNotEmpty())) {
                        Notification::make()
                            ->title('Checklist belum lengkap')
                            ->body('Semua item barang harus dicentang sebelum outbound dikonfirmasi.')
                            ->warning()
                            ->send();

                        return;
                    }

                    DB::transaction(function () use ($data): void {
                        $this->record->update([
                            'status' => 'taken',
                            'taken_at' => now(),
                            'foto_bukti' => (string) $data['foto_bukti'],
                            'taken_by' => auth()->id(),
                        ]);

                        if (!$this->record->outbound_manual && $this->record->project_id) {
                            DB::table('material_pieces')
                                ->where('booked_for_project_id', $this->record->project_id)
                                ->where('status', 'booked')
                                ->update([
                                    'status' => 'used',
                                    'used_at' => now(),
                                    'updated_at' => now(),
                                ]);
                        }

                        $project = $this->record->project;
                        if ($project) {
                            $this->completeProjectIfAllTaken($project);
                        }

                        UserLog::create([
                            'user_id' => auth()->id(),
                            'action' => 'Outbound Taken',
                            'module' => 'Outbound',
                            'description' => 'Outbound #' . $this->record->id . ' dikonfirmasi diambil oleh ' . (string) ($data['nama_pengambil'] ?? '-') . '. Catatan: ' . (string) ($data['catatan_pengambilan'] ?? '-'),
                            'ip_address' => request()->ip(),
                        ]);
                    });

                    Notification::make()
                        ->title('Outbound berhasil dikonfirmasi diambil')
                        ->success()
                        ->send();

                    $outboundId = (int) $this->record->id;

                    try {
                        $taskId = (new DeadstockAIService(new GeminiService()))->analyzeAfterOutbound($outboundId);

                        if ($taskId) {
                            Notification::make()
                                ->title('Screening deadstock selesai')
                                ->body('Task AI deadstock berhasil dibuat otomatis.')
                                ->success()
                                ->send();
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Screening deadstock sync gagal, fallback ke queue', [
                            'outbound_id' => $outboundId,
                            'error' => $e->getMessage(),
                        ]);

                        AnalyzeDeadstockAfterOutboundJob::dispatchAfterResponse($outboundId);
                    }

                    $this->record->refresh();
                }),
        ];
    }

    public function getDisplayItems(): array
    {
        $this->record->loadMissing([
            'items.material:id,nama_material,satuan',
            'project.bookedPieces:id,booked_for_project_id,material_id,panjang,status,shelf_location_id',
            'project.bookedPieces.material:id,nama_material,satuan',
            'project.bookedPieces.shelfLocation:id,kode_lokasi,nama_lokasi',
        ]);

        if ($this->record->items->isNotEmpty()) {
            return $this->record->items
                ->map(fn ($item): array => [
                    'material' => (string) ($item->material?->nama_material ?? 'Material tidak diketahui'),
                    'qty' => $this->formatQty((float) $item->qty, (string) ($item->unit ?: ($item->material?->satuan ?: 'unit'))),
                    'required_qty' => $this->formatQty((float) $item->qty, (string) ($item->unit ?: ($item->material?->satuan ?: 'unit'))),
                    'provided_qty' => $this->formatQty((float) $item->qty, (string) ($item->unit ?: ($item->material?->satuan ?: 'unit'))),
                    'shortage_qty' => $this->formatQty(0, (string) ($item->unit ?: ($item->material?->satuan ?: 'unit'))),
                    'fulfillment_status' => 'Manual',
                    'racks' => 'Belum dipetakan (outbound manual)',
                    'is_potongan' => false,
                    'piece_details' => [],
                ])
                ->values()
                ->all();
        }

        $project = $this->record->project;
        if (!$project) {
            return [];
        }

        $requiredByMaterial = $project->items()
            ->with('material:id,nama_material,satuan')
            ->get()
            ->groupBy('material_id')
            ->map(function ($items): array {
                $first = $items->first();

                return [
                    'material' => (string) ($first?->material?->nama_material ?? 'Material tidak diketahui'),
                    'unit' => (string) ($first?->material?->satuan ?? 'unit'),
                    'required' => (float) $items->sum('jumlah_diminta'),
                ];
            });

        $piecesByMaterial = $project->bookedPieces
            ->whereIn('status', ['booked', 'used'])
            ->groupBy('material_id');

        $materialIds = $requiredByMaterial->keys()
            ->merge($piecesByMaterial->keys())
            ->unique()
            ->values();

        $instructionsByMaterial = $this->aiInstructionsByMaterial();

        return $materialIds
            ->map(function ($materialId) use ($requiredByMaterial, $piecesByMaterial, $instructionsByMaterial): array {
                $pieces = collect($piecesByMaterial->get($materialId, []));
                $first = $pieces->first();
                $requiredData = $requiredByMaterial->get($materialId, []);
                $unit = (string) ($requiredData['unit'] ?? ($first?->material?->satuan ?: 'unit'));
                $pieceCount = $pieces->count();
                $isPotongan = $this->isLengthUnit($unit) && $pieceCount > 1;
                $rackText = $pieces
                    ->map(fn ($piece) => (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-')))
                    ->filter(fn ($rack) => trim($rack) !== '')
                    ->unique()
                    ->values()
                    ->implode(', ');

                $pieceDetails = $pieces
                    ->map(function ($piece) use ($unit): string {
                        $rack = (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-'));
                        $qty = $this->formatQty((float) $piece->panjang, $unit);

                        return $qty . ' - Rak ' . $rack;
                    })
                    ->values()
                    ->all();

                $required = (float) ($requiredData['required'] ?? 0);
                $provided = (float) $pieces->sum('panjang');
                $shortage = max(0, round($required - $provided, 2));
                $status = $required <= 0
                    ? 'Tanpa Kebutuhan'
                    : ($shortage > 0 ? 'Kurang' : 'Lengkap');

                $instructions = $instructionsByMaterial->get((int) $materialId, []);
                $instructionLines = collect($instructions)
                    ->map(function (array $row): string {
                        $pieceId = (int) ($row['piece_id'] ?? 0);
                        $rack = (string) ($row['rack'] ?? '-');
                        $takeQty = (float) ($row['taken_qty'] ?? 0);
                        $original = (float) ($row['original_length'] ?? 0);
                        $remaining = (float) ($row['remaining_length'] ?? 0);

                        $text = 'Ambil piece #' . $pieceId . ' dari Rak ' . $rack
                            . ' (potong ' . $this->formatQty($takeQty, 'm')
                            . ' dari ' . $this->formatQty($original, 'm') . ')';

                        if ($remaining > 0) {
                            $text .= ' | Sisa: ' . $this->formatQty($remaining, 'm') . ' dikembalikan ke rak';
                        }

                        return $text;
                    })
                    ->values()
                    ->all();

                return [
                    'material' => (string) ($requiredData['material'] ?? ($first?->material?->nama_material ?: 'Material tidak diketahui')),
                    'qty' => $this->formatQty($provided, $unit),
                    'required_qty' => $this->formatQty($required, $unit),
                    'provided_qty' => $this->formatQty($provided, $unit),
                    'shortage_qty' => $this->formatQty($shortage, $unit),
                    'fulfillment_status' => $status,
                    'racks' => $rackText !== '' ? $rackText : '-',
                    'is_potongan' => $isPotongan,
                    'piece_details' => $isPotongan ? $pieceDetails : [],
                    'ai_pick_instructions' => $instructionLines,
                ];
            })
            ->values()
            ->all();
    }

    private function aiInstructionsByMaterial(): \Illuminate\Support\Collection
    {
        if (!$this->record->project_id) {
            return collect();
        }

        $task = Task::query()
            ->where('type', 'ai_booking_suggestion')
            ->where('related_type', 'project')
            ->where('related_id', (int) $this->record->project_id)
            ->where('status', 'done')
            ->latest('handled_at')
            ->latest('id')
            ->first();

        if (!$task) {
            return collect();
        }

        $results = collect(data_get($task->payload, 'execution_result.material_results', []));

        return $results
            ->filter(fn ($row) => is_array($row) && !empty($row['material_id']))
            ->mapWithKeys(function (array $row): array {
                return [
                    (int) ($row['material_id'] ?? 0) => collect($row['allocations'] ?? [])->values()->all(),
                ];
            });
    }

    /**
     * @return array{ids: array<int, string>, options: array<string, string>}
     */
    private function buildTakeChecklist(): array
    {
        $rows = $this->getDisplayItems();

        $options = collect($rows)
            ->values()
            ->mapWithKeys(function (array $row, int $index): array {
                $id = 'item-' . (string) $index;
                $material = (string) ($row['material'] ?? 'Material tidak diketahui');
                $qty = (string) ($row['qty'] ?? '-');
                $racks = (string) ($row['racks'] ?? '-');
                $type = (bool) ($row['is_potongan'] ?? false) ? 'Potongan' : 'Utuh';
                $status = (string) ($row['fulfillment_status'] ?? '-');

                return [
                    $id => '<span class="font-semibold">' . e($material) . '</span>'
                        . '<div class="mt-1 text-xs text-gray-500">'
                        . e($qty) . ' | Rak ' . e($racks) . ' | ' . e($type) . ' | Status ' . e($status)
                        . '</div>',
                ];
            })
            ->all();

        return [
            'ids' => array_keys($options),
            'options' => $options,
        ];
    }

    private function completeProjectIfAllTaken(Project $project): void
    {
        $remaining = $project->outbounds()->where('status', '!=', 'taken')->count();

        if ($remaining === 0) {
            $project->update([
                'status' => 'Completed',
                'completed_at' => now(),
            ]);
        }
    }

    private function canPrepare(): bool
    {
        return in_array(auth()->user()?->role, [
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
            'Administrator IT',
        ], true);
    }

    private function canTake(): bool
    {
        return in_array(auth()->user()?->role, [
            'Manager Teknik',
            'Manajer Teknik',
        ], true);
    }

    private function canAccessOutbound(?string $role): bool
    {
        return in_array($role, [
            'Manager Teknik',
            'Manajer Teknik',
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
            'Administrator IT',
        ], true);
    }

    private function formatQty(float $value, string $unit): string
    {
        $number = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return trim($number . ' ' . $unit);
    }

    private function isLengthUnit(string $unit): bool
    {
        $normalized = strtolower(trim($unit));

        return in_array($normalized, ['m', 'meter', 'meters', 'mtr'], true);
    }
}
