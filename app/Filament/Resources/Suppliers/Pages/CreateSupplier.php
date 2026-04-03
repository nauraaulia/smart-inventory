<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $lastId = \App\Models\Supplier::withTrashed()->max('id') ?? 0;
        $nextId = $lastId + 1;
        
        $data['kode_supplier'] = 'SPL-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);
        
        return $data;
    }
}
