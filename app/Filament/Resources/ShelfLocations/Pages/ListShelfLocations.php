<?php

namespace App\Filament\Resources\ShelfLocations\Pages;

use App\Filament\Resources\ShelfLocations\ShelfLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShelfLocations extends ListRecords
{
    protected static string $resource = ShelfLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
