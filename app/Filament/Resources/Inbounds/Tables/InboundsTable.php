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

                // 5. Asal inbound: supplier untuk inbound baru, project untuk retur
                TextColumn::make('asal_inbound')
                    ->label('Asal Inbound')
                    ->getStateUsing(fn ($record): string => $record->jenis_inbound === 'Retur'
                        ? ($record->project?->kode_proyek ?? '-')
                        : ($record->dataSupplier?->nama_supplier ?? '-'))
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
                    ->getStateUsing(fn ($record): string => $record->is_opening_balance
                        ? 'Opening Balance'
                        : ($record->jenis_inbound === 'Retur' ? 'Retur' : 'Inbound Baru'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Opening Balance' => 'info',
                        'Inbound Baru' => 'success',
                        'Retur' => 'warning',
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