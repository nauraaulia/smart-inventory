<?php

namespace App\Filament\Resources\Materials\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class MaterialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('kode_material')
                    ->label('Kode material')
                    ->placeholder('Otomatis dibuat oleh sistem')
                    ->disabled() 
                    ->dehydrated(false) 
                    ->columnSpanFull(),
                TextInput::make('nama_material')
                    ->required(),
                Textarea::make('deskripsi')
                    ->columnSpanFull(),
                TextInput::make('satuan')
                    ->required(),
                TextInput::make('default_roll_length')
                    ->label('Panjang Roll Standar')
                    ->numeric()
                    ->step('0.01')
                    ->suffix(fn ($get) => $get('satuan') ?: 'meter')
                    ->helperText('Isi jika material memiliki standar 1 roll (contoh kabel 300 meter/roll).')
                    ->nullable(),
                Select::make('default_supplier_id')
                    ->label('Supplier')
                    ->relationship('defaultSupplier', 'nama_supplier')
                    ->searchable()
                    ->preload()
                    ->required(),
                    TextInput::make('min_stok')
                    ->label('Batas Minimum Stok')
                    ->numeric()
                    ->default(0)
                    ->required(),
                 TextInput::make('kategori')
                    ->label('Kategori')
                    ->placeholder('Masukkan kategori barang...')
                    ->maxLength(255),
            ]);
    }
}
