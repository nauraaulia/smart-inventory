<?php

namespace App\Filament\Resources\ProjectTemplates\Pages;

use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use App\Filament\Widgets\ProjectTemplateStatsWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjectTemplates extends ListRecords
{
    protected static string $resource = ProjectTemplateResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ProjectTemplateStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        if (auth()->user()?->role === 'Manager Teknik') {
            return [
                Actions\CreateAction::make(),
            ];
        }

        return [];
    }
}