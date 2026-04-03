<?php

namespace App\Filament\Resources\Tasks\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Task')
                    ->schema([
                        TextInput::make('title')->label('Judul Task')->disabled(),
                        Textarea::make('message')->label('Ringkasan dari AI')->rows(3)->disabled(),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending (Menunggu Konfirmasi)',
                                'done' => 'Done (Disetujui & Diproses)',
                                'rejected' => 'Rejected (Ditolak)',
                            ])
                            ->required(),
                    ])->columns(1),

                Section::make('Detail Rekomendasi AI (Mudah Dibaca)')
                    ->schema([
                        Repeater::make('payload.ai_summary.rekomendasi')
                            ->label('')
                            // INI YANG DIGANTI UNTUK FILAMENT V3:
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                TextInput::make('material')->label('Nama Material')->disabled(),
                                TextInput::make('aksi')->label('Saran Aksi')->disabled(),
                                Textarea::make('detail')->label('Penjelasan AI')->disabled()->columnSpanFull(),
                            ])->columns(2),
                    ]),
            ]);
    }
}