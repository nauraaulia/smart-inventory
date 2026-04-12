<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Models\Task;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                        TextInput::make('payload.ai_instruction.ringkas')
                            ->label('Interpretasi Instruksi AI')
                            ->disabled(),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending (Menunggu Konfirmasi)',
                                'done' => 'Done (Disetujui & Diproses)',
                                'rejected' => 'Rejected (Ditolak)',
                            ])
                            ->live()
                            ->disabled(function (Get $get, ?Task $record): bool {
                                if ($record?->type !== 'ai_booking_suggestion') {
                                    return false;
                                }

                                return collect($get('payload.items') ?? [])
                                    ->contains(fn ($item): bool => in_array((string) ($item['status'] ?? 'pending'), ['approved', 'rejected'], true));
                            })
                            ->helperText(fn (?Task $record): ?string => $record?->type === 'ai_booking_suggestion'
                                ? 'Pilih Approve atau Tolak untuk setiap material, lalu klik Submit.'
                                : null)
                            ->required(),
                        Textarea::make('rejection_reason')
                            ->label('Alasan Reject')
                            ->visible(fn (Get $get) => $get('status') === 'rejected')
                            ->required(fn (Get $get) => $get('status') === 'rejected')
                            ->rows(3),
                    ])->columns(1),

                Section::make('Detail Rekomendasi AI')
                    ->schema([
                        Repeater::make('payload.items')
                            ->label('')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                TextInput::make('material_name')->label('Material')->disabled(),
                                TextInput::make('dibutuhkan')
                                    ->label('Qty Dibutuhkan')
                                    ->suffix(fn (Get $get): string => (string) ($get('satuan') ?: 'unit'))
                                    ->disabled(),
                                TextInput::make('recommended_qty')
                                    ->label('Qty Booking')
                                    ->suffix(fn (Get $get): string => (string) ($get('satuan') ?: 'unit'))
                                    ->disabled(),
                                TextInput::make('qty_pr')
                                    ->label('Qty PR')
                                    ->formatStateUsing(fn ($state, Get $get): float => max(0, round(
                                        (float) ($get('dibutuhkan') ?? 0) - (float) ($get('recommended_qty') ?? 0),
                                        2
                                    )))
                                    ->suffix(fn (Get $get): string => (string) ($get('satuan') ?: 'unit'))
                                    ->disabled(),
                                Textarea::make('recommended_pieces')
                                    ->label('Detail Piece')
                                    ->rows(4)
                                    ->formatStateUsing(function ($state): string {
                                        if (!is_array($state)) {
                                            return '-';
                                        }

                                        $lines = collect($state)->map(function ($piece): string {
                                            $id = (int) data_get($piece, 'id', 0);
                                            $take = (float) data_get($piece, 'take_qty', 0);
                                            $len = (float) data_get($piece, 'panjang', 0);
                                            $age = (int) data_get($piece, 'age_days', 0);
                                            $rack = (string) data_get($piece, 'rack_label', '-');
                                            $totalText = rtrim(rtrim(number_format($len, 2, '.', ''), '0'), '.');
                                            $takeText = rtrim(rtrim(number_format($take, 2, '.', ''), '0'), '.');
                                            $sisaText = rtrim(rtrim(number_format(max(0, $len - $take), 2, '.', ''), '0'), '.');

                                            $text = "Piece #{$id} | {$totalText}m | Rak {$rack} | Masuk {$age} hari lalu";
                                            if ($take + 0.00001 < $len) {
                                                $text .= " | Potong: {$takeText}m, Sisa: {$sisaText}m";
                                            } else {
                                                $text .= ' | Ambil utuh';
                                            }

                                            return $text;
                                        })->values()->all();

                                        return empty($lines) ? '-' : implode("\n", $lines);
                                    })
                                    ->disabled()
                                    ->dehydrated(false),
                                Textarea::make('ai_reason')
                                    ->label('Analisis AI')
                                    ->rows(2)
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpanFull(),
                                Select::make('status')
                                    ->label('Keputusan')
                                    ->options([
                                        'pending' => 'Pending',
                                        'approved' => 'Approve',
                                        'rejected' => 'Tolak',
                                    ])
                                    ->default('pending')
                                    ->required(),
                            ])->columns(2),
                    ]),
            ]);
    }
}