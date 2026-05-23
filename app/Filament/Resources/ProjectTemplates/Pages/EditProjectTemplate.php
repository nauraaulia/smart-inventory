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
            Actions\DeleteAction::make()
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-trash')
                ->modalIconColor('danger')
                ->modalHeading('Delete project template')
                ->modalDescription('Are you sure you would like to do this?')
                ->modalCancelActionLabel('Cancel')
                ->modalSubmitActionLabel('Delete'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
