<?php

namespace App\Filament\Widgets;

use App\Models\Inbound;
use App\Models\MaterialPiece;
use App\Models\PurchaseRequest;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StaffStatsOverview extends StatsOverviewWidget
{
    protected int | string | array $columnSpan = 'full';

    protected ?string $heading = 'Ringkasan Gudang';

    public static function canView(): bool
    {
        return auth()->user()?->role === 'Staff Administrasi Gudang';
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $totalAvailable = (float) MaterialPiece::query()
            ->where('status', 'available')
            ->sum('panjang');

        $pendingAiTasks = (int) Task::query()
            ->where('type', 'ai_booking_suggestion')
            ->where('status', 'pending')
            ->where('for_role', 'Staff Administrasi Gudang')
            ->count();

        $pendingPr = (int) PurchaseRequest::query()
            ->where('status', 'draft')
            ->count();

        $inboundThisWeek = (int) Inbound::query()
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();

        return [
            Stat::make('Total Material Available', number_format($totalAvailable, 2) . ' m')
                ->description('Total panjang piece status available')
                ->color('success'),
            Stat::make('Tasks AI Pending', (string) $pendingAiTasks)
                ->description('Task ai_booking_suggestion pending')
                ->color($pendingAiTasks > 0 ? 'warning' : 'success'),
            Stat::make('PR Draft/Pending', (string) $pendingPr)
                ->description('Purchase request status draft')
                ->color($pendingPr > 0 ? 'warning' : 'gray'),
            Stat::make('Inbound Minggu Ini', (string) $inboundThisWeek)
                ->description('Inbound dibuat sejak awal minggu')
                ->color('info'),
        ];
    }
}
