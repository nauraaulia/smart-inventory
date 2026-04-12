<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    public function getTabs(): array
    {
        return [
            'proses' => Tab::make('PO Proses')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'waiting_inbound'))
                ->badge($this->countPoByStatus('waiting_inbound'))
                ->badgeColor('warning'),
            'selesai' => Tab::make('PO Selesai')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'completed'))
                ->badge($this->countPoByStatus('completed'))
                ->badgeColor('success'),
            'semua' => Tab::make('Semua PO')
                ->badge($this->countPoByStatus())
                ->badgeColor('gray'),
        ];
    }

    private function countPoByStatus(?string $status = null): int
    {
        $query = PurchaseOrderResource::getEloquentQuery();

        if ($status !== null) {
            $query->where('status', $status);
        }

        return (int) $query->count();
    }

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
