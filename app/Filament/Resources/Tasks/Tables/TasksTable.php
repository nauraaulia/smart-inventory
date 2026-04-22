<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\Task;
use App\Services\AI\TaskApprovalService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('1s')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 'Task #'.$state),

                TextColumn::make('for_role')
                    ->label('Untuk')
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(function ($state, $record) {
                        if (! $record) {
                            return $state;
                        }
                        if ($record->type === 'ai_inbound_allocation_suggestion') {
                            return 'Alokasi Inbound';
                        }
                        if ($record->type === 'ai_deadstock_recommendation') {
                            return 'Screening Deadstock';
                        }

                        return $state;
                    }),

                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->formatStateUsing(function ($state, $record) {
                        if (! $record) {
                            return $state;
                        }
                        if ($record->type === 'ai_inbound_allocation_suggestion') {
                            return 'Usulan Alokasi Inbound';
                        }
                        if ($record->type === 'ai_deadstock_recommendation') {
                            return $state ?: 'AI: Screening Deadstock';
                        }
                        if (str_starts_with($record->type, 'ai_')) {
                            return $state ?: 'AI: Konfirmasi Kebutuhan Material Proyek';
                        }

                        return $state;
                    }),

                BadgeColumn::make('status')
                    ->label('Status Task')
                    ->formatStateUsing(fn (string $state, Task $record): string => match ($record->visualStatus()) {
                        'pending' => 'Pending',
                        'done' => 'Done',
                        'rejected' => 'Rejected',
                        default => ucfirst($record->visualStatus()),
                    })
                    ->colors([
                        'warning' => fn (Task $record): bool => $record->visualStatus() === 'pending',
                        'success' => fn (Task $record): bool => $record->visualStatus() === 'done',
                        'danger' => fn (Task $record): bool => $record->visualStatus() === 'rejected',
                        'gray',
                    ]),

                TextColumn::make('project_code')
                    ->label('Kode Proyek')
                    ->searchable()
                    ->getStateUsing(function (Task $record): string {
                        if ($record->type === 'ai_deadstock_recommendation') {
                            return '';
                        }

                        return (string) ($record->project_code ?? '');
                    })
                    ->placeholder('-')
                    ->hidden(fn ($livewire) => $livewire->activeTab === 'operasional'),

                TextColumn::make('acc_staff')
                    ->label('Decision')
                    ->getStateUsing(fn (Task $record, $livewire): string => self::resolveDecisionLabel($record, $livewire->activeTab ?? null))
                    ->badge()
                    ->color(fn (string $state): string => self::resolveDecisionColor($state)),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                ViewAction::make(),

                EditAction::make()
                    ->visible(fn (Task $record) => $record->visualStatus() === 'pending'
                        && self::canHandleTask($record)
                        && ! in_array($record->type, ['ai_booking_suggestion', 'ai_inbound_allocation_suggestion'], true)),

                Action::make('updateStatus')
                    ->label('Update Status')
                    ->color('primary')
                    ->icon('heroicon-o-pencil-square')
                    ->modalSubmitActionLabel('Save Changes')
                    ->visible(fn (Task $record) => $record->visualStatus() === 'pending'
                        && self::canHandleTask($record)
                        && ! ($record->type === 'ai_deadstock_recommendation'
                            && (string) data_get($record->payload, 'workflow_state', '') === 'approved_for_aging'))
                    ->form(function (Task $record): array {
                        if ($record->type === 'ai_booking_suggestion') {
                            $items = collect(data_get($record->payload, 'items', []));

                            $materialRows = $items->map(function (array $item): array {
                                $materialId = (int) ($item['material_id'] ?? 0);
                                $material = $materialId > 0 ? Material::query()->find($materialId) : null;
                                $satuan = (string) ($material?->resolveTrackingUnit() ?: ($item['satuan'] ?? 'unit'));
                                $isDiscreteUnit = Material::usesDiscreteUnitTrackingFor($satuan, $material?->default_roll_length);
                                $itemLabel = $isDiscreteUnit ? 'Item' : 'Piece';
                                $recommendedPieces = collect($item['recommended_pieces'] ?? [])
                                    ->map(function (array $piece) use ($satuan, $isDiscreteUnit, $itemLabel): string {
                                        $id = (int) ($piece['id'] ?? 0);
                                        $total = rtrim(rtrim(number_format((float) ($piece['panjang'] ?? 0), 2, '.', ''), '0'), '.');
                                        $take = rtrim(rtrim(number_format((float) ($piece['take_qty'] ?? 0), 2, '.', ''), '0'), '.');
                                        $sisa = rtrim(rtrim(number_format((float) ($piece['panjang'] ?? 0) - (float) ($piece['take_qty'] ?? 0), 2, '.', ''), '0'), '.');
                                        $rack = (string) ($piece['rack_label'] ?? '-');
                                        $age = (int) ($piece['age_days'] ?? 0);

                                        $text = "{$itemLabel} #{$id} | {$total} {$satuan} | Rak {$rack} | Masuk {$age} hari lalu";
                                        if ((float) ($piece['take_qty'] ?? 0) < (float) ($piece['panjang'] ?? 0)) {
                                            if ($isDiscreteUnit) {
                                                $text .= " | Ambil: {$take} {$satuan}, Sisa: {$sisa} {$satuan}";
                                            } else {
                                                $text .= " | Potong: {$take} {$satuan}, Sisa: {$sisa} {$satuan}";
                                            }
                                        } else {
                                            $text .= ' | Ambil utuh';
                                        }

                                        return $text;
                                    })
                                    ->implode("\n");

                                return [
                                    'material_id' => $materialId,
                                    'material_name' => (string) ($item['material_name'] ?? '-'),
                                    'dibutuhkan' => (float) ($item['dibutuhkan'] ?? 0),
                                    'satuan' => $satuan,
                                    'recommended_piece_ids' => json_encode($item['recommended_piece_ids'] ?? []),
                                    'recommended_qty' => (float) ($item['recommended_qty'] ?? 0),
                                    'qty_pr' => max(0, round(
                                        (float) ($item['dibutuhkan'] ?? 0) - (float) ($item['recommended_qty'] ?? 0),
                                        2
                                    )),
                                    'recommended_pieces_text' => $recommendedPieces !== '' ? $recommendedPieces : '-',
                                    'ai_reason' => (string) ($item['ai_reason'] ?? '-'),
                                    'status' => 'pending',
                                ];
                            })->values()->all();

                            return [
                                Toggle::make('generate_pr')
                                    ->label('Jika ada kekurangan stok, generate PR otomatis')
                                    ->default(true)
                                    ->inline(false),
                                Repeater::make('material_decisions')
                                    ->label('Approval per Material')
                                    ->schema([
                                        Hidden::make('material_id')->required(),
                                        Hidden::make('recommended_piece_ids'),
                                        TextInput::make('material_name')
                                            ->label('Material')
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('dibutuhkan')
                                            ->label('Qty Dibutuhkan')
                                            ->suffix(fn (Get $get): string => (string) ($get('satuan') ?: 'unit'))
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('recommended_qty')
                                            ->label('Qty Booking')
                                            ->suffix(fn (Get $get): string => (string) ($get('satuan') ?: 'unit'))
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('qty_pr')
                                            ->label('Qty PR')
                                            ->suffix(fn (Get $get): string => (string) ($get('satuan') ?: 'unit'))
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('recommended_pieces_text')
                                            ->label('Detail Piece')
                                            ->disabled()
                                            ->dehydrated(false),
                                        Textarea::make('ai_reason')
                                            ->label('Analisis AI')
                                            ->rows(2)
                                            ->disabled()
                                            ->dehydrated(false),
                                        Select::make('status')
                                            ->label('Keputusan')
                                            ->options([
                                                'pending' => 'Pending',
                                                'approved' => 'Approve',
                                                'rejected' => 'Tolak',
                                            ])
                                            ->live()
                                            ->default('pending')
                                            ->required(),
                                        Textarea::make('rejection_reason')
                                            ->label('Alasan Penolakan')
                                            ->rows(2)
                                            ->visible(fn (Get $get): bool => (string) ($get('status') ?? 'pending') === 'rejected')
                                            ->required(fn (Get $get): bool => (string) ($get('status') ?? 'pending') === 'rejected')
                                            ->columnSpanFull(),
                                        Hidden::make('satuan')->dehydrated(false),
                                    ])
                                    ->columns(2)
                                    ->default($materialRows)
                                    ->addable(false)
                                    ->deletable(false)
                                    ->reorderable(false)
                                    ->minItems(1),
                            ];
                        }
                        if ($record->type === 'ai_inbound_allocation_suggestion') {
                            $suggestions = collect(data_get($record->payload, 'allocation_suggestions', []));
                            $materialId = (int) data_get($record->payload, 'material_id');

                            $availablePieces = $materialId > 0
                                ? MaterialPiece::query()
                                    ->with(['shelfLocation:id,kode_lokasi,nama_lokasi', 'inbound:id,entry_date', 'material:id,satuan,default_roll_length'])
                                    ->where('material_id', $materialId)
                                    ->where('status', 'available')
                                    ->orderByRaw("CASE
                                        WHEN sumber IN ('split_auto_allocation', 'split_booking', 'split_booking_residue') THEN 1
                                        WHEN sumber IN ('inbound_baru', 'opening_balance') THEN 2
                                        ELSE 3
                                    END ASC")
                                    ->orderBy('created_at')
                                    ->get(['id', 'panjang', 'sumber', 'shelf_location_id', 'inbound_id', 'created_at'])
                                : collect();

                            $defaultRows = $suggestions->map(fn ($s) => [
                                'selected' => true,
                                'project_id' => (int) ($s['project_id'] ?? 0),
                                'project_code' => (string) ($s['project_code'] ?? ('#'.($s['project_id'] ?? '-'))),
                                'pr_status' => (string) ($s['pr_status'] ?? '-'),
                                'qty' => (float) ($s['suggested_qty'] ?? 0),
                            ])->values()->all();
                            $pieceOptions = $availablePieces
                                ->mapWithKeys(function (MaterialPiece $piece): array {
                                    $id = (int) $piece->id;
                                    $length = rtrim(rtrim(number_format((float) $piece->panjang, 2, '.', ''), '0'), '.');
                                    $rack = (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-'));
                                    $source = (string) ($piece->sumber ?: '-');
                                    $unit = (string) ($piece->material?->satuan ?: 'unit');
                                    $itemLabel = Material::usesDiscreteUnitTrackingFor($unit, $piece->material?->default_roll_length) ? 'Item' : 'Piece';
                                    $receivedAt = $piece->inbound?->entry_date
                                        ? Carbon::parse($piece->inbound->entry_date)
                                        : $piece->created_at;
                                    $age = $receivedAt ? (int) $receivedAt->diffInDays(now()) : 0;

                                    if ($id <= 0) {
                                        return [];
                                    }

                                    return [
                                        $id => "{$itemLabel} #{$id} | {$length} {$unit} | Rak {$rack} | {$source} | Masuk {$age} hari lalu",
                                    ];
                                })
                                ->all();

                            return [
                                Select::make('new_status')
                                    ->label('Decision')
                                    ->options([
                                        'approved' => 'Approve',
                                        'rejected' => 'Reject',
                                    ])
                                    ->default('approved')
                                    ->live()
                                    ->required(),
                                Textarea::make('reason')
                                    ->label('Rejection Reason')
                                    ->rows(3)
                                    ->visible(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'rejected')
                                    ->required(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'rejected'),
                                Select::make('selected_piece_ids')
                                    ->label('Pilih Piece yang Boleh Dipakai')
                                    ->multiple()
                                    ->searchable()
                                    ->required()
                                    ->visible(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'approved')
                                    ->dehydrated(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'approved')
                                    ->options($pieceOptions)
                                    ->default([]),
                                Toggle::make('add_purchase_request')
                                    ->label('Tambahkan Purchase Request')
                                    ->default(false)
                                    ->inline(false)
                                    ->visible(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'approved')
                                    ->dehydrated(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'approved'),
                                Repeater::make('manual_allocation')
                                    ->label('Checklist Alokasi Proyek')
                                    ->helperText('Kebutuhan proyek sudah dihitung sistem. Centang proyek yang ingin dialokasikan sekarang; sisanya tetap menjadi stok gudang available.')
                                    ->visible(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'approved')
                                    ->dehydrated(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'approved')
                                    ->schema([
                                        Hidden::make('project_id')
                                            ->required(),
                                        Toggle::make('selected')
                                            ->label('Alokasikan ke proyek ini')
                                            ->default(true)
                                            ->columnSpan(2)
                                            ->inline(false),
                                        TextInput::make('project_code')
                                            ->label('Proyek')
                                            ->columnSpan(4)
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('pr_status')
                                            ->label('Status PR')
                                            ->columnSpan(3)
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('qty')
                                            ->label('Kebutuhan Qty')
                                            ->numeric()
                                            ->minValue(0)
                                            ->columnSpan(3)
                                            ->required()
                                            ->dehydrated(),
                                    ])
                                    ->columns(12)
                                    ->default($defaultRows)
                                    ->addable(false)
                                    ->deletable(false)
                                    ->reorderable(false)
                                    ->minItems(1)
                                    ->maxItems(10),
                            ];
                        }
                        if ($record->type === 'ai_deadstock_recommendation') {
                            return [
                                Select::make('new_status')
                                    ->label('Decision')
                                    ->options([
                                        'approved' => 'Approve',
                                        'rejected' => 'Reject',
                                    ])
                                    ->default('approved')
                                    ->live()
                                    ->required(),
                                Textarea::make('deadstock_flow_note')
                                    ->label('Info Alur')
                                    ->default('Approve di sini hanya untuk ACC screening AI. Keputusan final per material (termasuk qty keep/dispose) dilakukan di menu Aging Material.')
                                    ->visible(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'approved')
                                    ->disabled()
                                    ->dehydrated(false),
                                Textarea::make('reason')
                                    ->label('Rejection Reason')
                                    ->rows(3)
                                    ->visible(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'rejected')
                                    ->required(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'rejected'),
                            ];
                        }

                        return [
                            Select::make('new_status')
                                ->label('Decision')
                                ->options([
                                    'approved' => 'Approve',
                                    'rejected' => 'Reject',
                                ])
                                ->default('approved')
                                ->live()
                                ->required(),
                            Textarea::make('reason')
                                ->label('Rejection Reason')
                                ->rows(3)
                                ->visible(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'rejected')
                                ->required(fn (Get $get): bool => (string) ($get('new_status') ?? 'approved') === 'rejected'),
                        ];
                    })
                    ->action(function (Task $record, array $data, $livewire) {
                        $service = new TaskApprovalService;
                        $redirectUrl = TaskResource::getUrl('index');

                        if ($record->type === 'ai_booking_suggestion') {
                            Notification::make()
                                ->title('Sedang Memproses Booking & PR')
                                ->body('AI sedang diproses. Mohon tunggu sekitar 10-30 detik.')
                                ->info()
                                ->send();

                            $materialDecisions = collect($data['material_decisions'] ?? []);

                            if ($materialDecisions->contains(fn ($row) => ($row['status'] ?? 'pending') === 'pending')) {
                                Notification::make()
                                    ->title('Masih ada material pending')
                                    ->body('Silakan approve atau tolak semua material terlebih dahulu.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $service->approve($record, auth()->user(), [
                                'generate_pr' => (bool) ($data['generate_pr'] ?? true),
                                'rejection_reason' => trim((string) ($data['rejection_reason'] ?? '')),
                                'material_decisions' => $materialDecisions
                                    ->map(function (array $row): array {
                                        $row['recommended_piece_ids'] = json_decode((string) ($row['recommended_piece_ids'] ?? '[]'), true) ?: [];
                                        $row['rejection_reason'] = trim((string) ($row['rejection_reason'] ?? ''));

                                        return $row;
                                    })
                                    ->values()
                                    ->all(),
                            ]);

                            Notification::make()
                                ->title('Proses Selesai')
                                ->body('Approval booking/PR selesai diproses.')
                                ->success()
                                ->send();
                        } elseif ($record->type === 'ai_inbound_allocation_suggestion') {
                            if ((string) ($data['new_status'] ?? 'approved') === 'rejected') {
                                $service->reject($record, auth()->user(), (string) ($data['reason'] ?? 'Rejected'));

                                Notification::make()
                                    ->title('Task Rejected')
                                    ->body('Task operasional berhasil ditolak.')
                                    ->success()
                                    ->send();

                                $livewire->redirect($redirectUrl, navigate: true);

                                return;
                            }

                            Notification::make()
                                ->title('Sedang Memproses Task Operasional')
                                ->body('Alokasi inbound sedang diproses. Mohon tunggu sebentar.')
                                ->info()
                                ->send();

                            $service->approve($record, auth()->user(), [
                                'manual_allocation' => $data['manual_allocation'] ?? [],
                                'selected_piece_ids' => $data['selected_piece_ids'] ?? [],
                            ]);

                            Notification::make()
                                ->title('Alokasi Selesai')
                                ->body('Task operasional berhasil diproses.')
                                ->success()
                                ->send();

                            $shouldRedirectToManualPr = (bool) ($data['add_purchase_request'] ?? false)
                                && (string) (auth()->user()?->role ?? '') === 'Staff Administrasi Gudang';

                            if ($shouldRedirectToManualPr) {
                                $record->refresh();
                                $payload = $record->payload ?? [];
                                $projectId = (int) data_get($payload, 'project_id');
                                $materialId = (int) data_get($payload, 'material_id');
                                $qtyNeeded = (float) data_get($payload, 'qty_dibutuhkan', 0);
                                $allocatedQty = (float) data_get($payload, 'execution_result.allocated_total_qty', 0);
                                $shortageQty = max(0, round($qtyNeeded - $allocatedQty, 2));

                                $manualPrData = [
                                    'request_target' => $projectId > 0 ? 'project' : 'warehouse',
                                    'project_id' => $projectId > 0 ? $projectId : null,
                                    'material_id' => $materialId > 0 ? $materialId : null,
                                    'quantity' => $shortageQty > 0 ? $shortageQty : ($qtyNeeded > 0 ? $qtyNeeded : null),
                                    'priority' => 'medium',
                                    'needed_date' => now()->addDays(7)->toDateString(),
                                    'reason' => 'PR manual dari task operasional alokasi #'.$record->id,
                                ];

                                $manualPrData = collect($manualPrData)
                                    ->reject(fn ($value) => $value === null || $value === '')
                                    ->all();

                                $redirectUrl = PurchaseRequestResource::getUrl('create', [
                                    'data' => $manualPrData,
                                ]);
                            }
                        } elseif ($record->type === 'ai_deadstock_recommendation') {
                            if ((string) ($data['new_status'] ?? 'approved') === 'rejected') {
                                $service->reject($record, auth()->user(), (string) ($data['reason'] ?? 'Rejected'));
                            } else {
                                $service->approve($record, auth()->user());
                            }
                        } else {
                            if ((string) ($data['new_status'] ?? 'approved') === 'rejected') {
                                $service->reject($record, auth()->user(), (string) ($data['reason'] ?? 'Rejected'));
                            } else {
                                $service->approve($record, auth()->user());
                            }
                        }

                        $livewire->redirect($redirectUrl, navigate: true);
                    }),

            ]);
    }

    private static function resolveDecisionLabel(Task $record, ?string $activeTab = null): string
    {
        if ($record->type === 'ai_booking_suggestion') {
            if ($activeTab === 'approved_material' && $record->hasAiItemStatus('approved')) {
                return 'Approved';
            }

            if ($activeTab === 'rejected_material' && $record->hasAiItemStatus('rejected')) {
                return 'Rejected';
            }

            return match ($record->aiDecisionState()) {
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'hybrid' => 'Approved + Rejected',
                default => '-',
            };
        }

        if ($record->type === 'ai_deadstock_recommendation') {
            return (string) data_get($record->payload, 'approval_trail.manager_operasional.status', '-');
        }

        $status = data_get($record->payload, 'approval_trail.staff.status', '-');
        if ($record->type === 'ai_inbound_allocation_suggestion') {
            return $record->status !== 'pending' ? ($status === '-' ? 'approved' : $status) : '-';
        }

        return (string) $status;
    }

    private static function resolveDecisionColor(string $state): string
    {
        return match (mb_strtolower($state)) {
            'approved' => 'success',
            'rejected' => 'danger',
            'approved + rejected' => 'warning',
            default => 'gray',
        };
    }

    private static function canHandleTask(Task $record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->role === 'Administrator IT') {
            return false;
        }

        return in_array($record->for_role, self::roleAliases($user->role), true);
    }

    private static function roleAliases(string $role): array
    {
        $normalized = mb_strtolower(trim($role));

        return match ($normalized) {
            'staff administrasi gudang', 'staff adm gudang', 'staff gudang', 'admin gudang', 'administrator gudang' => [
                'Staff Administrasi Gudang',
                'Staff Adm Gudang',
                'Staff Gudang',
                'Admin Gudang',
                'Administrator Gudang',
            ],
            'manager operasional', 'manager operational', 'manajer operasional' => [
                'Manager Operasional',
                'Manager Operasional',
                'Manajer Operasional',
            ],
            'finance', 'keuangan' => [
                'Finance',
                'Keuangan',
            ],
            'manager teknik', 'manajer teknik' => [
                'Manager Teknik',
                'Manajer Teknik',
            ],
            default => [$role],
        };
    }
}
