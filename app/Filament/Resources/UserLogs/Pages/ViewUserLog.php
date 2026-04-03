<?php

namespace App\Filament\Resources\UserLogs\Pages;

use App\Filament\Resources\UserLogs\UserLogResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewUserLog extends ViewRecord
{
    protected static string $resource = UserLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
     
        ];
    }
}
