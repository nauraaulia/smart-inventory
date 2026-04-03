<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\MaterialPiece;
use Illuminate\Console\Command;

class MigrateMaterialToPieces extends Command
{
    protected $signature = 'migrate:material-to-pieces 
                            {--dry-run : Cek data tanpa eksekusi}
                            {--force : Paksa migrate meskipun sudah ada pieces}';

    protected $description = 'Migrasi stok material lama ke tabel material_pieces';

    public function handle()
    {
        $this->info('🚀 Memulai migrasi data material ke pieces...');
        $this->newLine();

        $isDryRun = $this->option('dry-run');
        $isForce = $this->option('force');

        // Ambil semua material yang punya stok
        $materials = Material::where(function($q) {
            $q->where('stok', '>', 0)
              ->orWhere('stok_rusak', '>', 0);
        })->get();

        if ($materials->isEmpty()) {
            $this->warn('⚠️  Tidak ada material dengan stok > 0');
            return 0;
        }

        $this->info("📦 Ditemukan {$materials->count()} material dengan stok");
        $this->newLine();

        $bar = $this->output->createProgressBar($materials->count());
        $bar->start();

        $totalPiecesCreated = 0;
        $skipped = 0;

        foreach ($materials as $material) {
            // Skip kalau sudah punya pieces (kecuali pakai --force)
            if (!$isForce && $material->pieces()->exists()) {
                $skipped++;
                $bar->advance();
                continue;
            }

            // Hitung total piece yang akan dibuat
            $piecesToCreate = 0;

            // 1. Stok Available (stok - booked - rusak)
            $stokAvailable = $material->stok - $material->booked - $material->stok_rusak;

            if ($stokAvailable > 0) {
                $piecesToCreate++;
                
                if (!$isDryRun) {
                    MaterialPiece::create([
                        'material_id' => $material->id,
                        'panjang' => $stokAvailable,
                        'status' => 'available',
                        'sumber' => 'inbound_baru',
                        'keterangan' => 'Migrasi dari stok lama',
                    ]);
                }
            }

            // 2. Stok Booked
            if ($material->booked > 0) {
                $piecesToCreate++;
                
                if (!$isDryRun) {
                    MaterialPiece::create([
                        'material_id' => $material->id,
                        'panjang' => $material->booked,
                        'status' => 'booked',
                        'sumber' => 'inbound_baru',
                        'keterangan' => 'Migrasi dari stok booked lama',
                    ]);
                }
            }

            // 3. Stok Rusak
            if ($material->stok_rusak > 0) {
                $piecesToCreate++;
                
                if (!$isDryRun) {
                    MaterialPiece::create([
                        'material_id' => $material->id,
                        'panjang' => $material->stok_rusak,
                        'status' => 'damaged',
                        'sumber' => 'inbound_baru',
                        'keterangan' => 'Migrasi dari stok rusak lama',
                    ]);
                }
            }

            $totalPiecesCreated += $piecesToCreate;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        if ($isDryRun) {
            $this->info("🔍 DRY RUN MODE");
            $this->info("   Akan membuat: {$totalPiecesCreated} pieces");
            $this->info("   Dari: {$materials->count()} materials");
            if ($skipped > 0) {
                $this->warn("   Di-skip: {$skipped} materials (sudah punya pieces)");
            }
            $this->newLine();
            $this->comment('💡 Jalankan tanpa --dry-run untuk eksekusi');
        } else {
            $this->info("✅ Migrasi selesai!");
            $this->info("   Total pieces dibuat: {$totalPiecesCreated}");
            $this->info("   Dari: {$materials->count()} materials");
            if ($skipped > 0) {
                $this->warn("   Di-skip: {$skipped} materials (sudah punya pieces)");
            }
        }

        $this->newLine();

        // Verifikasi
        if (!$isDryRun) {
            $this->info('🔍 Verifikasi data...');
            
            foreach ($materials->take(3) as $material) {
                $material->refresh();
                
                $sumPieces = $material->pieces()
                    ->whereIn('status', ['available', 'booked'])
                    ->sum('panjang');
                
                $match = ($sumPieces == $material->stok) ? '✅' : '❌';
                
                $this->line("   {$match} {$material->kode_material}: stok={$material->stok}, pieces={$sumPieces}");
            }
            
            $this->newLine();
            $this->comment('💡 Cek detail pakai: php artisan tinker');
            $this->comment('   $material = Material::first();');
            $this->comment('   $material->pieces;');
        }

        return 0;
    }
}