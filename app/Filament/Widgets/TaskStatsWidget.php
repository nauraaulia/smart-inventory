<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TaskStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $baseUrl = route('filament.admin.resources.tasks.index');

        $aiPending = (int) TaskResource::getEloquentQuery()
            ->where('type', 'like', 'ai_%')
            ->whereNotIn('type', ['ai_inbound_allocation_suggestion'])
            ->where('status', 'pending')
            ->count();

        $selesaiHariIni = (int) TaskResource::getEloquentQuery()
            ->where('status', 'done')
            ->whereDate('updated_at', today())
            ->count();

        $mingguIni = (int) TaskResource::getEloquentQuery()
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        $rataRata = TaskResource::getEloquentQuery()
            ->where('status', 'done')
            ->whereNotNull('updated_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 86400) as avg_days')
            ->value('avg_days');

        $rataRataLabel = $rataRata !== null
            ? round((float) $rataRata, 1) . ' hari'
            : '-';

        return [
            Stat::make('AI Pending', $aiPending)
                ->description('Task AI belum divalidasi')
                ->icon('heroicon-o-cpu-chip')
                ->color($aiPending > 0 ? 'warning' : 'success')
                ->url($baseUrl . '?activeTab=ai'),

            Stat::make('Selesai Hari Ini', $selesaiHariIni)
                ->description('Task done hari ini')
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Masuk Minggu Ini', $mingguIni)
                ->description('Task baru minggu ini')
                ->icon('heroicon-o-calendar')
                ->color('info'),

            Stat::make('Rata-rata Penyelesaian', $rataRataLabel)
                ->description('Dari buat sampai done')
                ->icon('heroicon-o-clock')
                ->color('primary'),
        ];
    }
}