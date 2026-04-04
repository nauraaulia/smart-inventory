<?php

namespace App\Filament\Resources\PurchaseRequests\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PurchaseRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('request_target')
                    ->label('Tujuan PR')
                    ->options([
                        'project' => 'Untuk Proyek',
                        'warehouse' => 'Tambah Stok Gudang',
                    ])
                    ->default('project')
                    ->live()
                    ->dehydrated(false)
                    ->required(),
                Select::make('project_id')
                    ->label('Kode Proyek')
                    ->relationship('project', 'kode_proyek')
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => ($get('request_target') ?? 'project') === 'project')
                    ->required(fn (Get $get): bool => ($get('request_target') ?? 'project') === 'project'),
                Select::make('material_id')
                    ->label('Material')
                    ->relationship('material', 'nama_material')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->required()
                    ->minValue(0.01),
                Select::make('priority')
                    ->label('Prioritas')
                    ->options([
                        'low' => 'LOW',
                        'medium' => 'MEDIUM',
                        'high' => 'HIGH',
                        'urgent' => 'CRITICAL',
                    ])
                    ->default('medium')
                    ->required(),
                DatePicker::make('needed_date')
                    ->label('Tanggal Dibutuhkan')
                    ->required(),
                Textarea::make('reason')
                    ->label('Reason')
                    ->rows(3)
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->label('Status')
                    ->default('draft')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
