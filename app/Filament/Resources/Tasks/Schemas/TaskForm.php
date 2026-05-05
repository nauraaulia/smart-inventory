<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\Task;
use Carbon\Carbon;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
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
                            ->visible(fn (?Task $record): bool => ! in_array($record?->type, ['ai_booking_suggestion', 'ai_inbound_allocation_suggestion'], true))
                            ->options([
                                'pending' => 'Pending (Menunggu Konfirmasi)',
                                'done' => 'Done (Disetujui & Diproses)',
                                'rejected' => 'Rejected (Ditolak)',
                            ])
                            ->live()
                            ->disabled(function (Get $get, ?Task $record): bool {
                                if ($record?->type === 'ai_inbound_allocation_suggestion') {
                                    return true;
                                }

                                if ($record?->type !== 'ai_booking_suggestion') {
                                    return false;
                                }

                                return collect($get('payload.items') ?? [])
                                    ->contains(fn ($item): bool => in_array((string) ($item['status'] ?? 'pending'), ['approved', 'rejected'], true));
                            })
                            ->helperText(fn (?Task $record): ?string => $record?->type === 'ai_booking_suggestion'
                                ? 'Pilih Approve atau Tolak untuk setiap material, lalu klik Submit.'
                                : ($record?->type === 'ai_inbound_allocation_suggestion'
                                    ? 'Untuk task operasional, langsung edit alokasi lalu klik Save changes untuk approve + booking.'
                                    : null))
                            ->required(),
                        Textarea::make('rejection_reason')
                            ->label('Alasan Reject')
                            ->visible(fn (?Task $record, Get $get): bool => $record?->type !== 'ai_booking_suggestion'
                                && self::shouldShowGlobalRejectionReason($record, $get))
                            ->required(fn (?Task $record, Get $get): bool => $record?->type !== 'ai_booking_suggestion'
                                && self::shouldShowGlobalRejectionReason($record, $get))
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
                                Textarea::make('purchase_recommendation_text')
                                    ->label('Rekomendasi Pembelian')
                                    ->rows(6)
                                    ->visible(fn (Get $get): bool => (float) ($get('recommended_pr_qty') ?? 0) > 0)
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpanFull(),
                                Textarea::make('recommended_pieces')
                                    ->label('Detail Piece')
                                    ->rows(4)
                                    ->formatStateUsing(function ($state, Get $get): string {
                                        if (! is_array($state)) {
                                            return '-';
                                        }

                                        $materialId = (int) ($get('material_id') ?? 0);
                                        $material = $materialId > 0 ? Material::query()->find($materialId) : null;
                                        $satuan = (string) ($material?->resolveTrackingUnit() ?: data_get($state, '0.satuan', 'unit'));
                                        $isDiscreteUnit = Material::usesDiscreteUnitTrackingFor($satuan, $material?->default_roll_length);
                                        $itemLabel = $isDiscreteUnit ? 'Item' : 'Piece';

                                        $lines = collect($state)->map(function ($piece) use ($itemLabel, $isDiscreteUnit, $satuan): string {
                                            $id = (int) data_get($piece, 'id', 0);
                                            $take = (float) data_get($piece, 'take_qty', 0);
                                            $len = (float) data_get($piece, 'panjang', 0);
                                            $age = (int) data_get($piece, 'age_days', 0);
                                            $rack = (string) data_get($piece, 'rack_label', '-');
                                            $reason = trim((string) data_get($piece, 'reason', ''));
                                            $totalText = rtrim(rtrim(number_format($len, 2, '.', ''), '0'), '.');
                                            $takeText = rtrim(rtrim(number_format($take, 2, '.', ''), '0'), '.');
                                            $sisaText = rtrim(rtrim(number_format(max(0, $len - $take), 2, '.', ''), '0'), '.');

                                            $text = "{$itemLabel} #{$id} | {$totalText} {$satuan} | Rak {$rack} | Masuk {$age} hari lalu";
                                            if ($take + 0.00001 < $len) {
                                                if ($isDiscreteUnit) {
                                                    $text .= " | Ambil: {$takeText} {$satuan}, Sisa: {$sisaText} {$satuan}";
                                                } else {
                                                    $text .= " | Potong: {$takeText} {$satuan}, Sisa: {$sisaText} {$satuan}";
                                                }
                                            } else {
                                                $text .= ' | Ambil utuh';
                                            }
                                            if ($reason !== '') {
                                                $text .= "\n  Alasan: {$reason}";
                                            }

                                            return $text;
                                        })->values()->all();

                                        return empty($lines) ? '-' : implode("\n", $lines);
                                    })
                                    ->disabled()
                                    ->dehydrated(false),
                                Textarea::make('ai_reason')
                                    ->label('Analisis AI')
                                    ->rows(5)
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
                                    ->live()
                                    ->default('pending')
                                    ->required(),
                                Textarea::make('rejection_reason')
                                    ->label('Alasan Penolakan')
                                    ->rows(2)
                                    ->visible(fn (Get $get): bool => (string) ($get('status') ?? 'pending') === 'rejected')
                                    ->required(fn (Get $get): bool => (string) ($get('status') ?? 'pending') === 'rejected')
                                    ->columnSpanFull(),
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
                            ->suffix(fn (Get $get): string => self::resolveTaskMaterialUnit($get))
                            ->disabled(),
                        TextInput::make('payload.total_available_qty')
                            ->label('Stok Tersedia untuk Dialokasikan')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_inbound_allocation_suggestion')
                            ->formatStateUsing(function ($state, Get $get): float {
                                $materialId = (int) ($get('payload.material_id') ?? 0);

                                if ($materialId > 0) {
                                    return round((float) MaterialPiece::query()
                                        ->where('material_id', $materialId)
                                        ->where('status', 'available')
                                        ->sum('panjang'), 2);
                                }

                                return round((float) ($state ?? 0), 2);
                            })
                            ->suffix(fn (Get $get): string => self::resolveTaskMaterialUnit($get))
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
                                    ->columnSpan(5)
                                    ->disabled(),
                                TextInput::make('suggested_qty')
                                    ->label('Qty Dibutuhkan Proyek')
                                    ->suffix(fn (Get $get): string => self::resolveTaskMaterialUnit($get))
                                    ->columnSpan(4)
                                    ->disabled(),
                                TextInput::make('pr_status')
                                    ->label('Keterangan')
                                    ->columnSpan(3)
                                    ->disabled(),
                            ])
                            ->columns(12),

                        Select::make('payload.selected_piece_ids')
                            ->visible(fn (?Task $record): bool => self::requiresExplicitPieceSelection($record))
                            ->label('Checklist Piece yang Dialokasikan')
                            ->multiple()
                            ->searchable()
                            ->required(fn (?Task $record): bool => self::requiresExplicitPieceSelection($record))
                            ->options(function (Get $get): array {
                                $materialId = (int) ($get('payload.material_id') ?? 0);
                                $fallbackUnit = self::resolveTaskMaterialUnit($get);

                                if ($materialId <= 0) {
                                    return [];
                                }

                                return MaterialPiece::query()
                                    ->with(['shelfLocation:id,kode_lokasi,nama_lokasi', 'inbound:id,entry_date', 'material:id,satuan,default_roll_length'])
                                    ->where('material_id', $materialId)
                                    ->where('status', 'available')
                                    ->orderByRaw("CASE
                                        WHEN sumber IN ('split_auto_allocation', 'split_booking', 'split_booking_residue') THEN 1
                                        WHEN sumber IN ('inbound_baru', 'opening_balance') THEN 2
                                        ELSE 3
                                    END ASC")
                                    ->orderBy('created_at')
                                    ->get(['id', 'material_id', 'panjang', 'sumber', 'shelf_location_id', 'inbound_id', 'created_at'])
                                    ->mapWithKeys(function (MaterialPiece $piece) use ($fallbackUnit): array {
                                        $id = (int) $piece->id;

                                        if ($id <= 0) {
                                            return [];
                                        }

                                        $length = rtrim(rtrim(number_format((float) $piece->panjang, 2, '.', ''), '0'), '.');
                                        $rack = (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-'));
                                        $source = (string) ($piece->sumber ?: '-');
                                        $unit = (string) ($piece->material?->resolveTrackingUnit() ?: $fallbackUnit);
                                        $itemLabel = Material::usesDiscreteUnitTrackingFor($unit, $piece->material?->default_roll_length) ? 'Item' : 'Piece';
                                        $receivedAt = $piece->inbound?->entry_date
                                            ? Carbon::parse($piece->inbound->entry_date)
                                            : $piece->created_at;
                                        $age = $receivedAt ? (int) $receivedAt->diffInDays(now()) : 0;

                                        return [
                                            $id => "{$itemLabel} #{$id} | {$length} {$unit} | Rak {$rack} | {$source} | Masuk {$age} hari lalu",
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

                                return $selected;
                            }),

                        Toggle::make('payload.add_purchase_request')
                            ->visible(fn (?Task $record): bool => $record?->type === 'ai_inbound_allocation_suggestion')
                            ->label('Tambahkan Purchase Request')
                            ->default(false)
                            ->inline(false),

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
                                        'project_code' => (string) ($row['project_code'] ?? ('#'.(int) ($row['project_id'] ?? 0))),
                                        'pr_status' => (string) ($row['pr_status'] ?? '-'),
                                        'qty' => (float) ($row['qty'] ?? $row['suggested_qty'] ?? 0),
                                    ])->all();
                                }

                                return collect($get('payload.allocation_suggestions') ?? [])
                                    ->filter(fn ($row): bool => is_array($row) && (int) ($row['project_id'] ?? 0) > 0)
                                    ->map(fn (array $row): array => [
                                        'selected' => true,
                                        'project_id' => (int) ($row['project_id'] ?? 0),
                                        'project_code' => (string) ($row['project_code'] ?? ('#'.(int) ($row['project_id'] ?? 0))),
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
                                    ->columnSpan(2)
                                    ->inline(false),
                                TextInput::make('project_code')
                                    ->label('Proyek')
                                    ->columnSpan(4)
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('pr_status')
                                    ->label('Keterangan')
                                    ->columnSpan(3)
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('qty')
                                    ->label('Qty Dialokasikan')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix(fn (Get $get): string => self::resolveTaskMaterialUnit($get))
                                    ->columnSpan(3)
                                    ->required(),
                                Hidden::make('project_id'),
                            ])
                            ->columns(12),

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

    private static function resolveTaskMaterialUnit(Get $get): string
    {
        $materialId = (int) ($get('payload.material_id') ?? $get('material_id') ?? 0);

        if ($materialId > 0) {
            $material = Material::query()->find($materialId, ['id', 'satuan', 'default_roll_length']);

            if ($material) {
                return (string) $material->resolveTrackingUnit();
            }
        }

        foreach ([
            (string) ($get('payload.unit') ?? ''),
            (string) ($get('payload.satuan') ?? ''),
            (string) ($get('unit') ?? ''),
            (string) ($get('satuan') ?? ''),
        ] as $fallback) {
            $normalized = trim($fallback);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return 'unit';
    }

    private static function requiresExplicitPieceSelection(?Task $record): bool
    {
        if ($record?->type !== 'ai_inbound_allocation_suggestion') {
            return false;
        }

        return $record->related_type === 'project'
            || (int) data_get($record->payload, 'source_ai_task_id', 0) > 0;
    }

    private static function shouldShowGlobalRejectionReason(?Task $record, Get $get): bool
    {
        if ($record?->type === 'ai_booking_suggestion') {
            return (string) ($get('status') ?? '') === 'rejected';
        }

        return (string) ($get('status') ?? '') === 'rejected';
    }
}
