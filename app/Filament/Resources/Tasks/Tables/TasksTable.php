<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Services\AI\TaskApprovalService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                    ->sortable(),

                TextColumn::make('for_role')
                    ->label('Untuk')
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(function ($state) {
                        return match ($state) {
                            'ai_booking_suggestion' => 'Rekomendasi Booking',
                            'ai_inbound_allocation_suggestion' => 'Alokasi Inbound',
                            'pr_manager_approval' => 'Approval Manager PR',
                            'pr_finance_approval' => 'Approval Finance PR',
                            'pr_revision' => 'Revisi PR',
                            default => ucfirst(str_replace('_', ' ', $state)),
                        };
                    }),

                TextColumn::make('related_type')
                    ->label('Relasi'),


                TextColumn::make('acc_staff')
                    ->label('ACC Staff')
                    ->getStateUsing(fn (Task $record) => data_get($record->payload, 'approval_trail.staff.status', '-'))
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
                            $projectOptions = \App\Models\Project::query()
                                ->orderBy('kode_proyek')
                                ->pluck('kode_proyek', 'id')
                                ->toArray();
                            return [
                                \Filament\Forms\Components\Repeater::make('manual_allocation')
                                    ->label('Alokasi ke Proyek')
                                    ->schema([
                                        \Filament\Forms\Components\Select::make('project_id')
                                            ->label('Proyek')
                                            ->options($projectOptions)
                                            ->required(),
                                        \Filament\Forms\Components\TextInput::make('qty')
                                            ->label('Qty')
                                            ->numeric()
                                            ->required(),
                                    ])
                                    ->columns(2)
                                    ->default(
                                        $suggestions->map(fn($s) => [
                                            'project_id' => $s['project_id'],
                                            'qty' => $s['suggested_qty'],
                                        ])->toArray()
                                    )
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