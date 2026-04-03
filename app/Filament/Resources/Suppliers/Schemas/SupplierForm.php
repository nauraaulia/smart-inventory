<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Supplier')
                    ->schema([
                        TextInput::make('kode_supplier')
                            ->label('Kode Supplier')
                            ->placeholder('Otomatis dibuat oleh sistem')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('nama_supplier')
                            ->label('Nama Supplier')
                            ->required(),
                        TextInput::make('no_telp')
                            ->label('No. Telepon')
                            ->tel(),
                        TextInput::make('email')
                            ->label('Email')
                            ->email(),
                        Textarea::make('alamat')
                            ->label('Alamat Lengkap')
                            ->columnSpanFull(),
                    ])->columns(2),

                Section::make('Parameter Pengadaan')
                    ->description('Data ini akan digunakan AI untuk menghitung waktu procurement otomatis')
                    ->schema([
                        TextInput::make('lead_time_hari')
                            ->label('Lead Time Pengiriman')
                            ->helperText('Berapa hari supplier butuh untuk kirim barang setelah PO?')
                            ->numeric()
                            ->suffix('Hari')
                            ->default(7)
                            ->required(),
                        Textarea::make('catatan')
                            ->label('Catatan Supplier')
                            ->helperText('Info tambahan: jam operasional, minimum order, dll')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }
}