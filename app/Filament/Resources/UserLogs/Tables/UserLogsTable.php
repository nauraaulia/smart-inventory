<?php

namespace App\Filament\Resources\UserLogs\Tables;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;   // ← sudah import
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\UserLogsExport;
use App\Models\User;
use App\Models\UserLog;

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
                    ->sortable()
                    ->searchable(),

                TextColumn::make('action')           
                    ->label('Action')
                    ->badge()
                    ->color(fn($state) => match($state) {
                        'Create' => 'success',
                        'Update' => 'warning',
                        'Delete' => 'danger',
                        default  => 'gray',
                    }),

                TextColumn::make('module')            
                    ->searchable(),

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
                            ->when($data['created_from'] ?? null, fn($q, $date) =>
                                $q->whereDate('created_at', '>=', $date)
                            )
                            ->when($data['created_until'] ?? null, fn($q, $date) =>
                                $q->whereDate('created_at', '<=', $date)
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['created_from'] ?? null)
                            $indicators[] = 'Dari: ' . $data['created_from'];
                        if ($data['created_until'] ?? null)
                            $indicators[] = 'Sampai: ' . $data['created_until'];
                        return $indicators;
                    }),

                SelectFilter::make('user_id')
                    ->label('Actor (User)')
                    ->options(fn() => User::pluck('id_custom', 'id')->toArray()) // ← id_custom
                    ->searchable(),

                SelectFilter::make('action')
                    ->label('Action')
                    ->options([
                        'Create' => 'Create',
                        'Update' => 'Update',
                        'Delete' => 'Delete',
                    ]),

                SelectFilter::make('module')
                    ->label('Module')
                    ->options(fn() => UserLog::distinct()
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
                            'date_to'   => $filterData['created_at']['created_until'] ?? null,
                            'user_id'   => $filterData['user_id']['value'] ?? null,
                            'action'    => $filterData['action']['value'] ?? null,
                            'module'    => $filterData['module']['value'] ?? null,
                        ];

                        return Excel::download(
                            new UserLogsExport($filters),
                            'user-logs-' . now()->format('Y-m-d-His') . '.xlsx'
                        );
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}