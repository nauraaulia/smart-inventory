<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected ?string $pollingInterval = '1s';

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua Task')
                ->badge($this->countVisibleTasks()),

            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge($this->countVisibleTasks('pending'))
                ->badgeColor('warning'),

            'done' => Tab::make('Done')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'done'))
                ->badge($this->countVisibleTasks('done'))
                ->badgeColor('success'),

            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected'))
                ->badge($this->countVisibleTasks('rejected'))
                ->badgeColor('danger'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(),
        ];
    }

    private function countVisibleTasks(?string $status = null): int
    {
        $query = TaskResource::getEloquentQuery();

        if ($status !== null) {
            $query->where('status', $status);
        }

        return (int) $query->count();
    }
}