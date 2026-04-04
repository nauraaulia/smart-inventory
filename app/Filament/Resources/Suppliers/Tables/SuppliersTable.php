<?php

namespace App\Filament\Resources\Suppliers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SuppliersTable
{
    public static function table(Table $table): Table
    {
        $canManage = in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true);

        return $table
            ->columns([
                TextColumn::make('kode_supplier')
                    ->label('Kode Supplier')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nama_supplier')
                    ->label('Nama Supplier')
                    ->searchable(),
                TextColumn::make('alamat')
                    ->label('Alamat')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('no_telp')
                    ->label('Telepon'),
                TextColumn::make('email')
                    ->label('Email'),
                TextColumn::make('lead_time_hari')
                    ->label('Lead Time')
                    ->suffix(' Hari')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state <= 7 => 'success',
                        $state <= 14 => 'warning',
                        default => 'danger',
                    }),
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