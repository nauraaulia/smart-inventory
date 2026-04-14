<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array

    {
        return [ ];
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterSave(): void
    {
        Notification::make()
            ->title('Gemini sedang memproses rekomendasi')
            ->body('Mohon tunggu sebentar, rekomendasi booking/PR akan diperbarui otomatis.')
            ->info()
            ->send();
    }
    
}
