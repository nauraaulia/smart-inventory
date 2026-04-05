<?php

namespace App\Filament\Resources\Tasks\Schemas;

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
                            ->required(),
                        Textarea::make('rejection_reason')
                            ->label('Alasan Reject')
                            ->visible(fn (Get $get) => $get('status') === 'rejected')
                            ->required(fn (Get $get) => $get('status') === 'rejected')
                            ->rows(3),
                    ])->columns(1),

                Section::make('Detail Rekomendasi AI')
                    ->schema([
                        Repeater::make('payload.ai_summary.rekomendasi')
                            ->label('')
                            ->dehydrated(false)
                            // INI YANG DIGANTI UNTUK FILAMENT V3:
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                TextInput::make('material')->label('Nama Material')->disabled(),
                                TextInput::make('aksi')->label('Saran Aksi')->disabled(),
                                Textarea::make('detail')
                                    ->label('Penjelasan AI')
                                    ->rows(8)
                                    ->autosize()
                                    ->formatStateUsing(function ($state): string {
                                        $text = (string) ($state ?? '');

                                        $legacyPattern = '/Butuh\s+asli\s*([^,]+),\s*buffer\s*[^,]+,\s*kebutuhan\s+akhir\s*([^,]+),\s*belum\s+tercover\s*([^,]+),\s*tersedia\s*([^,]+),\s*booking\s*([^,]+),\s*PR\s*([^\.]+)\.?/i';

                                        if (preg_match($legacyPattern, $text, $m)) {
                                            return sprintf(
                                                'Butuh %s, belum tercover %s, tersedia %s, booking %s, PR %s.',
                                                trim($m[2]),
                                                trim($m[3]),
                                                trim($m[4]),
                                                trim($m[5]),
                                                trim($m[6]),
                                            );
                                        }

                                        // Normalisasi tampilan untuk snapshot lama:
                                        // jika sudah dibooking tapi alasan masih menyebut potongan tidak bisa dipakai.
                                        $isBookedFlow = (bool) preg_match('/dibooking\s+[0-9]+(?:\.[0-9]+)?/i', $text)
                                            && (bool) preg_match('/\bPR\s+0\b|\bgenerate\s+PR\s+0\b/i', $text);

                                        if ($isBookedFlow && preg_match('/potongan\s+.*tidak\s+bisa\s+dipakai/i', $text)) {
                                            $text = preg_replace(
                                                '/dikarenakan\s+.+$/i',
                                                'dikarenakan aturan tanpa potongan aktif, stok roll utuh tersedia sehingga kebutuhan bisa langsung dibooking.',
                                                $text
                                            ) ?? $text;
                                        }

                                        return $text;
                                    })
                                    ->readOnly()
                                    ->columnSpanFull(),
                            ])->columns(2),
                    ]),
            ]);
    }
}