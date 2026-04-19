<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProjectStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $baseUrl = route('filament.admin.resources.projects.index');
        $pendingUrl = $baseUrl . '?' . http_build_query([
            'status_scope' => 'Pending',
            'filters' => [
                'status' => ['value' => 'Pending'],
            ],
        ]);
        $completedUrl = $baseUrl . '?' . http_build_query([
            'status_scope' => 'Completed',
            'filters' => [
                'status' => ['value' => 'Completed'],
            ],
        ]);

        return [
            Stat::make('Total Proyek', Project::count())
                ->description('Semua proyek terdaftar')
                ->icon('heroicon-o-folder')
                ->color('primary')
                ->url($baseUrl),

            Stat::make('Proyek Pending', Project::where('status', 'Pending')->count())
                ->description('Menunggu eksekusi')
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->url($pendingUrl),

            Stat::make('Proyek Selesai', Project::where('status', 'Completed')->count())
                ->description('Berhasil diselesaikan')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->url($completedUrl),
        ];
    }
}