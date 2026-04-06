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
            ->whereIn('status', ['booked', 'used'])
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
}