<?php

namespace App\Filament\Resources\Outbounds\Pages;

use App\Filament\Resources\Outbounds\OutboundResource;
use Filament\Resources\Pages\ListRecords;

class ListOutbounds extends ListRecords
{
    protected static string $resource = OutboundResource::class;

    protected ?string $pollingInterval = '2s';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
