<?php

namespace App\Filament\Widgets;

use App\Models\Outbound;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OutboundStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalProyekAktif = Outbound::query()
            ->whereIn('status', ['pending', 'ready'])
            ->distinct('project_id')
            ->count('project_id');

        $keluarHariIni = Outbound::query()
            ->whereDate('updated_at', today())
            ->where('status', 'taken')
            ->count();

        $totalItemKeluar = Outbound::query()
            ->where('status', 'taken')
            ->withCount('items')
            ->get()
            ->sum('items_count');

        $selesaiBulanIni = Outbound::query()
            ->where('status', 'taken')
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->count();

        return [
            Stat::make('Proyek Aktif', $totalProyekAktif)
                ->description('Sedang dalam proses outbound')
                ->icon('heroicon-o-folder-open')
                ->color('primary'),

            Stat::make('Diambil Hari Ini', $keluarHariIni)
                ->description('Outbound selesai hari ini')
                ->icon('heroicon-o-calendar-days')
                ->color('info'),

            Stat::make('Total Item Keluar', $totalItemKeluar)
                ->description('Keseluruhan item terkirim')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning'),

            Stat::make('Selesai Bulan Ini', $selesaiBulanIni)
                ->description('Outbound taken ' . now()->translatedFormat('F Y'))
                ->icon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }
}