<?php

namespace App\Filament\Widgets;

use App\Models\MaterialPiece;
use App\Models\ShelfLocation;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ShelfLocationStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalRak = ShelfLocation::count();

        $rakTerisi = ShelfLocation::whereHas('materialPieces', function ($q) {
            $q->whereIn('status', ['available', 'booked']);
        })->count();

        $rakKosong = $totalRak - $rakTerisi;

        $totalItem = MaterialPiece::whereIn('status', ['available', 'booked'])
            ->whereNotNull('shelf_location_id')
            ->count();

        return [
            Stat::make('Total Rak', $totalRak)
                ->description('Semua lokasi rak')
                ->icon('heroicon-o-map-pin')
                ->color('primary'),

            Stat::make('Rak Terisi', $rakTerisi)
                ->description('Ada item di dalamnya')
                ->icon('heroicon-o-archive-box')
                ->color('success'),

            Stat::make('Rak Kosong', $rakKosong)
                ->description('Belum ada item')
                ->icon('heroicon-o-archive-box-x-mark')
                ->color($rakKosong > 0 ? 'warning' : 'success'),

            Stat::make('Total Item di Rak', $totalItem)
                ->description('Piece yang sudah di-assign rak')
                ->icon('heroicon-o-cube')
                ->color('info'),
        ];
    }
}