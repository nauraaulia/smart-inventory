<?php

namespace App\Filament\Resources\Outbounds\Concerns;

use App\Models\Material;
use App\Models\MaterialPiece;
use Carbon\Carbon;
use Filament\Notifications\Notification;

trait HandlesManualOutboundPieces
{
    /**
     * @param  mixed  $state
     * @return array<int, int>
     */
    protected function manualOutboundPieceIds(mixed $state): array
    {
        return collect($state ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $includeIds
     * @return array<int, string>
     */
    protected function manualOutboundPieceOptions(int $materialId, array $includeIds = []): array
    {
        if ($materialId <= 0) {
            return [];
        }

        $includeIds = collect($includeIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return MaterialPiece::query()
            ->with([
                'bookedForProject:id,kode_proyek',
                'inbound:id,entry_date',
                'material:id,nama_material,satuan,default_roll_length',
                'shelfLocation:id,kode_lokasi,nama_lokasi',
            ])
            ->where('material_id', $materialId)
            ->where(function ($query) use ($includeIds): void {
                $query->whereIn('status', ['available', 'booked']);

                if (! empty($includeIds)) {
                    $query->orWhereIn('id', $includeIds);
                }
            })
            ->orderByRaw("CASE status WHEN 'available' THEN 0 WHEN 'booked' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->get(['id', 'piece_code', 'material_id', 'inbound_id', 'panjang', 'status', 'sumber', 'shelf_location_id', 'booked_for_project_id', 'created_at'])
            ->mapWithKeys(fn (MaterialPiece $piece): array => [
                (int) $piece->id => $this->manualOutboundPieceOptionLabel($piece),
            ])
            ->all();
    }

    protected function manualOutboundSelectedQty(mixed $pieceIds): string
    {
        $ids = $this->manualOutboundPieceIds($pieceIds);

        if (empty($ids)) {
            return '';
        }

        $qty = (float) MaterialPiece::query()
            ->whereIn('id', $ids)
            ->sum('panjang');

        return number_format($qty, 2, '.', '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>|null
     */
    protected function normalizeManualOutboundItemRows(array $rows): ?array
    {
        $normalized = [];
        $allSelectedIds = [];

        foreach ($rows as $row) {
            $selectedIds = $this->manualOutboundPieceIds($row['selected_piece_ids'] ?? []);

            if (empty($selectedIds)) {
                Notification::make()
                    ->title('Pilih pieces dulu')
                    ->body('Setiap material outbound manual harus punya minimal satu piece yang dipilih.')
                    ->warning()
                    ->send();

                return null;
            }

            $allSelectedIds = array_merge($allSelectedIds, $selectedIds);
        }

        $duplicateIds = collect($allSelectedIds)
            ->duplicates()
            ->unique()
            ->values()
            ->all();

        if (! empty($duplicateIds)) {
            Notification::make()
                ->title('Piece terpilih ganda')
                ->body('Piece #'.implode(', #', $duplicateIds).' dipilih lebih dari satu baris material.')
                ->warning()
                ->send();

            return null;
        }

        $piecesById = MaterialPiece::query()
            ->with('material:id,nama_material,satuan,default_roll_length')
            ->whereIn('id', $allSelectedIds)
            ->get(['id', 'material_id', 'panjang', 'status'])
            ->keyBy('id');

        $missingIds = collect($allSelectedIds)
            ->reject(fn (int $id): bool => $piecesById->has($id))
            ->values()
            ->all();

        if (! empty($missingIds)) {
            Notification::make()
                ->title('Piece tidak ditemukan')
                ->body('Piece #'.implode(', #', $missingIds).' tidak ada di stok.')
                ->warning()
                ->send();

            return null;
        }

        $invalidPieces = $piecesById
            ->filter(fn (MaterialPiece $piece): bool => ! in_array((string) $piece->status, ['available', 'booked'], true));

        if ($invalidPieces->isNotEmpty()) {
            Notification::make()
                ->title('Piece tidak bisa dipakai')
                ->body('Piece #'.$invalidPieces->keys()->implode(', #').' sudah tidak berstatus available/booked.')
                ->warning()
                ->send();

            return null;
        }

        foreach ($rows as $row) {
            $materialId = (int) ($row['material_id'] ?? 0);
            $selectedIds = $this->manualOutboundPieceIds($row['selected_piece_ids'] ?? []);

            if ($materialId <= 0) {
                Notification::make()
                    ->title('Material belum dipilih')
                    ->body('Lengkapi material pada semua baris outbound manual.')
                    ->warning()
                    ->send();

                return null;
            }

            $wrongMaterialIds = collect($selectedIds)
                ->filter(fn (int $id): bool => (int) ($piecesById[$id]?->material_id ?? 0) !== $materialId)
                ->values()
                ->all();

            if (! empty($wrongMaterialIds)) {
                Notification::make()
                    ->title('Piece tidak sesuai material')
                    ->body('Piece #'.implode(', #', $wrongMaterialIds).' bukan milik material pada baris yang dipilih.')
                    ->warning()
                    ->send();

                return null;
            }

            $selectedPieces = collect($selectedIds)->map(fn (int $id) => $piecesById[$id]);
            $qty = round((float) $selectedPieces->sum('panjang'), 2);
            $material = Material::query()->find($materialId, ['id', 'satuan', 'default_roll_length']);
            $unit = (string) ($material?->resolveTrackingUnit() ?: $material?->satuan ?: 'unit');

            $normalized[] = [
                'id' => (int) ($row['id'] ?? 0),
                'material_id' => $materialId,
                'qty' => number_format($qty, 2, '.', ''),
                'unit' => $unit,
                'selected_piece_ids' => $selectedIds,
            ];
        }

        return $normalized;
    }

    protected function manualOutboundPieceOptionLabel(MaterialPiece $piece): string
    {
        $unit = (string) ($piece->material?->resolveTrackingUnit() ?: $piece->material?->satuan ?: 'unit');
        $itemLabel = Material::usesDiscreteUnitTrackingFor($unit, $piece->material?->default_roll_length) ? 'Item' : 'Piece';
        $qty = $this->formatManualOutboundQty((float) $piece->panjang);
        $rack = (string) ($piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-'));
        $source = (string) ($piece->sumber ?: '-');
        $code = trim((string) ($piece->piece_code ?: ''));
        $codeText = $code !== '' ? " ({$code})" : '';
        $statusText = match ((string) $piece->status) {
            'available' => 'Available',
            'booked' => 'Booked'.($piece->bookedForProject?->kode_proyek ? ' - '.$piece->bookedForProject->kode_proyek : ''),
            'used' => 'Used',
            'damaged' => 'Rusak',
            default => ucfirst((string) $piece->status),
        };
        $receivedAt = $piece->inbound?->entry_date
            ? Carbon::parse($piece->inbound->entry_date)
            : $piece->created_at;
        $age = $receivedAt ? (int) $receivedAt->diffInDays(now()) : 0;

        return "{$itemLabel} #{$piece->id}{$codeText} | {$qty} {$unit} | Rak {$rack} | {$statusText} | {$source} | Masuk {$age} hari lalu";
    }

    protected function formatManualOutboundQty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
