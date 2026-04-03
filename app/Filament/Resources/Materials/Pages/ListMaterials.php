<?php

namespace App\Filament\Resources\Materials\Pages;

use App\Filament\Resources\Materials\MaterialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListMaterials extends ListRecords
{
    protected static string $resource = MaterialResource::class;

    protected function getHeaderActions(): array
{
    if (auth()->user()->role === 'Administrator IT') {
        return [
            // Kalau iya, kasih tombol "New"
            Actions\CreateAction::make(),
        ];
    }

    return [];
}
}