<?php

namespace App\Filament\Resources\Projects\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                TextColumn::make('status')
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
                Action::make('confirm_outbound')
                    ->label('Konfirmasi Outbound')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Konfirmasi Barang Sudah Outbound')
                    ->modalDescription('Aksi ini akan mengubah status proyek menjadi Processing.')
                    ->visible(fn ($record) => in_array($record->status, ['Pending', 'Approved']))
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'Processing',
                            'outbound_confirmed_at' => now(),
                        ]);
                    }),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
