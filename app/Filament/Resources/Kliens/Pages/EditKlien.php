<?php

namespace App\Filament\Resources\Kliens\Pages;

use App\Filament\Resources\Kliens\KlienResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKlien extends EditRecord
{
    protected static string $resource = KlienResource::class;

    protected function getHeaderActions(): array
    {
        return [ ];
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
