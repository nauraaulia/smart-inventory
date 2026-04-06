<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receive_by_po')
                ->label('Terima dari Nomor PO')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('danger')
                ->visible(fn (): bool => in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang'], true))
                ->url(fn (): string => PurchaseOrderResource::getUrl('receive')),
        ];
    }
}
