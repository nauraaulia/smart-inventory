<?php

namespace App\Filament\Resources\AgingMaterials\Pages;

use App\Filament\Resources\AgingMaterials\AgingMaterialResource;
use App\Filament\Widgets\AgingMaterialStatsWidget;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAgingMaterials extends ListRecords
{
    protected static string $resource = AgingMaterialResource::class;

    protected ?string $pollingInterval = '2s';

    protected function getHeaderWidgets(): array
    {
        return [
            AgingMaterialStatsWidget::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            'approved' => Tab::make('Siap Diproses')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', 'pending')
                    ->whereRaw("payload->>'workflow_state' = ?", ['approved_for_aging'])),
            'done' => Tab::make('Done')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'done')),
            'all' => Tab::make('Semua'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
