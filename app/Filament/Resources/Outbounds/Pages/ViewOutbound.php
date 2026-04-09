<?php

namespace App\Filament\Resources\Outbounds\Pages;

use App\Filament\Resources\Outbounds\OutboundResource;
use App\Models\Project;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

class ViewOutbound extends Page
{
    protected static string $resource = OutboundResource::class;

    protected string $view = 'filament.resources.outbounds.pages.view-outbound';

    public Project $record;

    /**
     * @var array<int>
     */
    public array $pickedPieceIds = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $shortageRows = [];

    public string $currentDate = '';

    public function mount(Project $record): void
    {
        abort_unless($this->canAccessOutbound(auth()->user()?->role), 403);

        $this->record = $record;
        $this->currentDate = now()->translatedFormat('d F Y');

        $this->reloadRows();
    }

    public function getHeading(): string
    {
        return 'Outbound - ' . ($this->record->kode_proyek ?: ('Proyek #' . $this->record->id));
    }

    public function getSubheading(): ?string
    {
        return 'Verifikasi fisik pengambilan semua barang sebelum transaksi outbound diselesaikan.';
    }

    public function isAllChecked(): bool
    {
        $allIds = collect($this->rows)->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();

        if ($allIds->isEmpty()) {
            return false;
        }

        $checkedIds = collect($this->pickedPieceIds)->map(fn ($id) => (int) $id)->unique()->values();

        return $checkedIds->count() === $allIds->count() && $checkedIds->diff($allIds)->isEmpty();
    }

    public function confirmOutbound(): void
    {
        if ($this->hasShortage()) {
            Notification::make()
                ->title('Barang belum lengkap')
                ->body('Masih ada material yang belum terpenuhi dari booking stok. Lengkapi dulu sebelum outbound.')
                ->danger()
                ->send();

            return;
        }

        if (!$this->isAllChecked()) {
            Notification::make()
                ->title('Checklist belum lengkap')
                ->body('Semua barang harus dicentang sebelum outbound dikonfirmasi.')
                ->warning()
                ->send();

            return;
        }

        $pieceIds = collect($this->rows)->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($pieceIds): void {
            $this->record->bookedPieces()
                ->whereIn('id', $pieceIds)
                ->where('status', 'booked')
                ->update([
                    'status' => 'used',
                    'used_at' => now(),
                ]);

            $this->record->update([
                'status' => 'Processing',
                'outbound_confirmed_at' => now(),
            ]);
        });

        Notification::make()
            ->title('Outbound berhasil dikonfirmasi')
            ->success()
            ->send();

        $this->redirect(OutboundResource::getUrl('index'));
    }

    private function reloadRows(): void
    {
        $pieces = $this->record->bookedPieces()
            ->with(['material:id,nama_material,satuan', 'shelfLocation:id,kode_lokasi,nama_lokasi'])
            ->where('status', 'booked')
            ->orderBy('shelf_location_id')
            ->orderBy('material_id')
            ->get();

        $requiredByMaterial = $this->record->items()
            ->with('material:id,nama_material,satuan')
            ->get()
            ->groupBy('material_id')
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'material_name' => $first?->material?->nama_material ?: 'Material tidak diketahui',
                    'unit' => $first?->material?->satuan ?: 'unit',
                    'required' => (float) $items->sum('jumlah_diminta'),
                ];
            });

        $bookedQtyByMaterial = $pieces
            ->groupBy('material_id')
            ->map(fn ($materialPieces) => (float) $materialPieces->sum('panjang'));

        $pieceCountByMaterial = $pieces
            ->countBy(fn ($piece) => (int) $piece->material_id);

        $this->rows = $pieces->map(function ($piece) use ($pieceCountByMaterial): array {
            $qtyNumber = rtrim(rtrim(number_format((float) $piece->panjang, 2, '.', ''), '0'), '.');
            $unit = $piece->material?->satuan ?: 'unit';
            $pieceCount = (int) ($pieceCountByMaterial[(int) $piece->material_id] ?? 1);
            $isPotongan = $this->isLengthUnit($unit)
                && $pieceCount > 1;

            return [
                'id' => (int) $piece->id,
                'rack' => $piece->shelfLocation?->kode_lokasi ?: ($piece->shelfLocation?->nama_lokasi ?: '-'),
                'material_name' => $piece->material?->nama_material ?: 'Material tidak diketahui',
                'qty' => trim($qtyNumber . ' ' . $unit),
                'type' => $isPotongan ? 'Potongan' : 'Utuh',
            ];
        })->all();

        $this->shortageRows = $requiredByMaterial
            ->map(function (array $materialData, $materialId) use ($bookedQtyByMaterial): ?array {
                $required = (float) $materialData['required'];

                if ($required <= 0) {
                    return null;
                }

                $booked = (float) ($bookedQtyByMaterial[(int) $materialId] ?? 0);
                $shortage = max(0, round($required - $booked, 2));

                if ($shortage <= 0) {
                    return null;
                }

                $unit = (string) $materialData['unit'];

                return [
                    'material_name' => (string) $materialData['material_name'],
                    'required' => $this->formatQty($required, $unit),
                    'booked' => $this->formatQty($booked, $unit),
                    'shortage' => $this->formatQty($shortage, $unit),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $this->pickedPieceIds = [];
    }

    public function hasShortage(): bool
    {
        return !empty($this->shortageRows);
    }

    public function canConfirmOutbound(): bool
    {
        return $this->isAllChecked() && !$this->hasShortage();
    }

    private function isLengthUnit(string $unit): bool
    {
        $normalized = strtolower(trim($unit));

        return in_array($normalized, [
            'm',
            'meter',
            'meters',
            'mtr',
        ], true);
    }

    private function formatQty(float $value, string $unit): string
    {
        $number = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return trim($number . ' ' . $unit);
    }

    private function canAccessOutbound(?string $role): bool
    {
        return in_array($role, [
            'Manager Teknik',
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
            'Administrator IT',
        ], true);
    }
}
