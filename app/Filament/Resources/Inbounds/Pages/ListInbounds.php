<?php

namespace App\Filament\Resources\Inbounds\Pages;

use App\Filament\Resources\Inbounds\InboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Widgets\InboundStatsWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInbounds extends ListRecords
{
    protected static string $resource = InboundResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            InboundStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('new_inbound')
                ->label('Tambah Inbound')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn (): string => static::getResource()::getUrl('create', ['type' => 'Baru'])),

            Actions\Action::make('receive_from_po')
                ->label('Terima dari Nomor PO')
                ->color('info')
                ->icon('heroicon-o-document-text')
                ->url(fn (): string => PurchaseOrderResource::getUrl('receive')),

            Actions\Action::make('new_retur')
                ->label('Tambah Retur')
                ->color('warning')
                ->icon('heroicon-o-arrow-path')
                ->url(fn (): string => static::getResource()::getUrl('create', ['type' => 'Retur'])),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua Riwayat'),

            'ready_stock' => Tab::make('Stok Layak Pakai')
                ->modifyQueryUsing(fn (Builder $query) =>
                    $query->where('jenis_inbound', 'Baru')
                        ->orWhere(fn ($q) => $q->where('jenis_inbound', 'Retur')->where('kondisi', 'Bagus'))
                )
                ->icon('heroicon-m-check-badge'),

            'deadstock' => Tab::make('Deadstock / Rusak')
                ->modifyQueryUsing(fn (Builder $query) =>
                    $query->whereIn('kondisi', ['Cacat Ringan', 'Cacat Berat'])
                )
                ->icon('heroicon-m-trash'),
        ];
    }
}