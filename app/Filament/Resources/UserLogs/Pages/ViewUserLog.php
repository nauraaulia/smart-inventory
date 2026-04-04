<?php

namespace App\Filament\Resources\UserLogs\Pages;

use App\Filament\Resources\UserLogs\UserLogResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewUserLog extends ViewRecord
{
    protected static string $resource = UserLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label('Close')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->url(UserLogResource::getUrl('index')),
        ];
    }
}
