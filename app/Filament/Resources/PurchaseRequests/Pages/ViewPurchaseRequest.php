<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Models\UserLog;
use App\Services\AI\PurchaseRequestSubmissionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseRequest extends ViewRecord
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
            Action::make('approve_manager')
                ->label('Setujui dan Kirim ke Finance')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn () => self::canApproveByManager($this->getRecord()))
                ->action(function (): void {
                    /** @var PurchaseRequest $record */
                    $record = $this->getRecord();

                    $record->update([
                        'status' => 'pending_finance',
                        'approved_by_manager' => auth()->id(),
                        'approved_by_manager_at' => now(),
                    ]);

                    UserLog::create([
                        'user_id' => auth()->id(),
                        'action' => 'Approve',
                        'module' => 'Purchase Request',
                        'description' => "Manager approve PR ID {$record->id}.",
                        'ip_address' => request()?->ip(),
                    ]);

                    Notification::make()
                        ->title('PR disetujui Manager')
                        ->success()
                        ->send();

                    $this->refreshFormData([]);
                }),
            Action::make('approve_finance')
                ->label('Setujui (Finance)')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn () => self::canApproveByFinance($this->getRecord()))
                ->action(function (): void {
                    /** @var PurchaseRequest $record */
                    $record = $this->getRecord();

                    $record->update([
                        'status' => 'approved',
                        'approved_by_finance' => auth()->id(),
                        'approved_by_finance_at' => now(),
                    ]);

                    Task::create([
                        'for_role' => 'Staff Administrasi Gudang',
                        'type' => 'pr_finance_approved_followup',
                        'title' => 'PR Disetujui Finance - Tindak Lanjut Gudang',
                        'message' => 'PR sudah disetujui Finance. Mohon lanjutkan proses pengadaan/penerimaan sesuai alur gudang.',
                        'payload' => [
                            'pr_ids' => [$record->id],
                            'project_id' => $record->project_id,
                            'project_code' => $record->project?->kode_proyek ?? ('#' . $record->project_id),
                            'source' => 'purchase_requests_view_approve_finance',
                        ],
                        'status' => 'pending',
                        'related_id' => $record->project_id,
                        'related_type' => 'project',
                    ]);

                    UserLog::create([
                        'user_id' => auth()->id(),
                        'action' => 'Approve',
                        'module' => 'Purchase Request',
                        'description' => "Finance approve PR ID {$record->id}.",
                        'ip_address' => request()?->ip(),
                    ]);

                    Notification::make()
                        ->title('PR disetujui Finance')
                        ->success()
                        ->send();

                    $this->refreshFormData([]);
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

                    UserLog::create([
                        'user_id' => auth()->id(),
                        'action' => 'Reject',
                        'module' => 'Draft PR',
                        'description' => "Reject draft PR ID {$record->id} untuk project " . ($record->project?->kode_proyek ?? ('#' . $record->project_id)) . ". Alasan: " . $data['rejection_reason'],
                        'ip_address' => request()?->ip(),
                    ]);

                    Notification::make()
                        ->title('Draft PR direject')
                        ->success()
                        ->send();
                }),
            Action::make('cancel_submission')
                ->label('Batalkan Pengajuan')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Batalkan Pengajuan ke Manager')
                ->modalDescription('PR akan dikembalikan ke status Draft selama Manager Operasional belum melakukan approval.')
                ->visible(fn () => PurchaseRequestResource::canCancelSubmission($this->getRecord()))
                ->action(function (): void {
                    /** @var PurchaseRequest $record */
                    $record = $this->getRecord();

                    $result = (new PurchaseRequestSubmissionService())
                        ->cancelSubmittedPr($record, auth()->user());

                    Notification::make()
                        ->title($result['cancelled'] ? 'Pengajuan PR dibatalkan' : 'Pengajuan tidak dapat dibatalkan')
                        ->body($result['cancelled']
                            ? "PR dikembalikan ke draft untuk project {$result['project_code']}."
                            : 'Status PR tidak lagi menunggu approval manager.')
                        ->success($result['cancelled'])
                        ->warning(!$result['cancelled'])
                        ->send();

                    if ($result['cancelled']) {
                        $this->redirect($this->getResource()::getUrl('index'));
                    }
                }),
            Action::make('cancel_submission_to_finance')
                ->label('Batalkan Pengajuan ke Finance')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Batalkan Pengajuan ke Finance')
                ->modalDescription('PR akan dikembalikan ke status Menunggu ACC Manager selama Finance belum melakukan approval.')
                ->visible(fn () => PurchaseRequestResource::canCancelSubmissionToFinanceByManager($this->getRecord()))
                ->action(function (): void {
                    /** @var PurchaseRequest $record */
                    $record = $this->getRecord();

                    $result = (new PurchaseRequestSubmissionService())
                        ->cancelSubmissionToFinanceByManager($record, auth()->user());

                    Notification::make()
                        ->title($result['cancelled'] ? 'Pengajuan ke Finance dibatalkan' : 'Pengajuan tidak dapat dibatalkan')
                        ->body($result['cancelled']
                            ? "PR dikembalikan ke status pending manager untuk project {$result['project_code']}."
                            : 'Status PR tidak lagi menunggu approval finance.')
                        ->success($result['cancelled'])
                        ->warning(!$result['cancelled'])
                        ->send();

                    if ($result['cancelled']) {
                        $this->refreshFormData([]);
                    }
                }),
        ];
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

        return in_array($user->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)
            && (int) $record->requested_by === (int) $user->id;
    }

    private static function canApproveByManager(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        return in_array($user->role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional'], true)
            && $record->status === 'pending_manager';
    }

    private static function canApproveByFinance(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        return in_array($user->role, ['Finance', 'Keuangan'], true)
            && $record->status === 'pending_finance';
    }
}
