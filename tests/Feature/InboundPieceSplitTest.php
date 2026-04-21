<?php

namespace Tests\Feature;

use App\Models\Inbound;
use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\ShelfLocation;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundPieceSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_material_inbound_is_split_into_one_unit_per_piece(): void
    {
        $material = Material::query()->create([
            'kode_material' => 'MAT-BAT-001',
            'nama_material' => 'Battery Backup 100Ah',
            'stok' => 0,
            'satuan' => 'unit',
            'min_stok' => 0,
        ]);

        $supplier = Supplier::query()->create([
            'kode_supplier' => 'SUP-001',
            'nama_supplier' => 'PT Supplier Unit',
        ]);

        $shelf = ShelfLocation::query()->create([
            'kode_lokasi' => 'A-01',
            'nama_lokasi' => 'Rak A-01',
        ]);

        Inbound::query()->create([
            'material_id' => $material->id,
            'quantity' => 4,
            'entry_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'shelf_location_id' => $shelf->id,
            'satuan' => 'unit',
            'penerima' => 'Tester',
            'jenis_inbound' => 'Baru',
            'is_opening_balance' => false,
            'kondisi' => 'Bagus',
        ]);

        $pieces = MaterialPiece::query()
            ->where('material_id', $material->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $pieces);
        $this->assertSame([1.0, 1.0, 1.0, 1.0], $pieces->pluck('panjang')->map(fn ($qty): float => (float) $qty)->all());
        $this->assertCount(4, $pieces->pluck('piece_code')->filter()->unique());
    }

    public function test_roll_material_inbound_still_uses_roll_length_split(): void
    {
        $material = Material::query()->create([
            'kode_material' => 'MAT-KBL-001',
            'nama_material' => 'Kabel FO 12 Core',
            'stok' => 0,
            'satuan' => 'meter',
            'min_stok' => 0,
            'default_roll_length' => 300,
        ]);

        $supplier = Supplier::query()->create([
            'kode_supplier' => 'SUP-002',
            'nama_supplier' => 'PT Supplier Roll',
        ]);

        $shelf = ShelfLocation::query()->create([
            'kode_lokasi' => 'B-01',
            'nama_lokasi' => 'Rak B-01',
        ]);

        Inbound::query()->create([
            'material_id' => $material->id,
            'quantity' => 650,
            'entry_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'shelf_location_id' => $shelf->id,
            'satuan' => 'meter',
            'penerima' => 'Tester',
            'jenis_inbound' => 'Baru',
            'is_opening_balance' => false,
            'kondisi' => 'Bagus',
        ]);

        $pieces = MaterialPiece::query()
            ->where('material_id', $material->id)
            ->orderBy('id')
            ->get();

        $this->assertSame([300.0, 300.0, 50.0], $pieces->pluck('panjang')->map(fn ($qty): float => (float) $qty)->all());
    }
}
