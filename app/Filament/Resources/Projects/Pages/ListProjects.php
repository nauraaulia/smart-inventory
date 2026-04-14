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
                ->badgeColor('warning'),

            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(function (Builder $q): void {
                    $q->where('status', 'Completed')
                        ->orWhereNotNull('completed_at');
                }))
                ->badge(Project::query()->where(function (Builder $q): void {
                    $q->where('status', 'Completed')
                        ->orWhereNotNull('completed_at');
                })->count())
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
