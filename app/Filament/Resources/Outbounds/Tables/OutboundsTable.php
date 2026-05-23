<?php

namespace App\Filament\Resources\Outbounds\Tables;

use App\Filament\Resources\Outbounds\OutboundResource;
use App\Models\MaterialPiece;
use App\Models\Outbound;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class OutboundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.kode_proyek')
                    ->label('Kode Proyek')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('project.nama_proyek')
                    ->label('Nama Proyek')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status Outbound')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Menunggu',
                        'ready' => 'Siap',
                        'taken' => 'Sudah Diambil',
                        default => ucfirst($state),
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'ready' => 'info',
                        'taken' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('items_count')
                    ->label('Item')
                    ->getStateUsing(fn (Outbound $record): int => self::materialCountForOutbound($record))
                    ->badge()
                    ->color('warning'),
                TextColumn::make('barang_status')
                    ->label('Status Barang')
                    ->getStateUsing(fn (Outbound $record): string => self::fulfillmentStatusForOutbound($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Lengkap' => 'success',
                        'Kurang' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('project.tanggal_mulai')
                    ->label('Mulai')
                    ->date('d M Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('taken_at')
                    ->label('Waktu Diambil')
                    ->getStateUsing(fn (Outbound $record): ?string => $record->status === 'taken'
                        ? optional($record->taken_at)->format('d M Y H:i')
                        : null)
                    ->placeholder('-')
                    ->visible(fn (): bool => request()->query('activeTab') === 'taken')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Menunggu',
                        'ready' => 'Siap',
                        'taken' => 'Sudah Diambil',
                    ]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                Action::make('process_outbound')
                    ->label(fn (Outbound $record): string => match ($record->status) {
                        'pending' => 'Proses Outbound',
                        'ready' => 'Konfirmasi Pengambilan',
                        'taken' => 'Lihat Detail',
                        default => 'Buka Outbound',
                    })
                    ->icon('heroicon-o-truck')
                    ->color(fn (Outbound $record): string => match ($record->status) {
                        'taken' => 'success',
                        'ready' => 'info',
                        default => 'warning',
                    })
                    ->visible(fn (Outbound $record): bool => self::canSeeProcessAction($record))
                    ->url(fn (Outbound $record): string => OutboundResource::getUrl('view', ['record' => $record])),
            ]);
    }

    private static function canSeeProcessAction(Outbound $record): bool
    {
        $role = (string) (auth()->user()?->role ?? '');

        $isStaffGudang = in_array($role, [
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
        ], true);

        if ($isStaffGudang) {
            if ((string) $record->status === 'pending') {
                return self::fulfillmentStatusForOutbound($record) === 'Lengkap';
            }

            return in_array((string) $record->status, ['taken'], true);
        }

        $isManagerTeknik = in_array($role, [
            'Manager Teknik',
            'Manajer Teknik',
        ], true);

        if ($isManagerTeknik) {
            return in_array((string) $record->status, ['ready', 'taken'], true);
        }

        return $role === 'Administrator IT';
    }

    public static function requirementsModalContent(Project $record): HtmlString
    {
        $record->loadMissing([
            'items.material:id,nama_material,satuan,default_roll_length',
            'bookedPieces:id,booked_for_project_id,material_id,panjang,status,sumber',
        ]);

        $requiredByMaterial = $record->items
            ->groupBy('material_id')
            ->map(function ($items) {
                $first = $items->first();
                return [
                    'name' => (string) ($first?->material?->nama_material ?? 'Material tidak diketahui'),
                    'unit' => (string) ($first?->material?->resolveTrackingUnit() ?? 'unit'),
                    'required' => (float) $items->sum('jumlah_diminta'),
                ];
            });

        $bookedPiecesByMaterial = $record->bookedPieces
            ->where('status', 'booked')
            ->groupBy('material_id');

        if ($requiredByMaterial->isEmpty()) {
            return new HtmlString('<div class="text-sm text-gray-600">Belum ada daftar kebutuhan barang pada project ini.</div>');
        }

        $rowsHtml = '';

        foreach ($requiredByMaterial as $materialId => $data) {
            $required = (float) ($data['required'] ?? 0);
            $bookedPieces = $bookedPiecesByMaterial[(int) $materialId] ?? collect();
            $booked = (float) $bookedPieces->sum('panjang');
            $shortage = max(0, round($required - $booked, 2));
            $unit = (string) ($data['unit'] ?? 'unit');

            $requiredText = self::formatQty($required, $unit);
            $bookedText = self::formatQty($booked, $unit);
            $shortageText = self::formatQty($shortage, $unit);
            $shortageClass = $shortage > 0 ? 'text-rose-600 font-semibold' : 'text-emerald-600 font-semibold';
            $detailQtyColumn = self::isLengthUnit($unit) ? 'Panjang' : 'Jumlah';
            $detailTitle = self::isLengthUnit($unit)
                ? 'Booked Pieces (Kelompok per Segmen):'
                : 'Booked Items:';

            $rowsHtml .= '<tr class="border-b border-gray-200 dark:border-white/10">'
                . '<td class="px-3 py-2 text-sm text-gray-900 dark:text-white">' . e((string) $data['name']) . '</td>'
                . '<td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-200">' . e($requiredText) . '</td>'
                . '<td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-200">' . e($bookedText) . '</td>'
                . '<td class="px-3 py-2 text-sm ' . $shortageClass . '">' . e($shortageText) . '</td>'
                . '</tr>';

            // Tampilkan detail booked per segmen
            if ($bookedPieces->isNotEmpty()) {
                $rowsHtml .= '<tr><td colspan="4" class="p-0"><div class="pl-6 pb-2">'
                    . '<div class="text-xs font-semibold text-gray-500 dark:text-gray-300 mb-1">' . e($detailTitle) . '</div>'
                    . '<table class="min-w-full border border-gray-100 dark:border-white/10 mb-2">'
                    . '<thead class="bg-gray-50 dark:bg-white/5">'
                    . '<tr>'
                    . '<th class="px-2 py-1 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">' . e($detailQtyColumn) . '</th>'
                    . '<th class="px-2 py-1 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Sumber</th>'
                    . '</tr>'
                    . '</thead>'
                    . '<tbody>';
                foreach ($bookedPieces as $piece) {
                    $rowsHtml .= '<tr>'
                        . '<td class="px-2 py-1 text-xs">' . e(self::formatQty((float) $piece->panjang, $unit)) . '</td>'
                        . '<td class="px-2 py-1 text-xs">' . e($piece->sumber ?? '-') . '</td>'
                        . '</tr>';
                }
                $rowsHtml .= '</tbody></table></div></td></tr>';
            }
        }

        return new HtmlString(
            '<div class="space-y-3">'
            . '<div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-300">Daftar kebutuhan barang project</div>'
            . '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">'
            . '<table class="min-w-full">'
            . '<thead class="bg-gray-50 dark:bg-white/5">'
            . '<tr>'
            . '<th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Nama Barang</th>'
            . '<th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Dibutuhkan</th>'
            . '<th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Sudah Booked</th>'
            . '<th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Kurang</th>'
            . '</tr>'
            . '</thead>'
            . '<tbody>' . $rowsHtml . '</tbody>'
            . '</table>'
            . '</div>'
            . '</div>'
        );
    }

    private static function formatQty(float $value, string $unit): string
    {
        $number = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return trim($number . ' ' . $unit);
    }

    private static function isLengthUnit(string $unit): bool
    {
        $normalized = strtolower(trim($unit));

        return in_array($normalized, ['m', 'meter', 'meters', 'metre', 'meteran', 'mtr'], true);
    }

    private static function materialCountForOutbound(Outbound $record): int
    {
        if (!$record->project_id) {
            return (int) $record->items()
                ->distinct('material_id')
                ->count('material_id');
        }

        $requiredCount = (int) $record->project?->items()
            ->distinct('material_id')
            ->count('material_id');

        if ($requiredCount > 0) {
            return $requiredCount;
        }

        return (int) MaterialPiece::query()
            ->where('booked_for_project_id', (int) $record->project_id)
            ->whereIn('status', ['booked', 'used'])
            ->distinct('material_id')
            ->count('material_id');
    }

    private static function fulfillmentStatusForOutbound(Outbound $record): string
    {
        if (!$record->project_id) {
            return 'Manual';
        }

        $requiredRows = $record->project?->items()
            ->selectRaw('material_id, SUM(jumlah_diminta) as required_qty')
            ->groupBy('material_id')
            ->get();

        if (!$requiredRows || $requiredRows->isEmpty()) {
            return 'Manual';
        }

        $providedMap = MaterialPiece::query()
            ->selectRaw('material_id, SUM(panjang) as provided_qty')
            ->where('booked_for_project_id', (int) $record->project_id)
            ->whereIn('status', ['booked', 'used'])
            ->groupBy('material_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->material_id => (float) $row->provided_qty]);

        $hasShortage = $requiredRows
            ->contains(function ($row) use ($providedMap): bool {
                $required = (float) $row->required_qty;
                $provided = (float) ($providedMap[(int) $row->material_id] ?? 0);

                return $required > 0 && $provided + 0.00001 < $required;
            });

        return $hasShortage ? 'Kurang' : 'Lengkap';
    }
}
