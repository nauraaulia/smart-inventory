<?php

namespace App\Filament\Widgets;

use App\Models\Material;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MaterialStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalSku = Material::count();

        $totalStok = (int) Material::sum('stok');

        $totalBooked = (int) Material::sum('booked');

        $totalAvailable = $totalStok - $totalBooked;

        $stokHabis = Material::whereRaw('(stok - booked) <= 0')->count();

        return [
            Stat::make('Total SKU', $totalSku)
                ->description('Jenis material terdaftar')
                ->icon('heroicon-o-squares-2x2')
                ->color('primary'),

            Stat::make('Total Available', $totalAvailable)
                ->description('Stok siap pakai')
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Total Booked', $totalBooked)
                ->description('Direservasi proyek aktif')
                ->icon('heroicon-o-lock-closed')
                ->color('warning'),

            Stat::make('Stok Habis', $stokHabis)
                ->description('Material perlu pengadaan')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($stokHabis > 0 ? 'danger' : 'success'),
        ];
    }
}