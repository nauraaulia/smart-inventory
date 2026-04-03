<?php

namespace App\Filament\Resources\ShelfLocations\Pages;

use App\Filament\Resources\ShelfLocations\ShelfLocationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShelfLocation extends EditRecord
{
    protected static string $resource = ShelfLocationResource::class;

    protected function getHeaderActions(): array
   {
        return [ ];
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}


