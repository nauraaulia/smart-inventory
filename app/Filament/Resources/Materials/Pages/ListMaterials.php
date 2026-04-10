<?php

namespace App\Filament\Resources\Materials\Pages;

use App\Filament\Resources\Materials\MaterialResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListMaterials extends ListRecords
{
    protected static string $resource = MaterialResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\Action::make('stock_by_location')
                ->label('Lokasi Stok')
                ->icon('heroicon-o-map')
                ->color('gray')
                ->url(MaterialResource::getUrl('stock-by-location'))
                ->visible(fn (): bool => StockByLocation::canAccess()),
        ];

        if (in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)) {
            $actions[] = Actions\CreateAction::make();
        }

        return $actions;
    }
}
