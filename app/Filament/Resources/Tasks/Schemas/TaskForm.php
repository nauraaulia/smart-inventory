<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\Task;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
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
                        Textarea::make('message')->label('Keterangan')->rows(3)->disabled(),
                        Textarea::make('payload.project_special_instruction')
                            ->label('Instruksi Khusus (Akan dibaca AI)')
                            ->rows(3)
                            ->placeholder('-')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_booking_suggestion')
                            ->disabled()
                            ->dehydrated(false),
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

                Section::make('Detail Rekomendasi')
                    ->schema([
                        Repeater::make('payload.items')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_booking_suggestion')
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
                                TextInput::make('available_qty')
                                    ->label('Stok Terkini')
                                    ->formatStateUsing(function ($state, Get $get): float {
                                        $materialId = (int) ($get('material_id') ?? 0);

                                        if ($materialId > 0) {
                                            $material = Material::query()->find($materialId, ['id', 'stok', 'booked', 'stok_rusak']);

                                            if ($material) {
                                                return round((float) $material->available, 2);
                                            }
                                        }

                                        return round((float) ($state ?? 0), 2);
                                    })
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

                        TextInput::make('payload.material_name')
                            ->label('Material')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_inbound_allocation_suggestion')
                            ->disabled(),
                        TextInput::make('payload.qty_dibutuhkan')
                            ->label('Qty Dibutuhkan')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_inbound_allocation_suggestion')
                            ->formatStateUsing(fn ($state, Get $get): float => round(
                                (float) ($state ?? collect($get('payload.allocation_suggestions') ?? [])->sum(
                                    fn ($row): float => (float) ($row['suggested_qty'] ?? 0)
                                )),
                                2
                            ))
                            ->suffix('unit')
                            ->disabled(),
                        TextInput::make('payload.total_available_qty')
                            ->label('Stok Tersedia untuk Dialokasikan')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_inbound_allocation_suggestion')
                            ->formatStateUsing(function ($state, Get $get): float {
                                $materialId = (int) ($get('payload.material_id') ?? 0);
                                $inboundId = (int) ($get('payload.inbound_id') ?? 0);

                                if ($materialId > 0 && $inboundId > 0) {
                                    return round((float) MaterialPiece::query()
                                        ->where('material_id', $materialId)
                                        ->where('inbound_id', $inboundId)
                                        ->sum('panjang'), 2);
                                }

                                return round((float) ($state ?? 0), 2);
                            })
                            ->suffix('unit')
                            ->disabled(),

                        Repeater::make('payload.allocation_suggestions')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_inbound_allocation_suggestion')
                            ->label('Kebutuhan Proyek (Sebelum Approve)')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                TextInput::make('project_code')
                                    ->label('Proyek')
                                    ->disabled(),
                                TextInput::make('suggested_qty')
                                    ->label('Qty Dibutuhkan Proyek')
                                    ->suffix('unit')
                                    ->disabled(),
                                TextInput::make('pr_status')
                                    ->label('Keterangan')
                                    ->disabled(),
                            ])
                            ->columns(3),

                        Select::make('payload.selected_piece_ids')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_inbound_allocation_suggestion')
                            ->label('Checklist Piece yang Dialokasikan')
                            ->multiple()
                            ->searchable()
                            ->options(function (Get $get): array {
                                $pieces = collect($get('payload.available_pieces') ?? []);

                                if ($pieces->isEmpty()) {
                                    $materialId = (int) ($get('payload.material_id') ?? 0);
                                    $inboundId = (int) ($get('payload.inbound_id') ?? 0);

                                    if ($materialId > 0) {
                                        $pieces = MaterialPiece::query()
                                            ->where('material_id', $materialId)
                                            ->where('status', 'available')
                                            ->when($inboundId > 0, fn ($q) => $q->where('inbound_id', $inboundId))
                                            ->orderBy('created_at')
                                            ->limit(50)
                                            ->get(['id', 'panjang', 'sumber'])
                                            ->map(fn (MaterialPiece $piece): array => [
                                                'id' => (int) $piece->id,
                                                'panjang' => (float) $piece->panjang,
                                                'lokasi_rak' => '-',
                                                'umur_hari' => 0,
                                                'sumber' => (string) $piece->sumber,
                                            ]);
                                    }
                                }

                                return $pieces
                                    ->mapWithKeys(function (array $piece): array {
                                        $id = (int) ($piece['id'] ?? 0);
                                        if ($id <= 0) {
                                            return [];
                                        }

                                        $length = rtrim(rtrim(number_format((float) ($piece['panjang'] ?? 0), 2, '.', ''), '0'), '.');
                                        $rack = (string) ($piece['lokasi_rak'] ?? '-');
                                        $age = (int) ($piece['umur_hari'] ?? 0);
                                        $source = (string) ($piece['sumber'] ?? '-');

                                        return [
                                            $id => "Piece #{$id} | {$length}m | Rak {$rack} | {$source} | Masuk {$age} hari lalu",
                                        ];
                                    })
                                    ->all();
                            })
                            ->default(function (Get $get): array {
                                $selected = collect($get('payload.selected_piece_ids') ?? [])
                                    ->map(fn ($id): int => (int) $id)
                                    ->filter(fn (int $id): bool => $id > 0)
                                    ->values()
                                    ->all();

                                if (!empty($selected)) {
                                    return $selected;
                                }

                                $pieceIds = collect($get('payload.piece_ids') ?? [])
                                    ->map(fn ($id): int => (int) $id)
                                    ->filter(fn (int $id): bool => $id > 0)
                                    ->values()
                                    ->all();

                                if (!empty($pieceIds)) {
                                    return $pieceIds;
                                }

                                $materialId = (int) ($get('payload.material_id') ?? 0);
                                $inboundId = (int) ($get('payload.inbound_id') ?? 0);

                                if ($materialId <= 0) {
                                    return [];
                                }

                                return MaterialPiece::query()
                                    ->where('material_id', $materialId)
                                    ->where('status', 'available')
                                    ->when($inboundId > 0, fn ($q) => $q->where('inbound_id', $inboundId))
                                    ->orderBy('created_at')
                                    ->limit(50)
                                    ->pluck('id')
                                    ->map(fn ($id): int => (int) $id)
                                    ->values()
                                    ->all();
                            })
                            ->helperText('Centang piece yang ingin dipakai untuk alokasi. Jika kabel/potongan, sistem akan split sesuai qty alokasi.'),

                        Repeater::make('payload.manual_allocation')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_inbound_allocation_suggestion')
                            ->label('Ubah Qty Alokasi per Proyek')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->default(function (Get $get): array {
                                $existing = collect($get('payload.manual_allocation') ?? [])->filter(fn ($row): bool => is_array($row))->values();
                                if ($existing->isNotEmpty()) {
                                    return $existing->map(fn (array $row): array => [
                                        'selected' => (bool) ($row['selected'] ?? true),
                                        'project_id' => (int) ($row['project_id'] ?? 0),
                                        'project_code' => (string) ($row['project_code'] ?? ('#' . (int) ($row['project_id'] ?? 0))),
                                        'pr_status' => (string) ($row['pr_status'] ?? '-'),
                                        'qty' => (float) ($row['qty'] ?? $row['suggested_qty'] ?? 0),
                                    ])->all();
                                }

                                return collect($get('payload.allocation_suggestions') ?? [])
                                    ->filter(fn ($row): bool => is_array($row) && (int) ($row['project_id'] ?? 0) > 0)
                                    ->map(fn (array $row): array => [
                                        'selected' => true,
                                        'project_id' => (int) ($row['project_id'] ?? 0),
                                        'project_code' => (string) ($row['project_code'] ?? ('#' . (int) ($row['project_id'] ?? 0))),
                                        'pr_status' => (string) ($row['pr_status'] ?? '-'),
                                        'qty' => (float) ($row['suggested_qty'] ?? 0),
                                    ])
                                    ->values()
                                    ->all();
                            })
                            ->schema([
                                Toggle::make('selected')
                                    ->label('Alokasikan')
                                    ->default(true)
                                    ->inline(false),
                                TextInput::make('project_code')
                                    ->label('Proyek')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('pr_status')
                                    ->label('Keterangan')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('qty')
                                    ->label('Qty Dialokasikan')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                                TextInput::make('project_id')
                                    ->hidden(),
                            ])
                            ->columns(4),

                        Repeater::make('payload.deadstock_items')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_deadstock_recommendation')
                            ->label('Screening Deadstock')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                TextInput::make('material_name')
                                    ->label('Material')
                                    ->disabled(),
                                TextInput::make('available_qty')
                                    ->label('Stok Saat Ini')
                                    ->suffix(fn (Get $get): string => (string) ($get('satuan') ?: 'unit'))
                                    ->disabled(),
                                TextInput::make('last_used_days')
                                    ->label('Tidak Terpakai (hari)')
                                    ->disabled(),
                                TextInput::make('oldest_available_days')
                                    ->label('Umur Stok Tertua (hari)')
                                    ->disabled(),
                                TextInput::make('rekomendasi')
                                    ->label('Saran Gemini')
                                    ->disabled(),
                                TextInput::make('prioritas')
                                    ->label('Prioritas')
                                    ->disabled(),
                                Textarea::make('alasan')
                                    ->label('Alasan')
                                    ->rows(2)
                                    ->disabled()
                                    ->columnSpanFull(),
                                TextInput::make('manager_decision')
                                    ->label('Keputusan Manager')
                                    ->disabled(),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }
}