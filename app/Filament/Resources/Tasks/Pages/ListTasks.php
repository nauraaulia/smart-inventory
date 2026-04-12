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
            'ai' => Tab::make('Task AI')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'like', 'ai_%')->whereNotIn('type', ['ai_inbound_allocation_suggestion']))
                ->badge($this->countTasksByType('ai'))
                ->badgeColor('info'),

            'operasional' => Tab::make('Task Operasional')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', ['ai_inbound_allocation_suggestion']))
                ->badge($this->countTasksByType('operasional'))
                ->badgeColor('primary'),

            'all' => Tab::make('Semua Task')
                ->badge($this->countVisibleTasks()),

            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge($this->countVisibleTasks('pending'))
                ->badgeColor('warning'),

            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved'))
                ->badge($this->countVisibleTasks('approved'))
                ->badgeColor('info'),

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

    private function countTasksByType(string $type): int
    {
        $query = TaskResource::getEloquentQuery();

        if ($type === 'ai') {
            $query->where('type', 'like', 'ai_%')->whereNotIn('type', ['ai_inbound_allocation_suggestion']);
        } elseif ($type === 'operasional') {
            $query->whereIn('type', ['ai_inbound_allocation_suggestion']);
        }

        return (int) $query->count();
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