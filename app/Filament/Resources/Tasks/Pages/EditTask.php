<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Services\AI\TaskApprovalService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Task $record */
        $newStatus = $data['status'] ?? $record->status;
        $oldStatus = $record->status;
        $reasonInput = trim((string) ($data['rejection_reason'] ?? ''));

        // Field virtual (bukan kolom DB), jangan ikut ke parent update.
        unset($data['rejection_reason']);

        if ($oldStatus === $newStatus) {
            return $record->refresh();
        }

        $service = new TaskApprovalService();

        if ($newStatus === 'done') {
            $service->approve($record, auth()->user());
            return $record->refresh();
        }

        if ($newStatus === 'rejected') {
            if ($reasonInput === '') {
                throw ValidationException::withMessages([
                    'rejection_reason' => 'Alasan reject wajib diisi.',
                ]);
            }

            $reason = $reasonInput;
            $service->reject($record, auth()->user(), $reason);
            return $record->refresh();
        }

        return parent::handleRecordUpdate($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
