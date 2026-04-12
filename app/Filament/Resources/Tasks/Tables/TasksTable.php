<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Services\AI\TaskApprovalService;
use Filament\Forms\Components\Hidden;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\Textarea;
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
                    ->formatStateUsing(fn ($state) => 'Task #' . $state),

                TextColumn::make('for_role')
                    ->label('Untuk')
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(function ($state, $record) {
                        if (!$record) return $state;
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
                        if (!$record) return $state;
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
                    ->label('ACC')
                    ->getStateUsing(function ($record) {
                        if (!$record) return '-';
                        if ($record->type === 'ai_deadstock_recommendation') {
                            return (string) data_get($record->payload, 'approval_trail.manager_operasional.status', '-');
                        }
                        $status = data_get($record->payload, 'approval_trail.staff.status', '-');
                        if ($record->type === 'ai_inbound_allocation_suggestion') {
                            return $record->status !== 'pending' ? ($status === '-' ? 'approved' : $status) : '-';
                        }
                        return $status;
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'approved' ? 'success' : 'gray'),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                ViewAction::make(),

                EditAction::make()
                    ->visible(fn (Task $record) => $record->status === 'pending'
                        && self::canHandleTask($record)
                        && !in_array($record->type, ['ai_booking_suggestion', 'ai_inbound_allocation_suggestion'], true)),

                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Task $record) => $record->status === 'pending'
                        && self::canHandleTask($record)
                        && !($record->type === 'ai_deadstock_recommendation'
                            && (string) data_get($record->payload, 'workflow_state', '') === 'approved_for_aging'))
                    ->form(function (Task $record): array {
                        if ($record->type === 'ai_booking_suggestion') {
                            $items = collect(data_get($record->payload, 'items', []));

                            $materialRows = $items->map(function (array $item): array {
                                $recommendedPieces = collect($item['recommended_pieces'] ?? [])
                                    ->map(function (array $piece): string {
                                        $id = (int) ($piece['id'] ?? 0);
                                        $total = rtrim(rtrim(number_format((float) ($piece['panjang'] ?? 0), 2, '.', ''), '0'), '.');
                                        $take = rtrim(rtrim(number_format((float) ($piece['take_qty'] ?? 0), 2, '.', ''), '0'), '.');
                                        $sisa = rtrim(rtrim(number_format((float) ($piece['panjang'] ?? 0) - (float) ($piece['take_qty'] ?? 0), 2, '.', ''), '0'), '.');
                                        $rack = (string) ($piece['rack_label'] ?? '-');
                                        $age = (int) ($piece['age_days'] ?? 0);

                                        $text = "Piece #{$id} | {$total}m | Rak {$rack} | Masuk {$age} hari lalu";
                                        if ((float) ($piece['take_qty'] ?? 0) < (float) ($piece['panjang'] ?? 0)) {
                                            $text .= " | Potong: {$take}m, Sisa: {$sisa}m";
                                        } else {
                                            $text .= ' | Ambil utuh';
                                        }

                                        return $text;
                                    })
                                    ->implode("\n");

                                return [
                                    'material_id' => (int) ($item['material_id'] ?? 0),
                                    'material_name' => (string) ($item['material_name'] ?? '-'),
                                    'dibutuhkan' => (float) ($item['dibutuhkan'] ?? 0),
                                    'satuan' => (string) ($item['satuan'] ?? 'unit'),
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
                                            ->default('pending')
                                            ->required(),
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
                            $defaultRows = $suggestions->map(fn ($s) => [
                                'selected' => true,
                                'project_id' => (int) ($s['project_id'] ?? 0),
                                'project_code' => (string) ($s['project_code'] ?? ('#' . ($s['project_id'] ?? '-'))),
                                'pr_status' => (string) ($s['pr_status'] ?? '-'),
                                'qty' => (float) ($s['suggested_qty'] ?? 0),
                            ])->values()->all();

                            return [
                                \Filament\Forms\Components\Repeater::make('manual_allocation')
                                    ->label('Checklist Alokasi Proyek')
                                    ->helperText('Kebutuhan proyek sudah dihitung sistem. Centang proyek yang ingin dialokasikan sekarang; sisanya tetap menjadi stok gudang available.')
                                    ->schema([
                                        Hidden::make('project_id')
                                            ->required(),
                                        Toggle::make('selected')
                                            ->label('Alokasikan ke proyek ini')
                                            ->default(true)
                                            ->inline(false),
                                        TextInput::make('project_code')
                                            ->label('Proyek')
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('pr_status')
                                            ->label('Status PR')
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('qty')
                                            ->label('Kebutuhan Qty')
                                            ->numeric()
                                            ->required()
                                            ->disabled()
                                            ->dehydrated(),
                                    ])
                                    ->columns(4)
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
                                Textarea::make('deadstock_flow_note')
                                    ->label('Info Alur')
                                    ->default('Approve di sini hanya untuk ACC screening AI. Keputusan final per material (termasuk qty keep/dispose) dilakukan di menu Aging Material.')
                                    ->disabled()
                                    ->dehydrated(false),
                            ];
                        }
                        return [];
                    })
                    ->action(function (Task $record, array $data) {
                        $service = new TaskApprovalService();
                        if ($record->type === 'ai_booking_suggestion') {
                            $materialDecisions = collect($data['material_decisions'] ?? []);

                            if ($materialDecisions->contains(fn ($row) => ($row['status'] ?? 'pending') === 'pending')) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Masih ada material pending')
                                    ->body('Silakan approve atau tolak semua material terlebih dahulu.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $service->approve($record, auth()->user(), [
                                'generate_pr' => (bool) ($data['generate_pr'] ?? true),
                                'material_decisions' => $materialDecisions
                                    ->map(function (array $row): array {
                                        $row['recommended_piece_ids'] = json_decode((string) ($row['recommended_piece_ids'] ?? '[]'), true) ?: [];

                                        return $row;
                                    })
                                    ->values()
                                    ->all(),
                            ]);
                        } elseif ($record->type === 'ai_inbound_allocation_suggestion') {
                            $service->approve($record, auth()->user(), [
                                'manual_allocation' => $data['manual_allocation'] ?? [],
                            ]);
                        } elseif ($record->type === 'ai_deadstock_recommendation') {
                            $service->approve($record, auth()->user());
                        }
                    })
                    ->after(function ($livewire): void {
                        $livewire->redirect(TaskResource::getUrl('index'), navigate: true);
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (Task $record) => $record->status === 'pending'
                        && self::canHandleTask($record)
                        && !($record->type === 'ai_deadstock_recommendation'
                            && (string) data_get($record->payload, 'workflow_state', '') === 'approved_for_aging'))
                    ->form([
                        Textarea::make('reason')
                            ->label('Alasan Reject')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Task $record, array $data) {
                        $service = new TaskApprovalService();
                        $service->reject($record, auth()->user(), (string) ($data['reason'] ?? 'Ditolak'));
                    })
                    ->after(function ($livewire): void {
                        $livewire->redirect(TaskResource::getUrl('index'), navigate: true);
                    }),

            ]);
    }

    private static function canHandleTask(Task $record): bool
    {
        $user = auth()->user();

        if (!$user) {
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