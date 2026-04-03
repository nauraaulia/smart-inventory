<?php

namespace App\Filament\Resources\Materials\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaterialsTable
{
    public static function configure(Table $table): Table
    {
        $isAdmin = auth()->user()->role === 'Administrator IT';

        return $table
            ->columns([
                TextColumn::make('kode_material')
                    ->label('Kode/SKU')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nama_material')
                    ->label('Nama Barang')
                    ->searchable(),

                TextColumn::make('kategori')
                    ->label('Kategori'),

                TextColumn::make('satuan')
                    ->label('Unit'),

                // ── KOLOM STOK ──────────────────────────────────

                TextColumn::make('stok')
                    ->label('Stok Total')
                    ->badge()
                    ->color(fn ($state, $record) =>
                        $state <= $record->min_stok ? 'danger' : 'success'
                    )
                    ->sortable(),

                TextColumn::make('stok_rusak')
                    ->label('Rusak')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->default(0),

                TextColumn::make('booked')
                    ->label('Booked')
                    ->badge()
                    ->color('warning')
                    ->default(0),

                TextColumn::make('available')
                    ->label('Available')
                    ->badge()
                    ->color(fn ($state, $record) =>
                        $state <= $record->min_stok ? 'danger' : 'success'
                    )
                    ->getStateUsing(fn ($record) => $record->available),
                    // ↑ pakai accessor dari Model, bukan hitung manual di sini
            ])
            ->filters([])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make()
                    ->visible($isAdmin),
                \Filament\Actions\DeleteAction::make()
                    ->visible($isAdmin),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible($isAdmin),
                ]),
            ]);
    }
}