<?php

namespace App\Filament\Resources\Kliens\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class KlienForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('kode_klien')
                ->label('Kode Klien')
                ->placeholder('Otomatis dibuat oleh sistem')
                ->disabled() 
                ->dehydrated(false),
            TextInput::make('nama_klien')
                ->label('Nama Klien')
                ->required(),
            TextInput::make('no_telp')
                ->label('No. Telepon')
                ->tel(),
            Textarea::make('alamat')
                ->label('Alamat')
                ->columnSpanFull(),
            ]);
    }
}
