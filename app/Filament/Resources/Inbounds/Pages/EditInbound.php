<?php

namespace App\Filament\Resources\Inbounds\Pages;

use App\Filament\Resources\Inbounds\InboundResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInbound extends EditRecord
{
    protected static string $resource = InboundResource::class;

    // TAMBAHKAN KODE INI JUGA DI SINI
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}