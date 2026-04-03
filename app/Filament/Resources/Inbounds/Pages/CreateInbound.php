<?php

namespace App\Filament\Resources\Inbounds\Pages;

use App\Filament\Resources\Inbounds\InboundResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInbound extends CreateRecord
{
    protected static string $resource = InboundResource::class;

    // TAMBAHKAN KODE INI BIAR LANGSUNG BALIK KE TABEL
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
