<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AgingMaterialStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $baseUrl = route('filament.admin.resources.aging-materials.index');

        $doneTasks = Task::query()
            ->where('type', 'ai_deadstock_recommendation')
            ->where('status', 'done');

        $decisionRows = $doneTasks
            ->get(['payload']);

        $normalizedRows = $decisionRows
            ->flatMap(fn (Task $task): array => (array) data_get($task->payload, 'deadstock_items', []))
            ->filter(fn ($row): bool => is_array($row) && (int) ($row['material_id'] ?? 0) > 0)
            ->values();

        $qtyKeep = (int) $normalizedRows
            ->filter(fn (array $row): bool => (float) ($row['qty_kept'] ?? 0) > 0.00001)
            ->count();

        $qtyJualMurah = (int) $normalizedRows
            ->filter(fn (array $row): bool => (float) ($row['qty_jual_murah'] ?? 0) > 0.00001)
            ->count();

        $qtyBuang = (int) $normalizedRows
            ->filter(fn (array $row): bool => (float) ($row['qty_buang'] ?? 0) > 0.00001)
            ->count();

        $qtyReturn = (int) $normalizedRows
            ->filter(fn (array $row): bool => (float) ($row['qty_return_distributor'] ?? 0) > 0.00001)
            ->count();

        return [
            Stat::make('Dikembalikan ke Gudang', $qtyKeep)
                ->description('Material tetap disimpan dan dimonitor')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->url($baseUrl.'?activeTab=done'),

            Stat::make('Dijual Murah', $qtyJualMurah)
                ->description('Material masuk keputusan clearance')
                ->icon('heroicon-o-tag')
                ->color('warning')
                ->url($baseUrl.'?activeTab=done'),

            Stat::make('Dibuang', $qtyBuang)
                ->description('Material dilepas sebagai scrap')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->url($baseUrl.'?activeTab=done'),

            Stat::make('Dikembalikan ke Supplier', $qtyReturn)
                ->description('Material diputuskan untuk retur')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success')
                ->url($baseUrl.'?activeTab=done'),
        ];
    }
}
