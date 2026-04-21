<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\MaterialPiece;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SplitDiscreteMaterialPieces extends Command
{
    protected $signature = 'inventory:split-discrete-pieces
                            {--material= : Filter by material code or material ID}
                            {--dry-run : Show what would be split without changing data}';

    protected $description = 'Split legacy discrete-unit material pieces (e.g. 2 unit in 1 row) into individual item rows';

    public function handle(): int
    {
        $materialFilter = trim((string) $this->option('material'));
        $isDryRun = (bool) $this->option('dry-run');

        $candidates = MaterialPiece::query()
            ->with(['material:id,kode_material,nama_material,satuan,default_roll_length'])
            ->when($materialFilter !== '', function ($query) use ($materialFilter): void {
                $query->whereHas('material', function ($materialQuery) use ($materialFilter): void {
                    if (ctype_digit($materialFilter)) {
                        $materialQuery
                            ->where('materials.id', (int) $materialFilter)
                            ->orWhere('kode_material', $materialFilter);

                        return;
                    }

                    $materialQuery->where('kode_material', $materialFilter);
                });
            })
            ->where('panjang', '>', 1)
            ->orderBy('material_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (MaterialPiece $piece): bool => $piece->material?->usesDiscreteUnitTracking() === true)
            ->values();

        if ($candidates->isEmpty()) {
            $this->info('Tidak ada piece barang satuan yang perlu di-split.');

            return self::SUCCESS;
        }

        $previewRows = $candidates->map(function (MaterialPiece $piece): array {
            $segments = $this->buildSegments((float) $piece->panjang);

            return [
                'piece_id' => (int) $piece->id,
                'material_code' => (string) ($piece->material?->kode_material ?: '-'),
                'material_name' => (string) ($piece->material?->nama_material ?: '-'),
                'current_qty' => round((float) $piece->panjang, 2),
                'new_rows' => count($segments),
                'extra_rows' => max(0, count($segments) - 1),
                'status' => (string) $piece->status,
            ];
        });

        $this->info('Candidate discrete-unit pieces: '.$previewRows->count());
        $this->table(
            ['Piece ID', 'Material', 'Qty Lama', 'Row Baru', 'Tambahan', 'Status'],
            $previewRows->map(fn (array $row): array => [
                '#'.$row['piece_id'],
                $row['material_code'].' - '.$row['material_name'],
                $this->decimal($row['current_qty']),
                (string) $row['new_rows'],
                (string) $row['extra_rows'],
                $row['status'],
            ])->all()
        );

        if ($isDryRun) {
            $this->comment('Dry run only. Jalankan tanpa --dry-run untuk eksekusi.');

            return self::SUCCESS;
        }

        $createdRows = 0;
        $updatedRows = 0;
        $touchedMaterialIds = [];

        foreach ($candidates as $piece) {
            $result = DB::transaction(function () use ($piece): array {
                /** @var MaterialPiece|null $lockedPiece */
                $lockedPiece = MaterialPiece::query()
                    ->with(['material:id,kode_material,nama_material,satuan,default_roll_length'])
                    ->lockForUpdate()
                    ->find($piece->id);

                if (! $lockedPiece || ! $lockedPiece->material?->usesDiscreteUnitTracking()) {
                    return ['updated' => 0, 'created' => 0, 'material_id' => 0];
                }

                $segments = $this->buildSegments((float) $lockedPiece->panjang);
                if (count($segments) <= 1) {
                    return ['updated' => 0, 'created' => 0, 'material_id' => 0];
                }

                $originalQty = round((float) $lockedPiece->panjang, 2);
                $firstSegment = array_shift($segments);
                $noteSuffix = ' | Backfill split barang satuan lama dari qty '.$this->decimal($originalQty).'.';

                DB::table('material_pieces')
                    ->where('id', $lockedPiece->id)
                    ->update([
                        'panjang' => $this->decimal((float) $firstSegment),
                        'keterangan' => trim((string) ($lockedPiece->keterangan ?? '').$noteSuffix),
                        'updated_at' => now(),
                    ]);

                $insertRows = [];
                foreach ($segments as $index => $segment) {
                    $insertRows[] = [
                        'piece_code' => MaterialPiece::generateNextPieceCode((int) $lockedPiece->material_id),
                        'material_id' => $lockedPiece->material_id,
                        'inbound_id' => $lockedPiece->inbound_id,
                        'booked_for_project_id' => $lockedPiece->booked_for_project_id,
                        'shelf_location_id' => $lockedPiece->shelf_location_id,
                        'supplier_id' => $lockedPiece->supplier_id,
                        'panjang' => $this->decimal((float) $segment),
                        'unit_price' => $lockedPiece->unit_price,
                        'status' => $lockedPiece->status,
                        'sumber' => $lockedPiece->sumber,
                        'booked_at' => $lockedPiece->booked_at,
                        'used_at' => $lockedPiece->used_at,
                        'keterangan' => trim((string) ($lockedPiece->keterangan ?? '').$noteSuffix.' Clone #'.($index + 2).'.'),
                        'created_at' => $lockedPiece->created_at,
                        'updated_at' => now(),
                    ];
                }

                if (! empty($insertRows)) {
                    DB::table('material_pieces')->insert($insertRows);
                }

                return [
                    'updated' => 1,
                    'created' => count($insertRows),
                    'material_id' => (int) $lockedPiece->material_id,
                ];
            });

            $updatedRows += (int) ($result['updated'] ?? 0);
            $createdRows += (int) ($result['created'] ?? 0);

            $materialId = (int) ($result['material_id'] ?? 0);
            if ($materialId > 0) {
                $touchedMaterialIds[$materialId] = true;
            }
        }

        foreach (array_keys($touchedMaterialIds) as $materialId) {
            $material = Material::query()->find((int) $materialId);
            if (! $material) {
                continue;
            }

            $material->updateStokSummary();
            MaterialPiece::syncAffectedProjectsForMaterial((int) $material->id);
        }

        $this->info("Selesai. {$updatedRows} piece dipecah dan {$createdRows} row baru dibuat.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, float>
     */
    private function buildSegments(float $qty): array
    {
        $wholeUnits = (int) floor($qty);
        $remainder = round($qty - $wholeUnits, 2);

        $segments = array_fill(0, max(0, $wholeUnits), 1.0);
        if ($remainder > 0.00001) {
            $segments[] = $remainder;
        }

        return $segments;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
