<?php

namespace App\Filament\Widgets;

use App\Models\Inbound;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InboundStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $baseUrl = route('filament.admin.resources.inbounds.index');

        $total = Inbound::count();
        $layak = Inbound::where(function ($q) {
            $q->where('jenis_inbound', 'Baru')
              ->orWhere(fn ($q) => $q->where('jenis_inbound', 'Retur')->where('kondisi', 'Bagus'));
        })->count();
        $deadstock = Inbound::whereIn('kondisi', ['Cacat Ringan', 'Cacat Berat'])->count();
        $hariIni = Inbound::whereDate('created_at', today())->count();

        return [
    Stat::make('Total Inbound', $total)
        ->description('Semua riwayat masuk')
        ->icon('heroicon-o-archive-box-arrow-down')
        ->color('primary')
        ->url($baseUrl . '?activeTab=all'),

    Stat::make('Stok Retur', Inbound::where('jenis_inbound', 'Retur')->count())
        ->description('Total material retur masuk')
        ->icon('heroicon-o-arrow-path')
        ->color('warning')
        ->url($baseUrl . '?activeTab=ready_stock'),

    Stat::make('Deadstock / Rusak', $deadstock)
        ->description('Perlu tindakan')
        ->icon('heroicon-o-trash')
        ->color('danger')
        ->url($baseUrl . '?activeTab=deadstock'),

    Stat::make('Masuk Hari Ini', $hariIni)
        ->description('Inbound hari ini')
        ->icon('heroicon-o-calendar-days')
        ->color('info'),
        ];
    }
}