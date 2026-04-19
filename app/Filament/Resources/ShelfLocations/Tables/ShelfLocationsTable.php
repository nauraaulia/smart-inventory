<?php

namespace App\Filament\Resources\ShelfLocations\Tables;

use App\Models\ShelfLocation;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShelfLocationsTable
{
    public static function configure(Table $table): Table
    {
        $canManage = in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true);

        return $table
            ->columns([
                TextColumn::make('kode_lokasi')
                    ->label('Kode Lokasi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nama_lokasi')
                    ->label('Nama Lokasi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('keterangan')
                    ->label('Keterangan')
                    ->limit(200)
                    ->searchable(),

                // KOLOM BARU — jumlah pieces di rak ini
                TextColumn::make('material_pieces_count')
                    ->label('Jumlah Item')
                    ->counts('materialPieces')
                    ->badge()
                    ->color(fn (int $state): string => match(true) {
                        $state === 0 => 'gray',
                        $state <= 5 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                // KOLOM BARU — jumlah item yang masih available (tidak booked)
                TextColumn::make('available_pieces_count')
                    ->label('Item Tersedia')
                    ->getStateUsing(fn (ShelfLocation $record): int =>
                        $record->materialPieces()
                            ->where('status', 'available')
                            ->count()
                    )
                    ->badge()
                    ->color(fn (int $state): string => match(true) {
                        $state === 0 => 'danger',
                        $state <= 3 => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('created_at')
                    ->label('Dibuat Pada')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()
                    ->visible($canManage),
                \Filament\Actions\DeleteAction::make()
                    ->visible($canManage),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible($canManage),
                ]),
            ]);
    }
}