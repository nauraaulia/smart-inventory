<?php

namespace App\Filament\Resources\ShelfLocations\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ShelfLocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('kode_lokasi')
                ->label('Kode Lokasi')
                ->required()
                ->maxLength(10)
                ->unique(ignoreRecord: true),
            TextInput::make('nama_lokasi')
                ->label('Nama Lokasi')
                ->required()
                ->maxLength(200),

            Textarea::make('keterangan')
                ->label('Keterangan / Deskripsi')
                ->maxLength(600)
                ->columnSpanFull(), 
                ]);
    }
}
