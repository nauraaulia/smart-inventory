<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Widgets\PRStatsWidget;
use App\Models\PurchaseRequest;
use Filament\Actions\Action;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    protected ?string $pollingInterval = '2s';

    public array $selectedPrIds = [];

    protected function getHeaderWidgets(): array
    {
        return [
            PRStatsWidget::class,
        ];
    }

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

        if (in_array(auth()->user()?->role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional'], true)) {
            return array_intersect_key($tabs, array_flip([
                'all', 'pending_manager', 'pending_finance', 'approved', 'ordered', 'received',
            ]));
        }

        if (in_array(auth()->user()?->role, ['Finance', 'Keuangan'], true)) {
            return array_intersect_key($tabs, array_flip([
                'all', 'pending_finance', 'approved', 'ordered', 'received',
            ]));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        if (in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)) {
            return [CreateAction::make()];
        }

        if (in_array(auth()->user()?->role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional'], true)) {
            return [
                Action::make('generate_all_to_po')
                    ->label('Generate & Edit ke PO')
                    ->icon('heroicon-o-document-plus')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(function (): string {
                        $selectedCount = count($this->selectedPrIds);
                        return $selectedCount > 0 ? "Generate ({$selectedCount}) ke PO" : 'Generate Semua ke PO';
                    })
                    ->modalDescription(function (): string {
                        $selectedCount = count($this->selectedPrIds);
                        return $selectedCount > 0
                            ? 'Hanya PR yang dicentang (status Approved) yang diproses. Setelah ini kamu masuk ke halaman Review untuk edit qty/satuan dulu sebelum PO diterbitkan.'
                            : 'Semua PR Approved pada daftar saat ini diproses. Setelah ini kamu masuk ke halaman Review untuk edit qty/satuan dulu sebelum PO diterbitkan.';
                    })
                    ->visible(function (): bool {
                        $activeTab = $this->activeTab ?? request()->query('activeTab') ?? 'all';
                        return $activeTab === 'approved';
                    })
                    ->action(function () {
                        $selectedIds = collect($this->selectedPrIds)
                            ->map(fn ($id) => (int) $id)
                            ->filter(fn ($id) => $id > 0)
                            ->values();

                        if ($selectedIds->isNotEmpty()) {
                            $approvedIds = PurchaseRequest::query()
                                ->whereIn('id', $selectedIds->all())
                                ->where('status', 'approved')
                                ->pluck('id')
                                ->map(fn ($id) => (int) $id)
                                ->values();

                            if ($approvedIds->isEmpty()) {
                                Notification::make()
                                    ->title('Tidak ada PR approved terpilih')
                                    ->body('Checklist minimal 1 PR status Approved untuk dibuatkan PO.')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            $this->redirect(PurchaseOrderResource::getUrl('review', ['pr_ids' => $approvedIds->implode(',')]));
                            return;
                        }

                        $this->redirect(PurchaseOrderResource::getUrl('review'));
                    }),
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