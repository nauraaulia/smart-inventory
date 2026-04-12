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

            'perencanaan' => Tab::make('Perencanaan Kebutuhan')
                ->modifyQueryUsing(fn (Builder $query) => $query->categoryPerencanaan())
                ->badge(Project::query()->categoryPerencanaan()->count())
                ->badgeColor('gray'),

            'barang_lengkap' => Tab::make('Barang Lengkap')
                ->modifyQueryUsing(fn (Builder $query) => $query->categoryBarangLengkap())
                ->badge(Project::query()->categoryBarangLengkap()->count())
                ->badgeColor('info'),

            'siap_diambil' => Tab::make('Siap Diambil')
                ->modifyQueryUsing(fn (Builder $query) => $query->categorySiapDiambil())
                ->badge(Project::query()->categorySiapDiambil()->count())
                ->badgeColor('success'),

            'complete' => Tab::make('Complete')
                ->modifyQueryUsing(fn (Builder $query) => $query->categoryComplete())
                ->badge(Project::query()->categoryComplete()->count())
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
