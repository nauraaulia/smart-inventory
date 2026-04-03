<?php

namespace App\Filament\Resources\Materials\Schemas;

use Filament\Forms\Components\TextInput;
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
                TextInput::make('stok')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('satuan')
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
