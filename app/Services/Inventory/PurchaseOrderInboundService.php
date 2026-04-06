<?php

namespace App\Services\Inventory;

use App\Models\Inbound;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\UserLog;
use Illuminate\Support\Facades\DB;

class PurchaseOrderInboundService
{
    public function receive(PurchaseOrder $purchaseOrder, User $actor, ?string $receiverName = null, array $receivedQuantities = []): int
    {
        return DB::transaction(function () use ($purchaseOrder, $actor, $receiverName, $receivedQuantities) {
            $po = PurchaseOrder::query()->lockForUpdate()->with(['items.material'])->findOrFail($purchaseOrder->id);

            if ($po->status !== 'waiting_inbound') {
                return 0;
            }

            $created = 0;
            $allPrIds = [];

            foreach ($po->items as $item) {
                $material = $item->material;

                if (!$material) {
                    continue;
                }

                $qtyOrder = (float) $item->qty_order;
                $qtyReceived = (float) ($item->qty_received ?? 0);
                $qtyRemaining = max(0, $qtyOrder - $qtyReceived);

                if ($qtyRemaining <= 0) {
                    continue;
                }

                $requestedReceiveQtyOrder = array_key_exists((string) $item->id, $receivedQuantities)
                    ? (float) $receivedQuantities[(string) $item->id]
                    : (array_key_exists($item->id, $receivedQuantities) ? (float) $receivedQuantities[$item->id] : $qtyRemaining);

                $receiveQtyOrder = round(max(0, min($requestedReceiveQtyOrder, $qtyRemaining)), 2);

                if ($receiveQtyOrder <= 0) {
                    continue;
                }

                $conversionFactor = $qtyOrder > 0 ? ((float) $item->qty_system / $qtyOrder) : 1;
                $receiveQtySystem = round($receiveQtyOrder * $conversionFactor, 2);

                if ($receiveQtySystem <= 0) {
                    continue;
                }

                Inbound::create([
                    'material_id' => $item->material_id,
                    'quantity' => $receiveQtySystem,
                    'entry_date' => now()->toDateString(),
                    'supplier_id' => $po->supplier_id,
                    'shelf_location_id' => null,
                    'satuan' => (string) ($item->unit_system ?: ($material->satuan ?: 'Meter')),
                    'penerima' => $receiverName ?: (string) ($actor->name ?? 'System'),
                    'jenis_inbound' => 'Baru',
                    'is_opening_balance' => false,
                    'kondisi' => 'Bagus',
                    'keterangan' => 'Inbound parsial dari PO ' . $po->po_number . ' | Diterima: ' . $receiveQtyOrder . ' ' . $item->unit_order . ' (' . $receiveQtySystem . ' ' . $item->unit_system . ')',
                    'purchase_order_id' => $po->id,
                    'purchase_order_item_id' => $item->id,
                ]);

                $item->update([
                    'qty_received' => round($qtyReceived + $receiveQtyOrder, 2),
                ]);

                $created++;
                $allPrIds = array_merge($allPrIds, $item->pr_ids ?? []);
            }

            $allPrIds = collect($allPrIds)
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $po->refresh();
            $po->load('items');

            $isCompleted = $po->items->every(function ($item): bool {
                return (float) ($item->qty_received ?? 0) >= (float) $item->qty_order;
            });

            if ($isCompleted && !empty($allPrIds)) {
                PurchaseRequest::query()
                    ->whereIn('id', $allPrIds)
                    ->where('status', 'ordered')
                    ->update(['status' => 'received']);
            }

            if ($isCompleted) {
                $po->update([
                    'status' => 'completed',
                    'received_at' => now(),
                ]);
            }

            UserLog::create([
                'user_id' => $actor->id,
                'action' => 'Create',
                'module' => 'Inbound',
                'description' => 'Menerima inbound dari PO ' . $po->po_number . ' dan membuat ' . $created . ' item inbound (partial/full).',
                'ip_address' => request()?->ip(),
            ]);

            return $created;
        });
    }
}
