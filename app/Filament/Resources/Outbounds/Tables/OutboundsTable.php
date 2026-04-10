<?php

namespace App\Filament\Resources\Outbounds\Tables;

use App\Filament\Resources\Outbounds\OutboundResource;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class OutboundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kode_proyek')
                    ->label('Kode Proyek')
                    ->searchable(),
                TextColumn::make('nama_proyek')
                    ->label('Nama Proyek')
                    ->searchable(),
                TextColumn::make('outbound_booked_count')
                    ->label('Item Reservasi')
                    ->badge()
                    ->color('warning'),
                TextColumn::make('tanggal_mulai')
                    ->label('Mulai')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('kelengkapan')
                    ->label('Kelengkapan')
                    ->getStateUsing(fn (Project $record): string => $record->outbound_is_complete ? 'Lengkap' : 'Belum Lengkap')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Lengkap' ? 'success' : 'warning'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Approved' ? 'info' : 'gray'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                Action::make('view_requirements')
                    ->label('Lihat Kebutuhan')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->modalHeading(fn (Project $record): string => 'Kebutuhan Project ' . ($record->kode_proyek ?: ('#' . $record->id)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalWidth('5xl')
                    ->modalContent(fn (Project $record): HtmlString => self::requirementsModalContent($record)),
                Action::make('process_outbound')
                    ->label('Proses Outbound')
                    ->icon('heroicon-o-truck')
                    ->color(fn (Project $record): string => $record->outbound_is_complete ? 'success' : 'warning')
                    ->url(fn (Project $record): string => OutboundResource::getUrl('view', ['record' => $record])),
            ]);
    }

    public static function requirementsModalContent(Project $record): HtmlString
    {
        $record->loadMissing([
            'items.material:id,nama_material,satuan',
            'bookedPieces:id,booked_for_project_id,material_id,panjang,status',
        ]);

        $requiredByMaterial = $record->items
            ->groupBy('material_id')
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'name' => (string) ($first?->material?->nama_material ?? 'Material tidak diketahui'),
                    'unit' => (string) ($first?->material?->satuan ?? 'unit'),
                    'required' => (float) $items->sum('jumlah_diminta'),
                ];
            });

        $bookedByMaterial = $record->bookedPieces
            ->where('status', 'booked')
            ->groupBy('material_id')
            ->map(fn ($pieces) => (float) $pieces->sum('panjang'));

        if ($requiredByMaterial->isEmpty()) {
            return new HtmlString('<div class="text-sm text-gray-600">Belum ada daftar kebutuhan barang pada project ini.</div>');
        }

        $rowsHtml = '';

        foreach ($requiredByMaterial as $materialId => $data) {
            $required = (float) ($data['required'] ?? 0);
            $booked = (float) ($bookedByMaterial[(int) $materialId] ?? 0);
            $shortage = max(0, round($required - $booked, 2));
            $unit = (string) ($data['unit'] ?? 'unit');

            $requiredText = self::formatQty($required, $unit);
            $bookedText = self::formatQty($booked, $unit);
            $shortageText = self::formatQty($shortage, $unit);
            $shortageClass = $shortage > 0 ? 'text-rose-600 font-semibold' : 'text-emerald-600 font-semibold';

            $rowsHtml .= '<tr class="border-b border-gray-200 dark:border-white/10">'
                . '<td class="px-3 py-2 text-sm text-gray-900 dark:text-white">' . e((string) $data['name']) . '</td>'
                . '<td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-200">' . e($requiredText) . '</td>'
                . '<td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-200">' . e($bookedText) . '</td>'
                . '<td class="px-3 py-2 text-sm ' . $shortageClass . '">' . e($shortageText) . '</td>'
                . '</tr>';
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
}
