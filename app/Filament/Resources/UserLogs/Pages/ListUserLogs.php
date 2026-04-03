<?php

namespace App\Filament\Resources\UserLogs\Pages;

use App\Filament\Resources\UserLogs\UserLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUserLogs extends ListRecords
{
    protected static string $resource = UserLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
          
        ];
    }
}
