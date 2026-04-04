<?php

namespace App\Filament\Resources\ProjectTemplates\Pages;

use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjectTemplates extends ListRecords
{
    // FIX PAKE PROJECT TEMPLATE RESOURCE
    protected static string $resource = ProjectTemplateResource::class;

    protected function getHeaderActions(): array
{
    if (auth()->user()?->role === 'Manager Teknik') {
        return [
            // Kalau iya, kasih tombol "New"
            Actions\CreateAction::make(),
        ];
    }

    return [];
}
}