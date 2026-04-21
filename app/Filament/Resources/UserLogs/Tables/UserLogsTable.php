<?php

namespace App\Filament\Resources\UserLogs\Tables;

use App\Exports\UserLogsExport;
use App\Models\User;   // ← sudah import
use App\Models\UserLog;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class UserLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                TextColumn::make('user.id_custom')
                    ->label('User')
                    ->formatStateUsing(fn ($state, UserLog $record): string => (string) ($state ?: ($record->user?->name ?: ('User #'.(string) $record->user_id))))
                    ->description(fn (UserLog $record): ?string => $record->user?->name)
                    ->sortable()
                    ->searchable(),

                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'Create' => 'success',
                        'Update' => 'warning',
                        'Delete' => 'danger',
                        'Activity' => 'info',
                        'Login' => 'primary',
                        'Outbound Taken' => 'success',
                        'Auto Allocate' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('module')
                    ->label('Modul')
                    ->badge()
                    ->searchable(),

                TextColumn::make('description')
                    ->label('Detail Aktivitas')
                    ->limit(90)
                    ->tooltip(fn (UserLog $record): ?string => $record->description)
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('ip_address')
                    ->label('IP Address'),
            ])
            ->filters([
                Filter::make('created_at')
                    ->label('Tanggal')
                    ->form([
                        DatePicker::make('created_from')->label('Dari Tanggal'),
                        DatePicker::make('created_until')->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['created_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date)
                            )
                            ->when($data['created_until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date)
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['created_from'] ?? null) {
                            $indicators[] = 'Dari: '.$data['created_from'];
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators[] = 'Sampai: '.$data['created_until'];
                        }

                        return $indicators;
                    }),

                SelectFilter::make('user_id')
                    ->label('Actor (User)')
                    ->options(fn () => User::query()
                        ->get(['id', 'id_custom', 'name', 'email'])
                        ->mapWithKeys(fn (User $user) => [
                            $user->id => $user->id_custom
                                ?: $user->name
                                ?: $user->email
                                ?: ('User #'.$user->id),
                        ])
                        ->toArray())
                    ->searchable(),

                SelectFilter::make('action')
                    ->label('Action')
                    ->options(fn () => UserLog::query()
                        ->visibleInAudit()
                        ->whereNotNull('action')
                        ->where('action', '!=', '')
                        ->distinct()
                        ->pluck('action', 'action')
                        ->toArray()),

                SelectFilter::make('module')
                    ->label('Module')
                    ->options(fn () => UserLog::query()
                        ->visibleInAudit()
                        ->whereNotNull('module')
                        ->where('module', '!=', '')
                        ->distinct()
                        ->pluck('module', 'module')
                        ->toArray()
                    ),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(function ($livewire) {
                        $filterData = $livewire->tableFilters;

                        $filters = [
                            'date_from' => $filterData['created_at']['created_from'] ?? null,
                            'date_to' => $filterData['created_at']['created_until'] ?? null,
                            'user_id' => $filterData['user_id']['value'] ?? null,
                            'action' => $filterData['action']['value'] ?? null,
                            'module' => $filterData['module']['value'] ?? null,
                        ];

                        return Excel::download(
                            new UserLogsExport($filters),
                            'user-logs-'.now()->format('Y-m-d-His').'.xlsx'
                        );
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
