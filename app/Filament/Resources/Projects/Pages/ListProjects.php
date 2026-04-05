<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected ?string $pollingInterval = '2s';

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua Proyek')
                ->badge(Project::query()->count()),

            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'Pending'))
                ->badge(Project::query()->where('status', 'Pending')->count())
                ->badgeColor('gray'),

            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'Approved'))
                ->badge(Project::query()->where('status', 'Approved')->count())
                ->badgeColor('info'),

            'processing' => Tab::make('Processing')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'Processing'))
                ->badge(Project::query()->where('status', 'Processing')->count())
                ->badgeColor('warning'),

            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'Completed'))
                ->badge(Project::query()->where('status', 'Completed')->count())
                ->badgeColor('success'),
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
