<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Gemini sedang memproses rekomendasi')
            ->body('Mohon tunggu sebentar, rekomendasi booking/PR akan muncul otomatis.')
            ->info()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function canCreateAnother(): bool
    {
        return false;
    }

}
