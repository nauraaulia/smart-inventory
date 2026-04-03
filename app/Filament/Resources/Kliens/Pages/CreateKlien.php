<?php

namespace App\Filament\Resources\Kliens\Pages;

use App\Filament\Resources\Kliens\KlienResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKlien extends CreateRecord
{
    protected static string $resource = KlienResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $lastId = \App\Models\Klien::withTrashed()->max('id') ?? 0;
        $nextId = $lastId + 1;
        
        $data['kode_klien'] = 'KLN-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);
        
        return $data;
    }
}
