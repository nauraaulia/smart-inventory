<?php

namespace App\Filament\Widgets;

use App\Models\ProjectTemplate;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProjectTemplateStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $total = ProjectTemplate::count();
        $aktif = ProjectTemplate::where('is_active', true)->count();
        $nonAktif = ProjectTemplate::where('is_active', false)->count();

        return [
            Stat::make('Total Template', $total)
                ->description('Semua project template')
                ->icon('heroicon-o-document-duplicate')
                ->color('primary'),

            Stat::make('Template Aktif', $aktif)
                ->description('Siap digunakan')
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Template Non-aktif', $nonAktif)
                ->description('Tidak digunakan')
                ->icon('heroicon-o-x-circle')
                ->color($nonAktif > 0 ? 'danger' : 'gray'),
        ];
    }
}