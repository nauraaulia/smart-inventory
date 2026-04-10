<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\ShelfLocation;
use Filament\Resources\Pages\Page;

class ReceivePurchaseOrders extends Page
{
    protected static string $resource = PurchaseOrderResource::class;

    protected string $view = 'filament.resources.purchase-orders.pages.receive-purchase-orders';

    public ?PurchaseOrder $purchaseOrder = null;

    public string $poNumber = '';

    public string $selectedShelfLocationId = '';

    public string $receiverName = '';

    /**
     * @var array<int, string>
     */
    public array $shelfLocationOptions = [];

    public function mount(): void
    {
        abort_unless(in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang', 'Administrator IT'], true), 403);

        $this->shelfLocationOptions = ShelfLocation::query()
            ->orderBy('kode_lokasi')
            ->get(['id', 'kode_lokasi', 'nama_lokasi'])
            ->mapWithKeys(fn (ShelfLocation $shelf) => [
                $shelf->id => trim(($shelf->kode_lokasi ?: 'RAK-' . $shelf->id) . ' - ' . ($shelf->nama_lokasi ?: '-')),
            ])
            ->toArray();

        $this->poNumber = trim((string) request()->query('po_number', ''));

        if ($this->poNumber !== '') {
            $this->purchaseOrder = PurchaseOrder::query()
                ->with(['supplier', 'items.material', 'inbounds'])
                ->where('po_number', $this->poNumber)
                ->first();

            if ($this->purchaseOrder) {
                $lastInbound = $this->purchaseOrder->inbounds
                    ->sortByDesc('id')
                    ->first();

                $this->selectedShelfLocationId = (string) ($lastInbound?->shelf_location_id ?? '');
                $this->receiverName = (string) ($lastInbound?->penerima ?? (auth()->user()?->name ?? ''));
            }
        }
    }
}
