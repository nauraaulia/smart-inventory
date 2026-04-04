<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseRequest extends CreateRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $target = request()->input('data.request_target', 'project');

        if ($target !== 'project') {
            $data['project_id'] = null;
        }

        $data['requested_by'] = auth()->id();
        $data['status'] = 'pending_manager';
        $data['submitted_at'] = now();
        $data['draft_approved_by'] = null;
        $data['draft_approved_at'] = null;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
