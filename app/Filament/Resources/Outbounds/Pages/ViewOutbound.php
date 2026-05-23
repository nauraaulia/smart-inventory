<?php

namespace App\Filament\Resources\Outbounds\Pages;

use App\Filament\Resources\Outbounds\Concerns\HandlesManualOutboundPieces;
use App\Filament\Resources\Outbounds\OutboundResource;
use App\Jobs\AnalyzeDeadstockAfterOutboundJob;
use App\Models\Material;
use App\Models\MaterialPiece;
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
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ViewOutbound extends Page
{
    use HandlesManualOutboundPieces;

    protected static string $resource = OutboundResource::class;

    protected string $view = 'filament.resources.outbounds.pages.view-outbound';

    public Outbound $record;

    public string $currentDate = '';

    public function mount(Outbound $record): void
    {
        abort_unless($this->canAccessOutbound(auth()->user()?->role), 403);

        $this->record = $record->load([
            'project:id,kode_proyek,nama_proyek,status',
            'items:id,outbound_id,material_id,qty,unit,selected_piece_ids',
            'items.material:id,nama_material,satuan',
        ]);

        $this->currentDate = now()->translatedFormat('d F Y');
    }

    public function getHeading(): string
    {
        $projectCode = $this->record->project?->kode_proyek ?: ('#'.$this->record->project_id);

        return 'Outbound - '.$projectCode;
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label('Close')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->url(OutboundResource::getUrl('index')),
            Action::make('map_manual_pieces')
                ->label('Pilih Pieces')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('warning')
                ->visible(fn (): bool => $this->canPrepare() && $this->record->outbound_manual && $this->record->status !== 'taken')
                ->modalHeading('Pilih pieces untuk outbound manual')
                ->modalWidth('5xl')
                ->form(fn (): array => [
                    Repeater::make('items')
                        ->label('Material outbound')
                        ->schema([
                            Hidden::make('id'),
                            Hidden::make('material_id'),
                            Hidden::make('unit'),
                            TextInput::make('material_name')
                                ->label('Material')
                                ->disabled()
                                ->dehydrated(false),
                            Select::make('selected_piece_ids')
                                ->label('Pilih Piece / Item')
                                ->multiple()
                                ->searchable()
                                ->required()
                                ->options(fn (Get $get): array => $this->manualOutboundPieceOptions(
                                    (int) ($get('material_id') ?? 0),
                                    $this->manualOutboundPieceIds($get('selected_piece_ids') ?? []),
                                ))
                                ->live()
                                ->afterStateUpdated(function (Set $set, $state): void {
                                    $set('qty', $this->manualOutboundSelectedQty($state));
                                }),
                            TextInput::make('qty')
                                ->label('Qty Total Piece')
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->required()
                                ->minValue(0.01),
                        ])
                        ->columns(2)
                        ->default(fn (): array => $this->manualOutboundItemFormRows())
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->minItems(1)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $items = $this->normalizeManualOutboundItemRows($data['items'] ?? []);

                    if ($items === null) {
                        return;
                    }

                    DB::transaction(function () use ($items): void {
                        foreach ($items as $row) {
                            $itemId = (int) ($row['id'] ?? 0);

                            if ($itemId <= 0) {
                                continue;
                            }

                            $this->record->items()
                                ->whereKey($itemId)
                                ->update([
                                    'qty' => (string) $row['qty'],
                                    'unit' => (string) $row['unit'],
                                    'selected_piece_ids' => $row['selected_piece_ids'],
                                ]);
                        }
                    });

                    Notification::make()
                        ->title('Pieces outbound manual tersimpan')
                        ->success()
                        ->send();

                    $this->record->refresh();
                }),
            Action::make('mark_ready')
                ->label('Tandai Siap')
                ->icon('heroicon-o-check-badge')
                ->color('info')
                ->visible(fn (): bool => $this->canPrepare() && $this->record->status === 'pending' && $this->canProceedOutbound())
                ->requiresConfirmation()
                ->modalHeading('Tandai outbound siap diambil?')
                ->action(function (): void {
                    if (! $this->manualOutboundPiecesAreUsable()) {
                        return;
                    }

                    if (! $this->canProceedOutbound()) {
                        Notification::make()
                            ->title('Outbound belum bisa diproses')
                            ->body('Status barang masih kurang. Lengkapi kebutuhan material terlebih dahulu.')
                            ->warning()
                            ->send();

                        return;
                    }

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
                    if (! $this->manualOutboundPiecesAreUsable()) {
                        return;
                    }

                    if (! $this->canProceedOutbound()) {
                        Notification::make()
                            ->title('Konfirmasi ditolak')
                            ->body('Status barang masih kurang, outbound belum bisa dikonfirmasi pengambilan.')
                            ->warning()
                            ->send();

                        return;
                    }

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

                        if ($this->record->outbound_manual) {
                            $this->markManualOutboundPiecesUsed();
                        } elseif ($this->record->project_id) {
                            $this->normalizeBookedPiecesBeforeTake((int) $this->record->project_id);

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
                            'description' => 'Outbound #'.$this->record->id.' dikonfirmasi diambil oleh '.(string) ($data['nama_pengambil'] ?? '-').'. Catatan: '.(string) ($data['catatan_pengambilan'] ?? '-'),
                            'ip_address' => request()->ip(),
                        ]);
                    });

                    Notification::make()
                        ->title('Outbound berhasil dikonfirmasi diambil')
                        ->success()
                        ->send();

                    $outboundId = (int) $this->record->id;

                    try {
                        $taskId = (new DeadstockAIService(new GeminiService))->analyzeAfterOutbound($outboundId);

                        if ($taskId) {
                            $deadstockTask = Task::query()->find($taskId);
                            $hasDeadstock = (bool) data_get($deadstockTask?->payload, 'has_deadstock', true);

                            Notification::make()
                                ->title('Screening deadstock selesai')
                                ->body($hasDeadstock
                                    ? 'Task AI deadstock berhasil dibuat otomatis.'
                                    : 'Tidak ditemukan deadstock. Task informasi tetap dibuat untuk jejak screening.')
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
            'items.material:id,nama_material,satuan,default_roll_length',
            'project:id,kode_proyek,nama_proyek,status',
            'project.bookedPieces:id,booked_for_project_id,material_id,panjang,status,shelf_location_id',
            'project.bookedPieces.material:id,nama_material,satuan,default_roll_length',
            'project.bookedPieces.shelfLocation:id,kode_lokasi,nama_lokasi',
        ]);

        if ($this->record->items->isNotEmpty()) {
            $selectedIds = $this->record->items
                ->flatMap(fn ($item) => $this->manualOutboundPieceIds($item->selected_piece_ids ?? []))
                ->unique()
                ->values()
                ->all();

            $selectedPiecesById = empty($selectedIds)
                ? collect()
                : MaterialPiece::query()
                    ->with([
                        'bookedForProject:id,kode_proyek',
                        'material:id,nama_material,satuan,default_roll_length',
                        'shelfLocation:id,kode_lokasi,nama_lokasi',
                    ])
                    ->whereIn('id', $selectedIds)
                    ->get(['id', 'piece_code', 'material_id', 'panjang', 'status', 'shelf_location_id', 'booked_for_project_id'])
                    ->keyBy('id');

            return $this->record->items
                ->map(function ($item) use ($selectedPiecesById): array {
                    $unit = (string) ($item->material?->resolveTrackingUnit() ?: $item->unit ?: 'unit');
                    $itemLabel = Material::usesDiscreteUnitTrackingFor($unit, $item->material?->default_roll_length) ? 'Item' : 'Piece';
                    $pieceIds = $this->manualOutboundPieceIds($item->selected_piece_ids ?? []);
                    $pieces = collect($pieceIds)
                        ->map(fn (int $id) => $selectedPiecesById->get($id))
                        ->filter();
                    $selectedQty = round((float) $pieces->sum('panjang'), 2);
                    $displayQty = $selectedQty > 0 ? $selectedQty : (float) $item->qty;
                    $rackText = $pieces
                        ->map(fn ($piece) => (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-')))
                        ->filter(fn (string $rack): bool => trim($rack) !== '')
                        ->unique()
                        ->values()
                        ->implode(', ');
                    $pieceDetails = $pieces
                        ->map(fn (MaterialPiece $piece): string => $this->manualOutboundPieceDetailLine($piece, $unit, $itemLabel))
                        ->values()
                        ->all();

                    return [
                        'material' => (string) ($item->material?->nama_material ?? 'Material tidak diketahui'),
                        'qty' => $this->formatQty($displayQty, $unit),
                        'required_qty' => $this->formatQty((float) $item->qty, $unit),
                        'provided_qty' => $this->formatQty($displayQty, $unit),
                        'shortage_qty' => $this->formatQty(0, $unit),
                        'fulfillment_status' => 'Manual',
                        'racks' => $rackText !== '' ? $rackText : 'Belum dipetakan (piece belum dipilih)',
                        'is_potongan' => false,
                        'type_label' => $pieces->isNotEmpty() ? 'Pieces Dipilih' : 'Utuh',
                        'piece_badge' => $pieces->isNotEmpty() ? 'Pieces Dipilih' : null,
                        'piece_details' => $pieceDetails,
                    ];
                })
                ->values()
                ->all();
        }

        $project = $this->record->project;
        if (! $project) {
            return [];
        }

        $requiredByMaterial = $project->items()
            ->with('material:id,nama_material,satuan,default_roll_length')
            ->get()
            ->groupBy('material_id')
            ->map(function ($items): array {
                $first = $items->first();

                return [
                    'material' => (string) ($first?->material?->nama_material ?? 'Material tidak diketahui'),
                    'unit' => (string) ($first?->material?->resolveTrackingUnit() ?? 'unit'),
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
                $unit = (string) ($requiredData['unit'] ?? ($first?->material?->resolveTrackingUnit() ?: 'unit'));
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

                        return $qty.' - Rak '.$rack;
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

                        $text = 'Ambil piece #'.$pieceId.' dari Rak '.$rack
                            .' (potong '.$this->formatQty($takeQty, 'm')
                            .' dari '.$this->formatQty($original, 'm').')';

                        if ($remaining > 0) {
                            $text .= ' | Sisa: '.$this->formatQty($remaining, 'm').' dikembalikan ke rak';
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manualOutboundItemFormRows(): array
    {
        $this->record->loadMissing('items.material:id,nama_material,satuan,default_roll_length');

        return $this->record->items
            ->map(function ($item): array {
                $unit = (string) ($item->material?->resolveTrackingUnit() ?: $item->unit ?: 'unit');

                return [
                    'id' => (int) $item->id,
                    'material_id' => (int) $item->material_id,
                    'material_name' => (string) ($item->material?->nama_material ?? 'Material tidak diketahui'),
                    'unit' => $unit,
                    'selected_piece_ids' => $this->manualOutboundPieceIds($item->selected_piece_ids ?? []),
                    'qty' => number_format((float) $item->qty, 2, '.', ''),
                ];
            })
            ->values()
            ->all();
    }

    private function manualOutboundPiecesAreUsable(): bool
    {
        if (! $this->record->outbound_manual) {
            return true;
        }

        $this->record->loadMissing('items');

        if ($this->record->items->isEmpty()) {
            Notification::make()
                ->title('Item outbound belum tersedia')
                ->body('Tambahkan item outbound manual terlebih dahulu.')
                ->warning()
                ->send();

            return false;
        }

        $missingSelection = $this->record->items
            ->contains(fn ($item): bool => empty($this->manualOutboundPieceIds($item->selected_piece_ids ?? [])));

        if ($missingSelection) {
            Notification::make()
                ->title('Pieces belum dipilih')
                ->body('Klik tombol Pilih Pieces untuk memetakan barang manual ke piece/rak fisik.')
                ->warning()
                ->send();

            return false;
        }

        $ids = $this->record->items
            ->flatMap(fn ($item) => $this->manualOutboundPieceIds($item->selected_piece_ids ?? []))
            ->unique()
            ->values()
            ->all();

        $pieces = MaterialPiece::query()
            ->whereIn('id', $ids)
            ->get(['id', 'status'])
            ->keyBy('id');

        $missingIds = collect($ids)
            ->reject(fn (int $id): bool => $pieces->has($id))
            ->values()
            ->all();

        if (! empty($missingIds)) {
            Notification::make()
                ->title('Piece tidak ditemukan')
                ->body('Piece #'.implode(', #', $missingIds).' tidak ada di stok.')
                ->warning()
                ->send();

            return false;
        }

        $invalidPieces = $pieces
            ->filter(fn (MaterialPiece $piece): bool => ! in_array((string) $piece->status, ['available', 'booked'], true));

        if ($invalidPieces->isNotEmpty()) {
            Notification::make()
                ->title('Piece sudah tidak bisa dipakai')
                ->body('Piece #'.$invalidPieces->keys()->implode(', #').' sudah dipakai/rusak. Pilih ulang pieces outbound manual.')
                ->warning()
                ->send();

            return false;
        }

        return true;
    }

    private function markManualOutboundPiecesUsed(): void
    {
        if (! $this->record->outbound_manual) {
            return;
        }

        $this->record->loadMissing('items');

        $ids = $this->record->items
            ->flatMap(fn ($item) => $this->manualOutboundPieceIds($item->selected_piece_ids ?? []))
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return;
        }

        $pieces = MaterialPiece::query()
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get();

        foreach ($pieces as $piece) {
            $piece->update([
                'status' => 'used',
                'used_at' => now(),
                'booked_for_project_id' => $piece->booked_for_project_id ?: $this->record->project_id,
                'booked_at' => $piece->booked_at ?: now(),
            ]);
        }
    }

    private function manualOutboundPieceDetailLine(MaterialPiece $piece, string $unit, string $itemLabel): string
    {
        $qty = $this->formatQty((float) $piece->panjang, $unit);
        $rack = (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-'));
        $code = trim((string) ($piece->piece_code ?: ''));
        $codeText = $code !== '' ? " ({$code})" : '';
        $statusText = match ((string) $piece->status) {
            'available' => 'Available',
            'booked' => 'Booked'.($piece->bookedForProject?->kode_proyek ? ' - '.$piece->bookedForProject->kode_proyek : ''),
            'used' => 'Used',
            'damaged' => 'Rusak',
            default => ucfirst((string) $piece->status),
        };

        return "{$itemLabel} #{$piece->id}{$codeText}: {$qty} - Rak {$rack} - {$statusText}";
    }

    private function aiInstructionsByMaterial(): Collection
    {
        if (! $this->record->project_id) {
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

        if (! $task) {
            return collect();
        }

        $results = collect(data_get($task->payload, 'execution_result.material_results', []));

        return $results
            ->filter(fn ($row) => is_array($row) && ! empty($row['material_id']))
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
                $id = 'item-'.(string) $index;
                $material = (string) ($row['material'] ?? 'Material tidak diketahui');
                $qty = (string) ($row['qty'] ?? '-');
                $racks = (string) ($row['racks'] ?? '-');
                $type = (string) ($row['type_label'] ?? ((bool) ($row['is_potongan'] ?? false) ? 'Potongan' : 'Utuh'));
                $status = (string) ($row['fulfillment_status'] ?? '-');

                return [
                    $id => '<span class="font-semibold">'.e($material).'</span>'
                        .'<div class="mt-1 text-xs text-gray-500">'
                        .e($qty).' | Rak '.e($racks).' | '.e($type).' | Status '.e($status)
                        .'</div>',
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
            \App\Models\User::ROLE_GENERAL_MANAGER,
        ], true);
    }

    private function canProceedOutbound(): bool
    {
        if ($this->record->outbound_manual || ! $this->record->project_id) {
            return true;
        }

        $requiredRows = $this->record->project?->items()
            ->selectRaw('material_id, SUM(jumlah_diminta) as required_qty')
            ->groupBy('material_id')
            ->get();

        if (! $requiredRows || $requiredRows->isEmpty()) {
            return true;
        }

        $providedMap = MaterialPiece::query()
            ->selectRaw('material_id, SUM(panjang) as provided_qty')
            ->where('booked_for_project_id', (int) $this->record->project_id)
            ->whereIn('status', ['booked', 'used'])
            ->groupBy('material_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->material_id => (float) $row->provided_qty]);

        $hasShortage = $requiredRows->contains(function ($row) use ($providedMap): bool {
            $required = (float) ($row->required_qty ?? 0);
            $provided = (float) ($providedMap[(int) ($row->material_id ?? 0)] ?? 0);

            return $required > 0 && $provided + 0.00001 < $required;
        });

        return ! $hasShortage;
    }

    private function formatQty(float $value, string $unit): string
    {
        $number = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return trim($number.' '.$unit);
    }

    private function isLengthUnit(string $unit): bool
    {
        $normalized = strtolower(trim($unit));

        return in_array($normalized, ['m', 'meter', 'meters', 'mtr'], true);
    }

    private function normalizeBookedPiecesBeforeTake(int $projectId): void
    {
        if ($projectId <= 0) {
            return;
        }

        $pieces = MaterialPiece::query()
            ->with('material:id,kategori,default_roll_length')
            ->where('booked_for_project_id', $projectId)
            ->where('status', 'booked')
            ->lockForUpdate()
            ->get();

        foreach ($pieces as $piece) {
            $rollLength = $this->resolveOutboundRollLength($piece);
            $pieceLength = (float) $piece->panjang;

            if ($rollLength <= 0 || $pieceLength <= ($rollLength + 0.00001)) {
                continue;
            }

            $fullRollCount = (int) floor($pieceLength / $rollLength);
            $remainder = round($pieceLength - ($fullRollCount * $rollLength), 2);

            if ($fullRollCount <= 0) {
                continue;
            }

            $piece->update([
                'panjang' => number_format($rollLength, 2, '.', ''),
                'keterangan' => trim((string) ($piece->keterangan ?? '').' | Auto split outbound per roll.'),
            ]);

            for ($i = 1; $i < $fullRollCount; $i++) {
                MaterialPiece::create([
                    'material_id' => $piece->material_id,
                    'inbound_id' => $piece->inbound_id,
                    'booked_for_project_id' => $piece->booked_for_project_id,
                    'shelf_location_id' => $piece->shelf_location_id,
                    'supplier_id' => $piece->supplier_id,
                    'panjang' => number_format($rollLength, 2, '.', ''),
                    'unit_price' => $piece->unit_price,
                    'status' => 'booked',
                    'sumber' => 'split_outbound_dispatch',
                    'booked_at' => $piece->booked_at,
                    'keterangan' => 'Auto split outbound dari piece #'.$piece->id.'.',
                ]);
            }

            if ($remainder > 0) {
                MaterialPiece::create([
                    'material_id' => $piece->material_id,
                    'inbound_id' => $piece->inbound_id,
                    'booked_for_project_id' => $piece->booked_for_project_id,
                    'shelf_location_id' => $piece->shelf_location_id,
                    'supplier_id' => $piece->supplier_id,
                    'panjang' => number_format($remainder, 2, '.', ''),
                    'unit_price' => $piece->unit_price,
                    'status' => 'booked',
                    'sumber' => 'split_outbound_remainder',
                    'booked_at' => $piece->booked_at,
                    'keterangan' => 'Sisa split outbound dari piece #'.$piece->id.'.',
                ]);
            }
        }
    }

    private function resolveOutboundRollLength(MaterialPiece $piece): float
    {
        $configured = (float) ($piece->material?->default_roll_length ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        $kategori = strtolower(trim((string) ($piece->material?->kategori ?? '')));
        if ($kategori !== '' && str_contains($kategori, 'kabel')) {
            return 300.0;
        }

        return 0.0;
    }
}
