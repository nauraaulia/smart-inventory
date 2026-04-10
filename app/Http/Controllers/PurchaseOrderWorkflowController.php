<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Outbounds\OutboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\Inventory\PurchaseOrderInboundService;
use App\Services\Inventory\PurchaseOrderService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderWorkflowController extends Controller
{
    public function review(Request $request): View|RedirectResponse
    {
        abort_unless($this->isManager(), 403);

        $ids = collect(explode(',', (string) $request->query('pr_ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $params = [];

        if (!empty($ids)) {
            $params['pr_ids'] = implode(',', $ids);
        }

        return redirect()->to(PurchaseOrderResource::getUrl('review', $params));
    }

    public function publish(Request $request, PurchaseOrderService $service): RedirectResponse
    {
        abort_unless($this->isManager(), 403);

        $validated = $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.supplier_id' => ['required', 'integer'],
            'groups.*.po_date' => ['required', 'date'],
            'groups.*.items' => ['required', 'array', 'min:1'],
            'groups.*.items.*.material_id' => ['required', 'integer'],
            'groups.*.items.*.qty_system' => ['required', 'numeric', 'gt:0'],
            'groups.*.items.*.unit_system' => ['required', 'string', 'max:50'],
            'groups.*.items.*.qty_order' => ['required', 'numeric', 'gt:0'],
            'groups.*.items.*.unit_order' => ['required', 'string', 'max:50'],
            'groups.*.items.*.pr_ids' => ['nullable', 'array'],
            'groups.*.items.*.project_ids' => ['nullable', 'array'],
            'groups.*.items.*.project_codes' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $result = $service->publish(
            groups: $validated['groups'],
            actor: auth()->user(),
            notes: $validated['notes'] ?? null,
        );

        $poIds = $result['purchase_order_ids'];

        if (count($poIds) === 1) {
            return redirect()->to(PurchaseOrderResource::getUrl('view', ['record' => $poIds[0]]))
                ->with('success', 'PO berhasil diterbitkan.');
        }

        return redirect()->to(PurchaseOrderResource::getUrl('index'))
            ->with('success', 'PO berhasil diterbitkan: ' . count($poIds) . ' dokumen.');
    }

    public function index(): View
    {
        abort_unless($this->canViewPo(), 403);

        $purchaseOrders = PurchaseOrder::query()
            ->with(['supplier', 'items'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('purchase-orders.index', [
            'purchaseOrders' => $purchaseOrders,
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        abort_unless($this->canViewPo(), 403);

        $purchaseOrder->load(['supplier', 'items.material']);

        return view('purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    public function print(PurchaseOrder $purchaseOrder)
    {
        abort_unless($this->canPrintPo(), 403);

        $purchaseOrder->load(['supplier', 'items.material']);

        $pdf = Pdf::loadView('purchase-orders.pdf', [
            'purchaseOrder' => $purchaseOrder,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream($purchaseOrder->po_number . '.pdf');
    }

    public function inboundLookup(Request $request): View
    {
        abort_unless($this->isWarehouseStaff(), 403);

        $poNumber = trim((string) $request->query('po_number'));
        $purchaseOrder = null;

        if ($poNumber !== '') {
            $purchaseOrder = PurchaseOrder::query()
                ->with(['supplier', 'items.material'])
                ->where('po_number', $poNumber)
                ->first();
        }

        return view('purchase-orders.inbound-lookup', [
            'poNumber' => $poNumber,
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    public function receive(PurchaseOrder $purchaseOrder, Request $request, PurchaseOrderInboundService $service): RedirectResponse
    {
        abort_unless($this->isWarehouseStaff(), 403);

        if ($purchaseOrder->status !== 'waiting_inbound') {
            throw ValidationException::withMessages([
                'po_number' => 'PO ini tidak bisa diproses karena statusnya bukan waiting inbound.',
            ]);
        }

        $purchaseOrder->load('items');

        $validated = $request->validate([
            'receiver_name' => ['nullable', 'string', 'max:255'],
            'shelf_location_id' => ['required', 'integer', 'exists:shelf_locations,id'],
            'received_quantities' => ['nullable', 'array'],
            'received_quantities.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $receiverName = trim((string) $request->input('receiver_name', auth()->user()?->name));
        $receivedQuantities = $validated['received_quantities'] ?? [];

        $itemsById = $purchaseOrder->items->keyBy('id');

        foreach ($receivedQuantities as $itemId => $qty) {
            $normalizedItemId = (int) $itemId;

            if (!$itemsById->has($normalizedItemId)) {
                throw ValidationException::withMessages([
                    "received_quantities.{$itemId}" => 'Item PO tidak valid.',
                ]);
            }

            $item = $itemsById->get($normalizedItemId);
            $remaining = max(0, (float) $item->qty_order - (float) ($item->qty_received ?? 0));

            if ((float) $qty > $remaining) {
                throw ValidationException::withMessages([
                    "received_quantities.{$itemId}" => 'Qty diterima melebihi sisa pesanan untuk item ini.',
                ]);
            }
        }

        $created = $service->receive(
            purchaseOrder: $purchaseOrder,
            actor: auth()->user(),
            receiverName: $receiverName,
            shelfLocationId: (int) $validated['shelf_location_id'],
            receivedQuantities: $receivedQuantities,
        );

        if ($created <= 0) {
            return back()->with('error', 'PO tidak dalam status waiting inbound atau tidak ada item yang bisa diproses.');
        }

        return redirect()->to(OutboundResource::getUrl('index'))
            ->with('success', "PO {$purchaseOrder->po_number} berhasil diterima. Inbound dibuat: {$created} item.");
    }

    private function isManager(): bool
    {
        return in_array(auth()->user()?->role, ['Manager Operasional', 'Manajer Operasional'], true);
    }

    private function isWarehouseStaff(): bool
    {
        return in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang', 'Administrator IT'], true);
    }

    private function canViewPo(): bool
    {
        return $this->isManager()
            || $this->isWarehouseStaff()
            || in_array(auth()->user()?->role, ['Finance', 'Keuangan'], true);
    }

    private function canPrintPo(): bool
    {
        return $this->isManager();
    }
}
