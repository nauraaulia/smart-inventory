<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Jobs\ProsesKebutuhanProyekJob;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function afterSave(): void
    {
        ProsesKebutuhanProyekJob::dispatchSync($this->record->id);
    }

    protected function getHeaderActions(): array

    {
        return [ ];
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
    
}
