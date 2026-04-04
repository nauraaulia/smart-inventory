<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Services\AI\PurchaseRequestSubmissionService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseRequest extends EditRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve_and_submit')
                ->label('Setujui & Kirim ke Manager')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn () => self::canApproveDraft($this->getRecord()))
                ->action(function (): void {
                    /** @var PurchaseRequest $record */
                    $record = $this->getRecord();

                    $record->update([
                        'draft_approved_by' => auth()->id(),
                        'draft_approved_at' => now(),
                    ]);

                    $result = (new PurchaseRequestSubmissionService())
                        ->submitDraftsForProject((int) $record->project_id, auth()->user());

                    Notification::make()
                        ->title('Draft PR disetujui dan dikirim ke Manager')
                        ->body("{$result['submitted_count']} PR berhasil disubmit untuk project {$result['project_code']}.")
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
            Action::make('reject_draft')
                ->label('Reject Draft')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->form([
                    Textarea::make('rejection_reason')
                        ->label('Alasan Reject')
                        ->rows(3)
                        ->required(),
                ])
                ->visible(fn () => self::canRejectDraft($this->getRecord()))
                ->action(function (array $data): void {
                    /** @var PurchaseRequest $record */
                    $record = $this->getRecord();

                    $record->update([
                        'status' => 'rejected',
                        'rejected_at' => now(),
                        'rejection_reason' => $data['rejection_reason'],
                        'draft_approved_by' => null,
                        'draft_approved_at' => null,
                    ]);

                    Notification::make()
                        ->title('Draft PR direject')
                        ->success()
                        ->send();
                }),
            ViewAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private static function canApproveDraft(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if (!in_array($record->status, ['draft', 'rejected'], true)) {
            return false;
        }

        if (filled($record->draft_approved_at)) {
            return false;
        }

        if ($user->role === 'Administrator IT') {
            return true;
        }

        return in_array($user->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)
            && (int) $record->requested_by === (int) $user->id;
    }

    private static function canRejectDraft(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if (!in_array($record->status, ['draft', 'rejected'], true)) {
            return false;
        }

        if ($user->role === 'Administrator IT') {
            return true;
        }

        return in_array($user->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)
            && (int) $record->requested_by === (int) $user->id;
    }
}
