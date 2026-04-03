<?php

namespace App\Filament\Resources\ShelfLocations\Pages;

use App\Filament\Resources\ShelfLocations\ShelfLocationResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\UserLog;

class CreateShelfLocation extends CreateRecord
{
    protected static string $resource = ShelfLocationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

}