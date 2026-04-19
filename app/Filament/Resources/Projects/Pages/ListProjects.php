<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Widgets\ProjectStatsWidget;
use App\Models\Project;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected ?string $pollingInterval = '2s';

    protected function getHeaderWidgets(): array
    {
        return [
            ProjectStatsWidget::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua Proyek'),
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'Pending')),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(function (Builder $q): void {
                    $q->where('status', 'Completed')
                        ->orWhereNotNull('completed_at');
                })),
        ];
    }

    protected function getHeaderActions(): array
    {
        if (auth()->user()?->role !== 'Manager Teknik') {
            return [];
        }

        return [
            CreateAction::make(),
        ];
    }
}