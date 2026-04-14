<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Resources\Outbounds\Tables\OutboundsTable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Models\Project;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('2s')
            ->columns([
                TextColumn::make('nama_proyek')
                    ->searchable(),
                TextColumn::make('kode_proyek')
                    ->searchable(),
                TextColumn::make('tanggal_mulai')
                    ->date()
                    ->sortable(),
                TextColumn::make('tanggal_selesai')
                    ->date()
                    ->sortable(),
                TextColumn::make('project_category_label')
                    ->label('Kategori Proyek')
                    ->badge()
                    ->color(fn (Project $record): string => $record->project_category_color),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'Pending' => 'Pending',
                        'Approved' => 'Approved',
                        'Processing' => 'Processing',
                        'Completed' => 'Completed',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Pending' => 'gray',
                        'Approved' => 'info',
                        'Processing' => 'warning',
                        'Completed' => 'success',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('outbound_confirmed_at')
                    ->label('Outbound Confirmed')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('view_requirements')
                    ->label('Lihat Kebutuhan')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->modalHeading(fn (Project $record): string => 'Kebutuhan Project ' . ($record->kode_proyek ?: ('#' . $record->id)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalWidth('5xl')
                    ->modalContent(fn (Project $record) => OutboundsTable::requirementsModalContent($record)),
                \Filament\Actions\EditAction::make()
                    ->visible(fn () => auth()->user()?->role === 'Manager Teknik'),
                \Filament\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->role === 'Manager Teknik'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ])
                    ->visible(fn () => auth()->user()?->role === 'Manager Teknik'),
            ]);
    }
}
