<?php

namespace App\Filament\Resources\ProjectTemplates\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Utilities\Set;

class ProjectTemplateForm
{
    public const KATEGORI_KODE = [
        'Infrastruktur Menara' => 'TWR',
        'Sistem Komunikasi'    => 'COM',
        'Sistem Keamanan'      => 'SEC',
        'Energi Alternatif'    => 'NRG',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([

                Group::make()
                    ->schema([
                        Section::make('Informasi Utama Template')
                            ->schema([
                                TextInput::make('kode_template')
                                    ->label('Kode template')
                                    ->disabled()
                                    ->dehydrated()
                                    ->required()
                                    ->default(fn () => 'TMP-' . strtoupper(substr(uniqid(), -6)))
                                    ->unique(ignoreRecord: true),

                                TextInput::make('nama_template')
                                    ->label('Nama template')
                                    ->required(),

                                Select::make('kategori')
                                    ->label('Kategori')
                                    ->options([
                                        'Infrastruktur Menara' => 'Infrastruktur Menara',
                                        'Sistem Komunikasi'    => 'Sistem Komunikasi',
                                        'Sistem Keamanan'      => 'Sistem Keamanan',
                                        'Energi Alternatif'    => 'Energi Alternatif',
                                    ])
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, $state) {
                                        $kode = self::KATEGORI_KODE[$state] ?? null;
                                        $set('kode_kategori', $kode);
                                    }),

                                TextInput::make('kode_kategori')
                                    ->label('Kode Kategori')
                                    ->disabled()
                                    ->dehydrated()
                                    ->placeholder('Otomatis dari kategori')
                                    ->prefixIcon('heroicon-o-hashtag'),

                                // ❌ HAPUS persiapan_barang dari sini
                                // Cukup diisi di form Project aja

                                Toggle::make('is_active')
                                    ->label('Status Aktif')
                                    ->default(true),
                            ])->columns(2),
                    ])
                    ->columnSpan(2),

                Group::make()
                    ->schema([
                        Section::make('Daftar Material Standar')
                            ->description('Area ini akan scroll ke bawah tanpa ganggu form utama.')
                            ->schema([
                                Repeater::make('items')
                                    ->relationship('items')
                                    ->schema([
                                        Select::make('material_id')
                                            ->label('Pilih Material')
                                            ->relationship('material', 'nama_material')
                                            ->required()
                                            ->searchable()
                                            ->preload(),
                                        TextInput::make('jumlah_kebutuhan')
                                            ->label('Jumlah kebutuhan')
                                            ->numeric()
                                            ->required()
                                            ->default(1),
                                    ])
                                    ->columns(1)
                                    ->minItems(1)
                                    ->addActionLabel('Add to items'),
                            ]),
                    ])
                    ->columnSpan(1),
            ]);
    }
}