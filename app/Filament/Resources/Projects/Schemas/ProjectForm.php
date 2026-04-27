<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use App\Models\ProjectTemplate;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\DatePicker;


class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([

                Group::make()
                    ->schema([
                        Section::make('Informasi Proyek')
                            ->schema([
                                Select::make('project_template_id')
                                    ->label('Pilih Template Proyek')
                                    ->options(fn () => ProjectTemplate::query()
                                        ->active()
                                        ->orderBy('nama_template')
                                        ->pluck('nama_template', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, $state) {
                                        if (!$state) {
                                            $set('kode_proyek', null);
                                            $set('nama_proyek', null);
                                            $set('items', []);
                                            return;
                                        }

                                        $template = ProjectTemplate::with('items')->find($state);

                                        if ($template) {
                                            $set('kode_proyek', Project::generateKodeProyek($template->id));
                                            $set('nama_proyek', $template->nama_template);

                                            // ✅ Hanya auto-fill material, H-minus diisi manual per proyek
                                            $set('items', $template->items->map(fn ($item) => [
                                                'material_id'    => $item->material_id,
                                                'jumlah_diminta' => $item->jumlah_kebutuhan,
                                            ])->toArray());
                                        }
                                    }),

                                TextInput::make('kode_proyek')
                                    ->label('Kode Proyek')
                                    ->disabled()
                                    ->dehydrated()
                                    ->required()
                                    ->placeholder('Otomatis saat pilih template')
                                    ->prefixIcon('heroicon-o-hashtag'),

                                TextInput::make('nama_proyek')
                                    ->required(),

                                DatePicker::make('tanggal_mulai')
                                    ->label('Tanggal Mulai Proyek')
                                    ->native(false)
                                    ->minDate(now())
                                    ->displayFormat('d/m/Y')
                                    ->required(),

                                DatePicker::make('tanggal_selesai')
                                    ->label('Perkiraan Selesai')
                                    ->native(false)
                                    ->minDate(now())
                                    ->displayFormat('d/m/Y')
                                    ->after('tanggal_mulai')
                                    ->required(),

                                TextInput::make('h_minus_persiapan')
                                    ->label('H- Barang Harus Siap')
                                    ->helperText('Berapa hari sebelum proyek dimulai, barang harus sudah ready?')
                                    ->numeric()
                                    ->required()
                                    ->default(7) // ✅ Default 7 hari, user bisa ubah
                                    ->suffix('hari'),
                            ])->columns(2),

                        Section::make('Parameter Efisiensi (Smart AI)')
                            ->schema([
                                Textarea::make('catatan_teknis')
                                    ->label('Instruksi Khusus (Akan dibaca AI)')
                                    ->nullable()
                                    ->columnSpanFull(),
                            ])->columns(2),
                    ])
                    ->columnSpan(2),

                Group::make()
                    ->schema([
                        Section::make('Kebutuhan Material')
                            ->schema([
                                Repeater::make('items')
                                    ->relationship('items')
                                    ->schema([
                                        Select::make('material_id')
                                            ->relationship('material', 'nama_material')
                                            ->required()
                                            ->searchable()
                                            ->preload(),
                                        TextInput::make('jumlah_diminta')
                                            ->numeric()
                                            ->required(),
                                    ])
                                    ->columns(2)
                                    ->minItems(1)
                                    ->addActionLabel('Tambah Material Tambahan'),
                            ]),
                    ])
                    ->columnSpan(1),

            ]);
    }
}
