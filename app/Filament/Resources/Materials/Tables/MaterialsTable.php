<?php

namespace App\Filament\Resources\Materials\Tables;

use App\Models\MaterialPiece;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class MaterialsTable
{
    public static function configure(Table $table): Table
    {
        $role = auth()->user()?->role;
        $canManage = in_array($role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true);

        return $table
            ->poll('5s')
            ->columns([
                TextColumn::make('kode_material')
                    ->label('Kode/SKU')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nama_material')
                    ->label('Nama Barang')
                    ->searchable(),

                TextColumn::make('kategori')
                    ->label('Kategori'),

                TextColumn::make('satuan')
                    ->label('Unit'),

                TextColumn::make('default_roll_length')
                    ->label('Roll Standar')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn ($record) => ' ' . ($record->satuan ?: 'meter'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('defaultSupplier.nama_supplier')
                    ->label('Supplier Default')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('defaultSupplier.lead_time_hari')
                    ->label('Lead Time (hari)')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                // ── KOLOM STOK ──────────────────────────────────

                TextColumn::make('stok')
                    ->label('Stok Total')
                    ->badge()
                    ->color(fn ($state, $record) =>
                        $state <= $record->min_stok ? 'danger' : 'success'
                    )
                    ->sortable(),

                TextColumn::make('stok_rusak')
                    ->label('Rusak')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->default(0),

                TextColumn::make('booked')
                    ->label('Booked')
                    ->badge()
                    ->color('warning')
                    ->default(0)
                    ->tooltip(function ($record): ?string {
                        if ((int) ($record->booked ?? 0) <= 0) {
                            return null;
                        }

                        $rows = self::bookedBreakdownLines($record);

                        if (empty($rows)) {
                            return 'Belum ada detail booking per project.';
                        }

                        return "Booked per project:\n" . implode("\n", $rows);
                    }),

                TextColumn::make('available')
                    ->label('Available')
                    ->badge()
                    ->color(fn ($state, $record) =>
                        $state <= $record->min_stok ? 'danger' : 'success'
                    )
                    ->getStateUsing(fn ($record) => $record->available),
                    // ↑ pakai accessor dari Model, bukan hitung manual di sini

                TextColumn::make('stock_condition_summary')
                    ->label('Kondisi')
                    ->badge()
                    ->getStateUsing(fn ($record): string => self::stockConditionBadge($record))
                    ->color(fn (string $state): string => str_contains($state, 'Perlu') ? 'danger' : (str_contains($state, 'Retur') ? 'warning' : 'success'))
                    ->tooltip(fn ($record): ?string => self::stockConditionTooltip($record)),
            ])
            ->filters([])
            ->recordActions([
                Action::make('booked_detail')
                    ->label('Booked Detail')
                    ->icon('heroicon-o-list-bullet')
                    ->color('warning')
                    ->visible(fn ($record) => (int) ($record->booked ?? 0) > 0)
                    ->modalHeading(fn ($record) => 'Booked Detail - ' . $record->nama_material)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(function ($record) {
                        $rows = self::bookedBreakdownLines($record);
                        $text = empty($rows)
                            ? 'Belum ada detail booking per project.'
                            : implode("\n", $rows);

                        return new HtmlString('<pre style="white-space: pre-wrap; font-size: 13px; margin: 0;">' . e($text) . '</pre>');
                    }),
                Action::make('stock_condition_detail')
                    ->label('Kondisi Stok')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->modalHeading(fn ($record) => 'Kondisi Stok - ' . $record->nama_material)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(function ($record) {
                        $text = self::stockConditionTooltip($record) ?: 'Belum ada stok available.';

                        return new HtmlString('<pre style="white-space: pre-wrap; font-size: 13px; margin: 0;">' . e($text) . '</pre>');
                    }),
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make()
                    ->visible($canManage),
                \Filament\Actions\DeleteAction::make()
                    ->visible($canManage),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible($canManage),
                ]),
            ]);
    }

    private static function bookedBreakdownLines($record): array
    {
        $rows = MaterialPiece::query()
            ->where('material_id', $record->id)
            ->where('status', 'booked')
            ->whereNotNull('booked_for_project_id')
            ->with('bookedForProject:id,kode_proyek')
            ->get(['booked_for_project_id', 'panjang'])
            ->groupBy('booked_for_project_id')
            ->map(function ($pieces) {
                $projectCode = optional($pieces->first()->bookedForProject)->kode_proyek
                    ?: ('PRJ#' . (int) $pieces->first()->booked_for_project_id);

                $lengths = $pieces
                    ->pluck('panjang')
                    ->map(function ($value) {
                        $num = (float) $value;
                        return number_format($num, 1, '.', '');
                    })
                    ->values()
                    ->all();

                return $projectCode . ': ' . implode(' + ', $lengths);
            })
            ->values()
            ->all();

        return $rows;
    }

    private static function stockConditionBadge($record): string
    {
        $summary = self::stockConditionSummary($record);

        if ((float) ($summary['bad_qty'] ?? 0) > 0) {
            return 'Perlu Review';
        }

        if ((float) ($summary['retur_qty'] ?? 0) > 0) {
            return 'Ada Retur';
        }

        return 'Bagus';
    }

    private static function stockConditionTooltip($record): ?string
    {
        $summary = self::stockConditionSummary($record);
        if ((int) ($summary['total_piece'] ?? 0) <= 0) {
            return null;
        }

        $unit = (string) ($record->resolveTrackingUnit() ?: 'unit');
        $lines = collect((array) ($summary['conditions'] ?? []))
            ->map(function (float $qty, string $condition) use ($unit): string {
                return $condition . ': ' . number_format($qty, 2, '.', '') . ' ' . $unit;
            })
            ->values()
            ->all();

        if ((int) ($summary['retur_piece'] ?? 0) > 0) {
            $lines[] = 'Retur available: ' . (int) $summary['retur_piece'] . ' piece / ' . number_format((float) $summary['retur_qty'], 2, '.', '') . ' ' . $unit;
        }

        foreach ((array) ($summary['notes'] ?? []) as $note) {
            $lines[] = $note;
        }

        return empty($lines) ? null : implode("\n", $lines);
    }

    private static function stockConditionSummary($record): array
    {
        $rows = MaterialPiece::query()
            ->where('material_id', $record->id)
            ->where('status', 'available')
            ->with('inbound:id,jenis_inbound,kondisi,keterangan')
            ->get(['id', 'inbound_id', 'panjang', 'sumber', 'keterangan']);

        $summary = [
            'total_piece' => $rows->count(),
            'conditions' => [],
            'retur_piece' => 0,
            'retur_qty' => 0.0,
            'bad_qty' => 0.0,
            'notes' => [],
        ];

        foreach ($rows as $piece) {
            $condition = trim((string) ($piece->inbound?->kondisi ?? 'Bagus')) ?: 'Bagus';
            $inboundType = trim((string) ($piece->inbound?->jenis_inbound ?? ''));
            $qty = round((float) ($piece->panjang ?? 0), 2);
            $isRetur = mb_strtolower($inboundType) === 'retur' || str_contains(mb_strtolower((string) $piece->sumber), 'retur');

            $summary['conditions'][$condition] = round((float) ($summary['conditions'][$condition] ?? 0) + $qty, 2);

            if ($isRetur) {
                $summary['retur_piece']++;
                $summary['retur_qty'] = round((float) $summary['retur_qty'] + $qty, 2);
            }

            if ($condition !== 'Bagus') {
                $summary['bad_qty'] = round((float) $summary['bad_qty'] + $qty, 2);
            }

            $note = trim((string) ($piece->keterangan ?: $piece->inbound?->keterangan ?: ''));
            if (($isRetur || $condition !== 'Bagus') && $note !== '') {
                $summary['notes'][] = 'Piece #'.(int) $piece->id.' ['.$condition.($isRetur ? ', Retur' : '').']: '.$note;
            }
        }

        $summary['notes'] = array_values(array_unique(array_slice($summary['notes'], 0, 8)));

        return $summary;
    }
}
