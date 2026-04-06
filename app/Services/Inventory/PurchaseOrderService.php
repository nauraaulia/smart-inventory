<?php

namespace App\Services\Inventory;

use App\Models\Inbound;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function buildDraftGroups(array $prIds = []): Collection
    {
        $ids = collect($prIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $query = PurchaseRequest::query()
            ->with(['material.defaultSupplier', 'project'])
            ->where('status', 'approved');

        if ($ids->isNotEmpty()) {
            $query->whereIn('id', $ids->all());
        }

        $requests = $query->get();

        $requestsWithSupplier = $requests
            ->map(function (PurchaseRequest $pr): array {
                $supplierId = $this->resolveSupplierIdForPr($pr);

                return [
                    'pr' => $pr,
                    'supplier_id' => $supplierId,
                ];
            })
            ->filter(fn (array $row) => (int) $row['supplier_id'] > 0)
            ->values();

        return $requestsWithSupplier
            ->groupBy(fn (array $row) => (int) $row['supplier_id'])
            ->map(function (Collection $supplierRequests, int $supplierId) {
                $supplier = Supplier::query()->find($supplierId);

                $items = $supplierRequests
                    ->map(fn (array $row) => $row['pr'])
                    ->groupBy('material_id')
                    ->map(function (Collection $materialRequests, int|string $materialId) {
                        $material = $materialRequests->first()?->material;
                        $qtySystem = (float) $materialRequests->sum(fn (PurchaseRequest $pr) => (float) $pr->quantity);
                        $defaultRoll = (float) ($material?->default_roll_length ?? 0);

                        $defaultQtyOrder = $defaultRoll > 0
                            ? round($qtySystem / $defaultRoll, 2)
                            : round($qtySystem, 2);

                        $defaultUnitOrder = $defaultRoll > 0 ? 'Roll' : ((string) ($material?->satuan ?: 'Unit'));

                        return [
                            'material_id' => (int) $materialId,
                            'material_name' => (string) ($material?->nama_material ?? '-'),
                            'unit_system' => (string) ($material?->satuan ?: 'Meter'),
                            'qty_system' => round($qtySystem, 2),
                            'qty_order_default' => $defaultQtyOrder,
                            'unit_order_default' => $defaultUnitOrder,
                            'pr_ids' => $materialRequests->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                            'project_ids' => $materialRequests->pluck('project_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
                            'project_codes' => $materialRequests
                                ->map(fn (PurchaseRequest $pr) => (string) ($pr->project?->kode_proyek ?: ('#' . $pr->project_id)))
                                ->unique()
                                ->values()
                                ->all(),
                        ];
                    })
                    ->values();

                return [
                    'supplier_id' => (int) $supplierId,
                    'supplier_name' => (string) ($supplier?->nama_supplier ?? 'Supplier #' . $supplierId),
                    'po_date' => now()->toDateString(),
                    'items' => $items->all(),
                ];
            })
            ->values();
    }

    private function resolveSupplierIdForPr(PurchaseRequest $pr): int
    {
        $defaultSupplierId = (int) ($pr->material?->default_supplier_id ?? 0);

        if ($defaultSupplierId > 0) {
            return $defaultSupplierId;
        }

        return (int) (Inbound::query()
            ->where('material_id', $pr->material_id)
            ->whereNotNull('supplier_id')
            ->orderByDesc('id')
            ->value('supplier_id') ?? 0);
    }

    public function publish(array $groups, User $actor, ?string $notes = null): array
    {
        return DB::transaction(function () use ($groups, $actor, $notes) {
            $createdPoIds = [];
            $allPrIds = [];

            foreach ($groups as $group) {
                $supplierId = (int) ($group['supplier_id'] ?? 0);
                $items = collect($group['items'] ?? []);

                if ($supplierId <= 0 || $items->isEmpty()) {
                    continue;
                }

                $po = PurchaseOrder::create([
                    'po_number' => $this->generatePoNumber(),
                    'supplier_id' => $supplierId,
                    'po_date' => $group['po_date'] ?? now()->toDateString(),
                    'status' => 'waiting_inbound',
                    'notes' => $notes,
                    'created_by' => $actor->id,
                    'published_at' => now(),
                ]);

                foreach ($items as $item) {
                    $prIds = collect($item['pr_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

                    if (empty($prIds)) {
                        continue;
                    }

                    PurchaseOrderItem::create([
                        'purchase_order_id' => $po->id,
                        'material_id' => (int) $item['material_id'],
                        'qty_system' => (float) $item['qty_system'],
                        'unit_system' => (string) ($item['unit_system'] ?? 'Meter'),
                        'qty_order' => (float) $item['qty_order'],
                        'unit_order' => (string) ($item['unit_order'] ?? 'Unit'),
                        'pr_ids' => $prIds,
                        'project_ids' => collect($item['project_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all(),
                        'project_codes' => collect($item['project_codes'] ?? [])->map(fn ($code) => (string) $code)->filter()->unique()->values()->all(),
                    ]);

                    $allPrIds = array_merge($allPrIds, $prIds);
                }

                $createdPoIds[] = $po->id;
            }

            $allPrIds = collect($allPrIds)->unique()->values()->all();

            if (!empty($allPrIds)) {
                PurchaseRequest::query()
                    ->whereIn('id', $allPrIds)
                    ->where('status', 'approved')
                    ->update(['status' => 'ordered']);
            }

            UserLog::create([
                'user_id' => $actor->id,
                'action' => 'Create',
                'module' => 'Purchase Order',
                'description' => 'Menerbitkan PO dari PR approved. Jumlah PO: ' . count($createdPoIds),
                'ip_address' => request()?->ip(),
            ]);

            return [
                'purchase_order_ids' => $createdPoIds,
                'purchase_request_ids' => $allPrIds,
            ];
        });
    }

    private function generatePoNumber(): string
    {
        $monthRoman = $this->toRoman((int) now()->format('n'));
        $year = now()->format('Y');
        $prefix = "PO/SI/{$monthRoman}/{$year}";
        $last = PurchaseOrder::query()
            ->where('po_number', 'like', $prefix . '/%')
            ->orderByDesc('id')
            ->value('po_number');

        $next = 1;

        if (is_string($last) && preg_match('/^(?:PO\/SI\/[IVXLCDM]+\/\d{4})\/(\d{4})$/', $last, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return sprintf('%s/%04d', $prefix, $next);
    }

    private function toRoman(int $number): string
    {
        $map = [
            1000 => 'M',
            900 => 'CM',
            500 => 'D',
            400 => 'CD',
            100 => 'C',
            90 => 'XC',
            50 => 'L',
            40 => 'XL',
            10 => 'X',
            9 => 'IX',
            5 => 'V',
            4 => 'IV',
            1 => 'I',
        ];

        $result = '';

        foreach ($map as $value => $roman) {
            while ($number >= $value) {
                $result .= $roman;
                $number -= $value;
            }
        }

        return $result;
    }
}
