<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        if (in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)) {
            return [
                CreateAction::make(),
            ];
        }

        return [];
    }
}
