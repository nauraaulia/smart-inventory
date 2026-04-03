<?php

namespace App\Filament\Resources\ProjectTemplates\Pages;

use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProjectTemplate extends EditRecord
{
    // FIX PAKE PROJECT TEMPLATE RESOURCE
    protected static string $resource = ProjectTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}