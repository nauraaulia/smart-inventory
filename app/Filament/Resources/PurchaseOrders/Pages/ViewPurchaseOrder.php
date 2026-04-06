<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print_pdf')
                ->label('Cetak PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => in_array(auth()->user()?->role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional', 'Finance', 'Keuangan'], true))
                ->url(fn (): string => route('po.print', ['purchaseOrder' => $this->record->id]))
                ->openUrlInNewTab(),
            Action::make('receive_po')
                ->label('Terima PO')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === 'waiting_inbound' && in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang'], true))
                ->url(fn (): string => PurchaseOrderResource::getUrl('receive', ['po_number' => $this->record->po_number])),
        ];
    }
}
