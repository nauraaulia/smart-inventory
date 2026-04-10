<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected string $view = 'filament.resources.purchase-orders.pages.view-purchase-order';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var PurchaseOrder $po */
        $po = $this->getRecord();
        $po->load([
            'supplier:id,nama_supplier',
            'items.material:id,nama_material,satuan',
            'creator:id,name,id_custom',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getItemRows(): array
    {
        /** @var PurchaseOrder $record */
        $record = $this->getRecord();

        return $record->items
            ->map(function ($item): array {
                $ordered = (float) $item->qty_order;
                $received = (float) ($item->qty_received ?? 0);
                $remaining = max(0, $ordered - $received);
                $status = $remaining <= 0.00001 ? 'Completed' : ($received > 0 ? 'Partial' : 'Waiting');

                return [
                    'material_name' => (string) ($item->material?->nama_material ?? ('Material #' . $item->material_id)),
                    'qty_order' => $ordered,
                    'qty_received' => $received,
                    'qty_remaining' => round($remaining, 2),
                    'unit' => (string) ($item->unit_order ?: ($item->material?->satuan ?: 'Unit')),
                    'status' => $status,
                ];
            })
            ->values()
            ->all();
    }

    public function getProgressPercent(): int
    {
        /** @var PurchaseOrder $record */
        $record = $this->getRecord();

        $ordered = (float) $record->items->sum(fn ($item) => (float) $item->qty_order);
        $received = (float) $record->items->sum(fn ($item) => (float) ($item->qty_received ?? 0));

        if ($ordered <= 0) {
            return 0;
        }

        return (int) max(0, min(100, round(($received / $ordered) * 100)));
    }

    public function getCompletedItemSummary(): string
    {
        $rows = $this->getItemRows();
        $total = count($rows);
        $completed = collect($rows)->where('status', 'Completed')->count();

        return $completed . ' / ' . $total . ' item completed';
    }

    public function getPoStatusColor(): string
    {
        return match ((string) $this->record->progress_status) {
            'Completed' => 'success',
            'Partial' => 'info',
            'Waiting Inbound' => 'warning',
            default => 'gray',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print_pdf')
                ->label('Cetak PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => in_array(auth()->user()?->role, ['Manager Operasional', 'Manager Operasional', 'Manajer Operasional', 'Finance', 'Keuangan'], true))
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
