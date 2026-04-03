<?php

namespace App\Filament\Resources\ProjectTemplates\Pages;

use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectTemplate extends CreateRecord
{
    // FIX PAKE PROJECT TEMPLATE RESOURCE
    protected static string $resource = ProjectTemplateResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}