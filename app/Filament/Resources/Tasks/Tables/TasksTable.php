<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Services\AI\TaskApprovalService;
use Filament\Forms\Components\Hidden;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
                        if (str_starts_with($record->type, 'ai_')) {
                            return 'AI: Konfirmasi Kebutuhan Material Proyek';
                        }
                        return $state;
                    }),

                TextColumn::make('project_code')
                    ->label('Kode Proyek')
                    ->searchable()
                    ->hidden(fn ($livewire) => $livewire->activeTab === 'operasional'),

                TextColumn::make('acc_staff')
                    ->label('ACC Staff')
                    ->getStateUsing(function ($record) {
                        if (!$record) return '-';
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
                    ->visible(fn (Task $record) => $record->status === 'pending' && self::canHandleTask($record))
                    ->form(function (Task $record): array {
                        if ($record->type === 'ai_booking_suggestion') {
                            return [
                                Toggle::make('generate_pr')
                                    ->label('Jika ada kekurangan stok, generate PR otomatis')
                                    ->default(true)
                                    ->inline(false),
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
                        return [];
                    })
                    ->action(function (Task $record, array $data) {
                        $service = new TaskApprovalService();
                        if ($record->type === 'ai_booking_suggestion') {
                            $service->approve($record, auth()->user(), [
                                'generate_pr' => (bool) ($data['generate_pr'] ?? true),
                            ]);
                        } elseif ($record->type === 'ai_inbound_allocation_suggestion') {
                            $service->approve($record, auth()->user(), [
                                'manual_allocation' => $data['manual_allocation'] ?? [],
                            ]);
                        }
                    })
                    ->after(function ($livewire): void {
                        $livewire->redirect(TaskResource::getUrl('index'), navigate: true);
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (Task $record) => $record->status === 'pending' && self::canHandleTask($record))
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