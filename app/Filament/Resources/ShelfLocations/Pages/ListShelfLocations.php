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
        if (in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)) {
            return [
                CreateAction::make(),
            ];
        }

        return [];
    }
}
