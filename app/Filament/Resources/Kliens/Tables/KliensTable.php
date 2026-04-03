<?php

namespace App\Filament\Resources\Kliens\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KliensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
            TextColumn::make('kode_klien')
                ->label('Kode Klien')
                ->searchable()
                ->sortable(),
            TextColumn::make('nama_klien')
                ->label('Nama Klien')
                ->searchable()
                ->sortable(),
            TextColumn::make('no_telp')
                ->label('Telepon'),
            TextColumn::make('alamat')
                ->label('Alamat')
                ->limit(200),
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
