<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $lastId = \App\Models\User::withTrashed()->max('id') ?? 0;
        
        $nextId = $lastId + 1;
        
        $data['id_custom'] = 'JAU-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);

        return $data;
    }
}
