<?php

namespace App\Filament\Resources\Materials\Pages;

use App\Filament\Resources\Materials\MaterialResource;
use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\ShelfLocation;
use Filament\Resources\Pages\Page;

class StockByLocation extends Page
{
    protected static string $resource = MaterialResource::class;

    protected string $view = 'filament.resources.materials.pages.stock-by-location';

    protected static ?string $title = 'Lokasi Stok';

    public string $search = '';

    public string $shelfLocationId = '';

    public string $materialId = '';

    /**
     * @var array<int|string, string>
     */
    public array $shelfLocationOptions = [];

    /**
     * @var array<int|string, string>
     */
    public array $materialOptions = [];

    /**
     * @var array<int, array<string, mixed>>
     */
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
                (string) $shelf->id => trim(($shelf->kode_lokasi ?: ('RAK-' . $shelf->id)) . ' - ' . ($shelf->nama_lokasi ?: '-')),
            ])
            ->toArray();

        $this->materialOptions = Material::query()
            ->orderBy('nama_material')
            ->get(['id', 'kode_material', 'nama_material'])
            ->mapWithKeys(fn (Material $material) => [
                (string) $material->id => trim(($material->kode_material ?: '-') . ' - ' . ($material->nama_material ?: '-')),
            ])
            ->toArray();

        $this->rows = $this->loadRows();
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
        ], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRows(): array
    {
        $query = MaterialPiece::query()
            ->leftJoin('materials', 'materials.id', '=', 'material_pieces.material_id')
            ->leftJoin('shelf_locations', 'shelf_locations.id', '=', 'material_pieces.shelf_location_id')
            ->whereIn('status', ['available', 'booked'])
            ->selectRaw("COALESCE(shelf_locations.kode_lokasi, 'TANPA RAK') as shelf_code")
            ->selectRaw("COALESCE(shelf_locations.nama_lokasi, 'Tanpa lokasi rak') as shelf_name")
            ->selectRaw('materials.kode_material as material_code')
            ->selectRaw('materials.nama_material as material_name')
            ->selectRaw("COALESCE(materials.satuan, 'unit') as unit")
            ->selectRaw("CASE
                WHEN LOWER(COALESCE(materials.satuan, '')) IN ('m', 'meter', 'meters', 'mtr')
                    AND material_pieces.sumber IN ('split_auto_allocation', 'split_booking', 'split_booking_residue', 'split_inbound_remainder') THEN 'Potongan'
                ELSE 'Utuh'
            END as piece_type")
            ->selectRaw("SUM(CASE WHEN material_pieces.status = 'available' THEN material_pieces.panjang ELSE 0 END) as available_qty")
            ->selectRaw("SUM(CASE WHEN material_pieces.status = 'booked' THEN material_pieces.panjang ELSE 0 END) as booked_qty")
            ->selectRaw('COUNT(material_pieces.id) as piece_count')
            ->groupByRaw("COALESCE(shelf_locations.kode_lokasi, 'TANPA RAK')")
            ->groupByRaw("COALESCE(shelf_locations.nama_lokasi, 'Tanpa lokasi rak')")
            ->groupBy('materials.kode_material', 'materials.nama_material')
            ->groupByRaw("COALESCE(materials.satuan, 'unit')")
            ->groupByRaw("CASE
                WHEN LOWER(COALESCE(materials.satuan, '')) IN ('m', 'meter', 'meters', 'mtr')
                    AND material_pieces.sumber IN ('split_auto_allocation', 'split_booking', 'split_booking_residue', 'split_inbound_remainder') THEN 'Potongan'
                ELSE 'Utuh'
            END");

        if ($this->shelfLocationId !== '') {
            $query->where('material_pieces.shelf_location_id', (int) $this->shelfLocationId);
        }

        if ($this->materialId !== '') {
            $query->where('material_pieces.material_id', (int) $this->materialId);
        }

        if ($this->search !== '') {
            $keyword = '%' . strtolower($this->search) . '%';

            $query->where(function ($builder) use ($keyword): void {
                $builder
                        ->whereRaw("LOWER(COALESCE(materials.nama_material, '')) LIKE ?", [$keyword])
                        ->orWhereRaw("LOWER(COALESCE(materials.kode_material, '')) LIKE ?", [$keyword])
                        ->orWhereRaw("LOWER(COALESCE(shelf_locations.kode_lokasi, '')) LIKE ?", [$keyword])
                        ->orWhereRaw("LOWER(COALESCE(shelf_locations.nama_lokasi, '')) LIKE ?", [$keyword]);
            });
        }

        $rows = $query
            ->orderBy('shelf_code')
            ->orderBy('material_name')
            ->orderBy('piece_type')
            ->get()
            ->map(function ($row): array {
                $availableQty = (float) $row->available_qty;
                $bookedQty = (float) $row->booked_qty;

                return [
                    'shelf_label' => trim(((string) $row->shelf_code) . ' - ' . ((string) $row->shelf_name)),
                    'material_code' => (string) ($row->material_code ?: '-'),
                    'material_name' => (string) ($row->material_name ?: 'Material tidak diketahui'),
                    'unit' => (string) ($row->unit ?: 'unit'),
                    'piece_type' => (string) ($row->piece_type ?: 'Utuh'),
                    'available_qty' => round($availableQty, 2),
                    'booked_qty' => round($bookedQty, 2),
                    'total_qty' => round($availableQty + $bookedQty, 2),
                    'piece_count' => (int) $row->piece_count,
                ];
            })
            ->values()
            ->all();

        return $rows;
    }
}
