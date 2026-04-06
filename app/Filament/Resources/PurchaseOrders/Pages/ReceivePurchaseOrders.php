<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Resources\Pages\Page;

class ReceivePurchaseOrders extends Page
{
    protected static string $resource = PurchaseOrderResource::class;

    protected string $view = 'filament.resources.purchase-orders.pages.receive-purchase-orders';

    public ?PurchaseOrder $purchaseOrder = null;

    public string $poNumber = '';

    public function mount(): void
    {
        abort_unless(in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang'], true), 403);

        $this->poNumber = trim((string) request()->query('po_number', ''));

        if ($this->poNumber !== '') {
            $this->purchaseOrder = PurchaseOrder::query()
                ->with(['supplier', 'items.material'])
                ->where('po_number', $this->poNumber)
                ->first();
        }
    }
}
