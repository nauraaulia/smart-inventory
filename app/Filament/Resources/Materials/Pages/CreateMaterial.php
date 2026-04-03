<?php

namespace App\Filament\Resources\Materials\Pages;

use App\Filament\Resources\Materials\MaterialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaterial extends CreateRecord
{
    protected static string $resource = MaterialResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
    
        $lastId = \App\Models\Material::withTrashed()->max('id') ?? 0;
        $nextId = $lastId + 1;

        $data['kode_material'] = 'MAT-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);

        return $data;
    }

}
