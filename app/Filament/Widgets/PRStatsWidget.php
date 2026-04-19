<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PRStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $baseUrl = route('filament.admin.resources.purchase-requests.index');
        $user = auth()->user();

        $draft = (int) PurchaseRequestResource::getEloquentQuery()->where('status', 'draft')->count();
        $pendingManager = (int) PurchaseRequestResource::getEloquentQuery()->where('status', 'pending_manager')->count();
        $pendingFinance = (int) PurchaseRequestResource::getEloquentQuery()->where('status', 'pending_finance')->count();
        $approved = (int) PurchaseRequestResource::getEloquentQuery()->where('status', 'approved')->count();

        // Sesuaikan stats berdasarkan role
        if (in_array($user?->role, ['Finance', 'Keuangan'], true)) {
            return [
                Stat::make('Pending Finance', $pendingFinance)
                    ->description('Menunggu approval Finance')
                    ->icon('heroicon-o-clock')
                    ->color('warning')
                    ->url($baseUrl . '?activeTab=pending_finance'),

                Stat::make('Approved', $approved)
                    ->description('Siap diproses')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->url($baseUrl . '?activeTab=approved'),
            ];
        }

        if (in_array($user?->role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional'], true)) {
            return [
                Stat::make('Pending Manager', $pendingManager)
                    ->description('Menunggu approval kamu')
                    ->icon('heroicon-o-clock')
                    ->color('warning')
                    ->url($baseUrl . '?activeTab=pending_manager'),

                Stat::make('Pending Finance', $pendingFinance)
                    ->description('Menunggu approval Finance')
                    ->icon('heroicon-o-banknotes')
                    ->color('info')
                    ->url($baseUrl . '?activeTab=pending_finance'),

                Stat::make('Approved', $approved)
                    ->description('Siap di-generate ke PO')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->url($baseUrl . '?activeTab=approved'),
            ];
        }

        // Default — Staff Gudang
        return [
            Stat::make('Draft', $draft)
                ->description('Belum disubmit')
                ->icon('heroicon-o-document')
                ->color('gray')
                ->url($baseUrl . '?activeTab=draft'),

            Stat::make('Pending Manager', $pendingManager)
                ->description('Menunggu approval')
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->url($baseUrl . '?activeTab=pending_manager'),

            Stat::make('Approved', $approved)
                ->description('Disetujui')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->url($baseUrl . '?activeTab=approved'),
        ];
    }
}