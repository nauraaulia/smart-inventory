<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    protected ?string $pollingInterval = '2s';

    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('Semua PR')
                ->badge($this->countVisiblePr()),

            'draft' => Tab::make('Draft')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft'))
                ->badge($this->countVisiblePr('draft'))
                ->badgeColor('gray'),

            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected'))
                ->badge($this->countVisiblePr('rejected'))
                ->badgeColor('danger'),

            'pending_manager' => Tab::make('Pending Manager')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending_manager'))
                ->badge($this->countVisiblePr('pending_manager'))
                ->badgeColor('warning'),

            'pending_finance' => Tab::make('Pending Finance')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending_finance'))
                ->badge($this->countVisiblePr('pending_finance'))
                ->badgeColor('warning'),

            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved'))
                ->badge($this->countVisiblePr('approved'))
                ->badgeColor('success'),

            'ordered' => Tab::make('Ordered')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'ordered'))
                ->badge($this->countVisiblePr('ordered'))
                ->badgeColor('info'),

            'received' => Tab::make('Received')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'received'))
                ->badge($this->countVisiblePr('received'))
                ->badgeColor('success'),
        ];

        if (in_array(auth()->user()?->role, ['Finance', 'Keuangan'], true)) {
            return array_intersect_key($tabs, array_flip([
                'all',
                'pending_finance',
                'approved',
                'ordered',
                'received',
            ]));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        if (in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)) {
            return [
                CreateAction::make(),
            ];
        }

        return [];
    }

    private function countVisiblePr(?string $status = null): int
    {
        $query = PurchaseRequestResource::getEloquentQuery();

        if ($status !== null) {
            $query->where('status', $status);
        }

        return (int) $query->count();
    }
}
