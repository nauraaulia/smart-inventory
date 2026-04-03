<?php

namespace App\Filament\Resources\Inbounds\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Actions\DeleteAction;

class InboundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // 1. Tampilkan Nama Material, bukan ID-nya
                TextColumn::make('material.nama_material')
                    ->label('Nama Material')
                    ->searchable()
                    ->sortable(),

                // 2. Jumlah
                TextColumn::make('quantity')
                    ->label('Jumlah')
                    ->numeric()
                    ->sortable(),

                // 3. Satuan (Tambahan baru)
                TextColumn::make('satuan')
                    ->label('Satuan')
                    ->searchable(),

                // 4. Tanggal
                TextColumn::make('entry_date')
                    ->label('Tanggal Masuk')
                    ->date()
                    ->sortable(),

                // 5. Pemasok (Pakai relasi dataSupplier yang baru kita bikin!)
                TextColumn::make('dataSupplier.nama_supplier')
                    ->label('Pemasok')
                    ->searchable(),

                // 6. Lokasi Rak (Tambahan baru)
                TextColumn::make('shelfLocation.nama_lokasi')
                    ->label('Lokasi Rak')
                    ->searchable(),

                // 7. Penerima (Tambahan baru)
                TextColumn::make('penerima')
                    ->label('Penerima / Staff')
                    ->searchable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('jenis_inbound')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Baru' => 'success', // Warna hijau
                        'Retur' => 'warning', // Warna kuning/oranye
                        default => 'gray',
                    })
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
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