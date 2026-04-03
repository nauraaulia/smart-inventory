<?php

namespace App\Filament\Resources\Kliens\Pages;

use App\Filament\Resources\Kliens\KlienResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKliens extends ListRecords
{
    protected static string $resource = KlienResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
