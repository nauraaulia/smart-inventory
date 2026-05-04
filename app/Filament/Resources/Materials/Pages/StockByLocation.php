<?php

namespace App\Filament\Resources\Materials\Pages;

use App\Filament\Resources\Materials\MaterialResource;
use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\ShelfLocation;
use App\Models\UserLog;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class StockByLocation extends Page
{
    protected static string $resource = MaterialResource::class;

    protected string $view = 'filament.resources.materials.pages.stock-by-location';

    protected static ?string $title = 'Lokasi Stok';

    public string $search = '';

    public string $shelfLocationId = '';

    public string $materialId = '';

    public int $movingPieceId = 0;

    public string $targetShelfLocationId = '';

    public string $movingFromShelfLabel = '-';

    public bool $showMoveModal = false;

    /** @var array<int|string, string> */
    public array $shelfLocationOptions = [];

    /** @var array<int|string, string> */
    public array $materialOptions = [];

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->search = trim((string) request()->query('search', ''));
        $this->shelfLocationId = trim((string) request()->query('shelf_location_id', ''));
        $this->materialId = trim((string) request()->query('material_id', ''));

        $this->shelfLocationOptions = ShelfLocation::query()
            ->orderBy('kode_lokasi')
            ->get(['id', 'kode_lokasi', 'nama_lokasi'])
            ->mapWithKeys(fn (ShelfLocation $shelf) => [
                (string) $shelf->id => trim(($shelf->kode_lokasi ?: ('RAK-'.$shelf->id)).' - '.($shelf->nama_lokasi ?: '-')),
            ])
            ->toArray();

        $this->materialOptions = Material::query()
            ->orderBy('nama_material')
            ->get(['id', 'kode_material', 'nama_material'])
            ->mapWithKeys(fn (Material $material) => [
                (string) $material->id => trim(($material->kode_material ?: '-').' - '.($material->nama_material ?: '-')),
            ])
            ->toArray();

        // Tampilkan notifikasi setelah redirect berhasil
        if (request()->query('moved') === '1') {
            Notification::make()
                ->title('Berhasil dipindahkan!')
                ->success()
                ->send();
        }

        // Handle submit pindah rak
        $doMove = (int) request()->query('do_move', 0);
        $targetShelfId = (int) request()->query('target_shelf_location_id', 0);
        $movePieceId = (int) request()->query('move_piece_id', 0);

        if ($doMove && $movePieceId > 0) {
            $piece = MaterialPiece::find($movePieceId);
            $targetShelf = $targetShelfId > 0 ? ShelfLocation::find($targetShelfId) : null;

            if (! $piece) {
                Notification::make()
                    ->title('Piece tidak ditemukan.')
                    ->danger()
                    ->send();
            } elseif (! $targetShelf) {
                Notification::make()
                    ->title('Rak tujuan wajib dipilih.')
                    ->warning()
                    ->send();

                $this->openMoveModal($movePieceId);
            } else {
                $piece->update(['shelf_location_id' => $targetShelfId]);
                UserLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'Update',
                    'module' => 'Material',
                    'description' => 'Memindahkan piece #'.$movePieceId.' ke rak '.$targetShelf->kode_lokasi,
                    'ip_address' => request()->ip(),
                ]);

                $cancelUrl = MaterialResource::getUrl('stock-by-location')
                    .'?search='.urlencode($this->search)
                    .'&shelf_location_id='.urlencode((string) $targetShelfId)
                    .'&material_id='.urlencode($this->materialId)
                    .'&moved=1';

                header('Location: '.$cancelUrl);
                exit();
            }
        }

        $this->rows = $this->loadRows();

        if ($movePieceId > 0 && ! $doMove) {
            $this->openMoveModal($movePieceId);
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        return in_array(auth()->user()?->role, [
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
            'Manager Operasional',
            'Manajer Operasional',
            'Manager Teknik',
            'Administrator IT',
            \App\Models\User::ROLE_GENERAL_MANAGER,
        ], true);
    }

    public function getMoveUrl(int $pieceId): string
    {
        return MaterialResource::getUrl('stock-by-location')
            .'?move_piece_id='.$pieceId
            .'&search='.urlencode($this->search)
            .'&shelf_location_id='.urlencode($this->shelfLocationId)
            .'&material_id='.urlencode($this->materialId);
    }

    public function getCancelUrl(): string
    {
        return MaterialResource::getUrl('stock-by-location')
            .'?search='.urlencode($this->search)
            .'&shelf_location_id='.urlencode($this->shelfLocationId)
            .'&material_id='.urlencode($this->materialId);
    }

    public function openMoveModal(int $pieceId): void
    {
        $piece = MaterialPiece::query()
            ->with('shelfLocation:id,kode_lokasi,nama_lokasi')
            ->find($pieceId);

        if (! $piece) {
            return;
        }

        $this->movingPieceId = $pieceId;
        $shelfCode = (string) ($piece->shelfLocation?->kode_lokasi ?: 'TANPA RAK');
        $shelfName = (string) ($piece->shelfLocation?->nama_lokasi ?: 'Tanpa lokasi rak');
        $this->movingFromShelfLabel = trim($shelfCode.' - '.$shelfName);
        $this->targetShelfLocationId = '';
        $this->showMoveModal = true;
    }

    public function applyFilter(): void
    {
        $this->rows = $this->loadRows();
    }

    public function resetFilter(): void
    {
        $this->search = '';
        $this->shelfLocationId = '';
        $this->materialId = '';
        $this->rows = $this->loadRows();
    }

    /** @return array<int, array<string, mixed>> */
    private function loadRows(): array
    {
        $query = MaterialPiece::query()
            ->leftJoin('materials', 'materials.id', '=', 'material_pieces.material_id')
            ->leftJoin('shelf_locations', 'shelf_locations.id', '=', 'material_pieces.shelf_location_id')
            ->whereIn('status', ['available', 'booked'])
            ->selectRaw('material_pieces.id as piece_id')
            ->selectRaw('material_pieces.piece_code as piece_code')
            ->selectRaw('material_pieces.panjang as piece_qty')
            ->selectRaw('material_pieces.status as piece_status')
            ->selectRaw("COALESCE(shelf_locations.kode_lokasi, 'TANPA RAK') as shelf_code")
            ->selectRaw("COALESCE(shelf_locations.nama_lokasi, 'Tanpa lokasi rak') as shelf_name")
            ->selectRaw('materials.kode_material as material_code')
            ->selectRaw('materials.nama_material as material_name')
            ->selectRaw("COALESCE(materials.satuan, 'unit') as unit")
            ->selectRaw('materials.default_roll_length as default_roll_length');

        if ($this->shelfLocationId !== '') {
            $query->where('material_pieces.shelf_location_id', (int) $this->shelfLocationId);
        }

        if ($this->materialId !== '') {
            $query->where('material_pieces.material_id', (int) $this->materialId);
        }

        if ($this->search !== '') {
            $keyword = '%'.strtolower($this->search).'%';
            $query->where(function ($builder) use ($keyword): void {
                $builder
                    ->whereRaw("LOWER(COALESCE(materials.nama_material, '')) LIKE ?", [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(materials.kode_material, '')) LIKE ?", [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(shelf_locations.kode_lokasi, '')) LIKE ?", [$keyword])
                    ->orWhereRaw("LOWER(COALESCE(shelf_locations.nama_lokasi, '')) LIKE ?", [$keyword]);
            });
        }

        return $query
            ->orderBy('shelf_code')
            ->orderBy('material_code')
            ->orderBy('material_name')
            ->orderByRaw("CASE WHEN material_pieces.status = 'available' THEN 0 ELSE 1 END ASC")
            ->orderByDesc('material_pieces.panjang')
            ->orderBy('material_pieces.piece_code')
            ->orderBy('piece_id')
            ->get()
            ->map(function ($row): array {
                $pieceQty = (float) ($row->piece_qty ?? 0);
                $pieceStatus = (string) ($row->piece_status ?? 'available');
                $availableQty = $pieceStatus === 'available' ? $pieceQty : 0.0;
                $bookedQty = $pieceStatus === 'booked' ? $pieceQty : 0.0;
                $unit = (string) ($row->unit ?: 'unit');
                $isDiscreteUnit = Material::usesDiscreteUnitTrackingFor(
                    satuan: $unit,
                    defaultRollLength: $row->default_roll_length,
                );

                return [
                    'shelf_label' => trim(((string) $row->shelf_code).' - '.((string) $row->shelf_name)),
                    'material_code' => (string) ($row->material_code ?: '-'),
                    'material_name' => (string) ($row->material_name ?: 'Material tidak diketahui'),
                    'unit' => $unit,
                    'piece_code' => (string) ($row->piece_code ?: '-'),
                    'piece_id' => (int) ($row->piece_id ?? 0),
                    'piece_qty' => round($pieceQty, 2),
                    'piece_label' => $isDiscreteUnit ? 'Unit' : 'Piece',
                    'piece_status' => $pieceStatus,
                    'available_qty' => round($availableQty, 2),
                    'booked_qty' => round($bookedQty, 2),
                    'total_qty' => round($pieceQty, 2),
                ];
            })
            ->values()
            ->all();
    }
}
