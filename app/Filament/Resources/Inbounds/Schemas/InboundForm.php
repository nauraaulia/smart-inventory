<?php

namespace App\Filament\Resources\Inbounds\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select; // Untuk dropdown
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Hidden;


class InboundForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Hidden::make('jenis_inbound')
                    ->default(request('type', 'Baru')),

                // 1. Dropdown Nama Material
                Select::make('material_id')
                    ->label('Nama Material')
                    ->relationship('material', 'nama_material')
                    ->searchable()
                    ->preload()
                    ->required(),

                // 2. Dropdown Satuan (Ambil dari data material yang dipilih)
                TextInput::make('satuan')
                    ->label('Satuan')
                    ->placeholder('Contoh: Pcs, Kg, Box')
                    ->required(),

                // 3. Jumlah Masuk
                TextInput::make('quantity')
                    ->label('Jumlah Masuk')
                    ->numeric()
                    ->required(),

                // 4. Dropdown Lokasi Rak (Shelf Location)
                Select::make('shelf_location_id')
                    ->label('Lokasi Rak / Shelf')
                    ->relationship('shelfLocation', 'nama_lokasi') // Pastikan ada relasi shelfLocation di Model Inbound
                    ->searchable()
                    ->preload()
                    ->required(),

                // 5. Tanggal Masuk
                DatePicker::make('entry_date')
                    ->label('Tanggal Masuk')
                    ->default(now())
                    ->required(),

                // 6. Dropdown Pemasok / Supplier
                Select::make('supplier_id')
                    ->label('Pemasok / Supplier')
                    ->relationship('dataSupplier', 'nama_supplier')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->visible(fn ($get) => $get('jenis_inbound') === 'Baru' || request('type') === 'Baru'),

                Select::make('project_id')
                    ->label('Project Yang Diretur')
                    ->relationship('project', 'kode_proyek')
                    ->searchable()
                    ->preload()
                    ->visible(fn ($get) => $get('jenis_inbound') === 'Retur' || request('type') === 'Retur')
                    ->required(fn ($get) => $get('jenis_inbound') === 'Retur' || request('type') === 'Retur'),

                Checkbox::make('is_opening_balance')
                    ->label('Opening Balance')
                    ->default(false)
                    ->visible(fn ($get) => $get('jenis_inbound') === 'Baru' || request('type') === 'Baru'),

                // 7. Penerima (Otomatis nama yang login tapi bisa diubah kalau perlu)
                TextInput::make('penerima')
                    ->label('Penerima')
                    ->default(auth()->user()->name)
                    ->required(),
                
                Select::make('kondisi')
                    ->label('Kondisi Barang Retur')
                    ->options([
                        'Bagus' => 'Bagus (Bisa masuk stok utama)',
                        'Cacat Ringan' => 'Cacat Ringan (Bisa dipakai tapi minus)',
                        'Cacat Berat' => 'Cacat Berat (Tidak bisa dipakai / Dibuang)',
                    ])
                    ->visible(fn ($get) => $get('jenis_inbound') === 'Retur' || request('type') === 'Retur')
                    ->required(fn ($get) => $get('jenis_inbound') === 'Retur' || request('type') === 'Retur'),

                Textarea::make('keterangan')
                    ->label('Keterangan / Alasan Retur')
                    ->placeholder('Misal: Sisa proyek A, pipa kelebihan 2 meter...')
                    ->visible(fn ($get) => $get('jenis_inbound') === 'Retur' || request('type') === 'Retur')
                    ->required(fn ($get) => $get('jenis_inbound') === 'Retur' || request('type') === 'Retur'),
                
                    ]);

    }
}