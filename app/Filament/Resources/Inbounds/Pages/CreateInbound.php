<?php

namespace App\Filament\Resources\Inbounds\Pages;

use App\Filament\Resources\Inbounds\InboundResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateInbound extends CreateRecord
{
    protected static string $resource = InboundResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->isReturMode() ? 'Create Retur' : parent::getTitle();
    }

    public function getBreadcrumb(): string
    {
        return $this->isReturMode() ? 'Create Retur' : parent::getBreadcrumb();
    }

    // TAMBAHKAN KODE INI BIAR LANGSUNG BALIK KE TABEL
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private function isReturMode(): bool
    {
        return request('type') === 'Retur' || (($this->data['jenis_inbound'] ?? null) === 'Retur');
    }
}
