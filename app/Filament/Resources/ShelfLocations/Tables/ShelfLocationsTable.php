<?php

namespace App\Filament\Resources\ShelfLocations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
