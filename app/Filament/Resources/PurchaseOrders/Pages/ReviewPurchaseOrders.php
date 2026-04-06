<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Services\Inventory\PurchaseOrderService;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

class ReviewPurchaseOrders extends Page
{
    protected static string $resource = PurchaseOrderResource::class;

    protected string $view = 'filament.resources.purchase-orders.pages.review-purchase-orders';

    public Collection $groups;

    public array $sourcePrIds = [];

    public function mount(): void
    {
        abort_unless(in_array(auth()->user()?->role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional'], true), 403);

        $this->sourcePrIds = collect(explode(',', (string) request()->query('pr_ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->groups = app(PurchaseOrderService::class)->buildDraftGroups($this->sourcePrIds);
    }
}
