<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Models\Task;
use App\Services\AI\TaskApprovalService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Table;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->searchable(),

                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->limit(50),

                BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'done',
                        'danger' => 'rejected',
                    ]),

                TextColumn::make('related_type')
                    ->label('Relasi'),

                TextColumn::make('project_code')
                    ->label('Kode Proyek')
                    ->getStateUsing(fn (Task $record) => $record->related_type === 'project'
                        ? (data_get($record->payload, 'project_code') ?: ('#' . $record->related_id))
                        : '-'),

                TextColumn::make('acc_staff')
                    ->label('ACC Staff')
                    ->getStateUsing(fn (Task $record) => data_get($record->payload, 'approval_trail.staff.status', '-'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'approved' ? 'success' : 'gray'),

                TextColumn::make('acc_manager')
                    ->label('ACC Manager')
                    ->getStateUsing(fn (Task $record) => data_get($record->payload, 'approval_trail.manager.status', '-'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'approved' ? 'success' : 'gray'),

                TextColumn::make('acc_finance')
                    ->label('ACC Finance')
                    ->getStateUsing(fn (Task $record) => data_get($record->payload, 'approval_trail.finance.status', '-'))
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
                    ->visible(fn (Task $record) => $record->status === 'pending' && self::canHandleTask($record)),

                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Task $record) => $record->status === 'pending' && self::canHandleTask($record))
                    ->action(function (Task $record) {
                        $service = new TaskApprovalService();
                        $service->approve($record, auth()->user());
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
            return true;
        }

        return in_array($record->for_role, self::roleAliases($user->role), true);
    }

    private static function roleAliases(string $role): array
    {
        $normalized = mb_strtolower(trim($role));

        return match ($normalized) {
            'staff administrasi gudang', 'staff adm gudang', 'staff gudang' => [
                'Staff Administrasi Gudang',
                'Staff Adm Gudang',
                'Staff Gudang',
            ],
            'manager operasional', 'manager operational', 'manajer operasional' => [
                'Manager Operasional',
                'Manager Operational',
                'Manajer Operasional',
            ],
            'finance', 'keuangan' => [
                'Finance',
                'Keuangan',
            ],
            default => [$role],
        };
    }
}